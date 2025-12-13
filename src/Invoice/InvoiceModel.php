<?php
namespace Meita\ZatcaEngine\Invoice;

final class InvoiceModel
{
    public function __construct(
        public string $type, // standard|simplified
        public string $invoiceNumber,
        public string $uuid,
        public string $issueDate,
        public string $issueTime,
        public string $transactionCode, // 0100000|0200000
        public array $buyerAddress,
        public ?string $buyerName = null,
        public ?string $buyerVat = null,
        public array $items = [],
        public ?string $previousHash = null,
        public string $icv = '1',
    ) {}
}
