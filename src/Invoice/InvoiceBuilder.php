<?php

namespace Meita\ZatcaEngine\Invoice;

use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Helpers\DateHelper;
use Meita\ZatcaEngine\Helpers\UuidHelper;
use Meita\ZatcaEngine\Validators\Phase2Validator;
use InvalidArgumentException;

final class InvoiceBuilder
{
    private string $type = 'standard';

    private string $invoiceNumber = '1';
    private string $invoiceCounter = '1'; // KSA-16 (ICV)

    private string $uuid;
    private string $issueDate;
    private string $issueTime;
    private ?string $supplyDate = null;

    private ?string $buyerName = null;
    private ?string $buyerVat  = null;
    private ?string $buyerId   = null;
    private array $buyerAddress = [];

    private array $items = [];

    private ?string $previousHash = null; // KSA-13

    public function __construct(private readonly Context $ctx)
    {
        $this->uuid      = UuidHelper::v4();
        $this->issueDate = DateHelper::today();
        $this->issueTime = DateHelper::nowTime();
    }

    /* ================= TYPE ================= */

    public function standard(): self
    {
        $this->type = 'standard';
        return $this;
    }

    public function simplified(): self
    {
        $this->type = 'simplified';
        return $this;
    }

    /* ================= IDS ================= */

    public function number(string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber;
        return $this;
    }

    /** Invoice Counter Value (KSA-16) */
    public function counter(string|int $counter): self
    {
        $this->invoiceCounter = (string)$counter;
        return $this;
    }

    /* ================= DATES ================= */

    public function issueAt(string $date, string $time): self
    {
        $this->issueDate = $date;
        $this->issueTime = $time;
        return $this;
    }

    /** Supply Date (KSA-5) – mandatory for standard */
    public function supplyDate(string $date): self
    {
        $this->supplyDate = $date;
        return $this;
    }

    /* ================= BUYER ================= */

    public function buyer(
        string $name,
        array $address,
        ?string $vat = null,
        ?string $buyerId = null
    ): self {
        $this->buyerName    = $name;
        $this->buyerVat     = $vat;
        $this->buyerId      = $buyerId;
        $this->buyerAddress = $address;
        return $this;
    }

    /* ================= ITEMS ================= */

    public function addItem(
        string $name,
        float $qty,
        float $unitPrice,
        ?float $vatRate = null,
        string $vatCategory = 'S',
        string $unitCode = 'EA'
    ): self {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Item quantity must be greater than zero.');
        }

        if ($unitPrice < 0) {
            throw new InvalidArgumentException('Item unit price cannot be negative.');
        }

        $this->items[] = [
            'name'         => $name,
            'qty'          => $qty,
            'price'        => $unitPrice,
            'vat_rate'     => $vatRate ?? $this->ctx->taxRate(),
            'vat_category' => $vatCategory,
            'unit_code'    => $unitCode,
        ];

        return $this;
    }

    /* ================= PHASE 2 ================= */

    public function previousHash(?string $hash): self
    {
        $this->previousHash = $hash;
        return $this;
    }

    /* ================= BUILD ================= */

    public function build(): InvoiceDocument
    {
        if (empty($this->items)) {
            throw new InvalidArgumentException('Invoice must contain at least one item.');
        }

        if ($this->type === 'standard') {
            if (!$this->buyerName) {
                throw new InvalidArgumentException('Buyer name is required for standard invoices.');
            }
            if (!$this->supplyDate) {
                $this->supplyDate = $this->issueDate;
            }
            if (!$this->buyerVat && !$this->buyerId) {
                throw new InvalidArgumentException(
                    'Buyer VAT or Buyer ID (BT-46) is required for standard invoices.'
                );
            }
        }

        if ($this->invoiceCounter === '') {
            throw new InvalidArgumentException('Invoice Counter (KSA-16) is required.');
        }

        $transactionCode = $this->type === 'standard'
            ? '0100000'
            : '0200000';

        $model = new InvoiceModel(
            type: $this->type,
            invoiceNumber: $this->invoiceNumber,
            uuid: $this->uuid,
            issueDate: $this->issueDate,
            issueTime: $this->issueTime,
            transactionCode: $transactionCode,

            buyerName: $this->buyerName,
            buyerAddress: $this->buyerAddress,
            buyerVat: $this->buyerVat,
            buyerId: $this->buyerId,

            items: $this->items,

            previousHash: $this->previousHash,
            invoiceCounter: $this->invoiceCounter,
            supplyDate: $this->supplyDate
        );

        // Final safety net (structure + Phase 2 rules)
        Phase2Validator::validate($this->ctx, $model);

        return new InvoiceDocument($this->ctx, $model);
    }
}
