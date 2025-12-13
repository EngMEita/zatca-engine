<?php
namespace Meita\ZatcaEngine\Invoice;

use DOMDocument;
use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Helpers\DateHelper;
use Meita\ZatcaEngine\Helpers\NumberHelper;
use Meita\ZatcaEngine\Helpers\UuidHelper;
use Meita\ZatcaEngine\Crypto\Canonicalizer;
use Meita\ZatcaEngine\Crypto\Sha256;
use Meita\ZatcaEngine\QR\QrGenerator;
use Meita\ZatcaEngine\Validators\Phase2Validator;

final class InvoiceBuilder
{
    private string $type = 'standard';
    private string $invoiceNumber = '1';
    private string $uuid;
    private string $issueDate;
    private string $issueTime;
    private array $buyerAddress = [];
    private ?string $buyerName = null;
    private ?string $buyerVat = null;
    private array $items = [];
    private ?string $previousHash = null;
    private string $icv = '1';

    public function __construct(private readonly Context $ctx)
    {
        $this->uuid = UuidHelper::v4();
        $this->issueDate = DateHelper::today();
        $this->issueTime = DateHelper::nowTime();
    }

    public function standard(): self { $this->type = 'standard'; return $this; }
    public function simplified(): self { $this->type = 'simplified'; return $this; }

    public function number(string $invoiceNumber): self { $this->invoiceNumber = $invoiceNumber; return $this; }

    public function issueAt(string $date, string $time): self
    {
        $this->issueDate = $date;
        $this->issueTime = $time;
        return $this;
    }

    public function buyer(string $name, ?string $vat = null, array $address = []): self
    {
        $this->buyerName = $name;
        $this->buyerVat = $vat;
        $this->buyerAddress = $address;
        return $this;
    }

    public function addItem(string $name, float $qty, float $unitPrice, ?float $vatRate = null, string $vatCategory = 'S'): self
    {
        $this->items[] = [
            'name' => $name,
            'qty' => $qty,
            'price' => $unitPrice,
            'vat_rate' => $vatRate ?? $this->ctx->taxRate(),
            'vat_category' => $vatCategory,
        ];
        return $this;
    }

    public function previousHash(?string $hash): self { $this->previousHash = $hash; return $this; }
    public function icv(string $icv): self { $this->icv = $icv; return $this; }

    public function build(): InvoiceDocument
    {
        $tx = $this->type === 'standard' ? '0100000' : '0200000';

        $model = new InvoiceModel(
            type: $this->type,
            invoiceNumber: $this->invoiceNumber,
            uuid: $this->uuid,
            issueDate: $this->issueDate,
            issueTime: $this->issueTime,
            transactionCode: $tx,
            buyerAddress: $this->buyerAddress,
            buyerName: $this->buyerName,
            buyerVat: $this->buyerVat,
            items: $this->items,
            previousHash: $this->previousHash,
            icv: $this->icv
        );

        Phase2Validator::validate($this->ctx, $model);
        return new InvoiceDocument($this->ctx, $model);
    }
}
