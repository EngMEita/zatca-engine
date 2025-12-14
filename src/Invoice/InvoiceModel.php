<?php

namespace Meita\ZatcaEngine\Invoice;

final class InvoiceModel
{
    public function __construct(
        public string $type, // standard|simplified

        // Core identifiers
        public string $invoiceNumber,
        public string $uuid,

        // Timestamps
        public string $issueDate, // YYYY-MM-DD
        public string $issueTime, // HH:MM:SS

        // KSA-2 (0100000 standard | 0200000 simplified)
        public string $transactionCode,

        // Buyer
        public ?string $buyerName = null,
        public array $buyerAddress = [],

        // Buyer VAT (BT-48) optional
        public ?string $buyerVat = null,

        // Buyer ID (BT-46) REQUIRED when buyerVat is missing in standard invoices
        // Examples: National ID/Iqama/CRN - you decide scheme in builder (default NAT)
        public ?string $buyerId = null,

        // Lines
        public array $items = [],

        // Phase 2 linking
        // Previous Invoice Hash (KSA-13): accept HEX(64) or Base64; null => first invoice zeros
        public ?string $previousHash = null,

        // Invoice Counter Value (KSA-16) — MUST exist (BR-KSA-33)
        // This is NOT invoiceNumber. It's a sequential counter string/number.
        public string $invoiceCounter = '1',

        // Supply date (KSA-5) required for standard invoices
        public ?string $supplyDate = null,

        // Optional: Tax currency if ever differs; keep same as currency usually
        public ?string $taxCurrencyCode = null,

        // Optional: VAT category per invoice (fallback for lines)
        public string $vatCategory = 'S',
    ) {}
}
