# meita/zatca-engine

A framework-agnostic PHP engine for generating ZATCA Phase 2 compliant e-invoices (UBL 2.1) with built-in validation, hashing, signing hooks, QR (TLV), and invoice chaining (ICV/PIH).

Goal: produce XML that passes the ZATCA simulator without XSD errors or BR warnings and is ready for Phase 2 clearance/reporting flows.

---

## Requirements
- PHP 8.1+
- Extensions: ext-dom, ext-openssl
- No other runtime dependencies

---

## Installation
```bash
composer require meita/zatca-engine
```

---

## End-to-End Quick Start (Plain PHP)
```php
require __DIR__.'/vendor/autoload.php';

use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Core\Engine;

// 1) Company context (multi-tenant friendly)
$ctx = Context::fromArray([
  'company_key' => 'dania',
  'currency'    => 'SAR',   // required by ZATCA
  'tax_rate'    => 15,      // default VAT percent

  'seller' => [
    'name' => 'DANIA AIR CONTROL SYSTEM FACTORY',
    'vat'  => '310123456700003',
    'crn'  => '2341682066', // will be output once with schemeID=CRN
    'address' => [
      'street'      => 'Sudair Street',
      'building_no' => '4230',    // 4 digits (KSA-17)
      'city'        => 'Riyadh',
      'district'    => 'Al Nadheem',
      'postal_code' => '12987',
      'country'     => 'SA',
    ],
  ],
]);

// 2) Build invoice
$engine = new Engine($ctx);

$invoice = $engine->invoice()
  ->standard()                // or ->simplified()
  ->number('INV-2025-0001')
  ->counter(1)                // ICV (KSA-16)
  ->previousHash(null)        // PIH (KSA-13); null -> zeros for first invoice
  ->issueAt('2025-12-13', '09:26:57')
  ->supplyDate('2025-12-12')  // mandatory for standard
  ->buyer(
      'MohEita Company',
      [
        'street'      => 'King Fahd Rd',
        'building_no' => '1234',   // 4 digits (KSA-18)
        'city'        => 'Riyadh',
        'district'    => 'Al Olaya',
        'postal_code' => '11564',
        'country'     => 'SA',
      ],
      '319123456700003' // Buyer VAT (BT-48); if missing, set buyerId instead
  )
  ->addItem('Air Grill 25x25', 2, 100.00)
  ->addItem('Air Grill 30x30', 5, 120.00)
  ->addItem('Air Grill 50x50', 17, 165.15)
  ->build();

// 3) Outputs
$xml  = $invoice->toXml();      // UBL 2.1 XML (ordered for XSD)
$hash = $invoice->hash();       // SHA-256 over canonicalized XML (hex)
$qr   = $invoice->qrBase64();   // TLV QR as base64 (Phase 2 tags)

file_put_contents('invoice.xml', $xml);
file_put_contents('invoice.hash.txt', $hash);
file_put_contents('invoice.qr.txt', $qr);
```

---

## Worked Example (with comments and masked IDs)
```php
require __DIR__.'/vendor/autoload.php';

use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Core\Engine;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$tz  = new DateTimeZone('Asia/Riyadh');
$now = new DateTimeImmutable('now', $tz);

// Dynamic timestamps (override with env vars if needed)
$issueDate  = getenv('INVOICE_DATE') ?: $now->format('Y-m-d');
$issueTime  = getenv('INVOICE_TIME') ?: $now->format('H:i:s');
$supplyDate = getenv('SUPPLY_DATE') ?: $issueDate; // mandatory for standard

$seller = [
    'name' => 'MohEita Systems LLC',
    'vat'  => '310*********0003', // masked example VAT
    'crn'  => '2341****66',       // masked example CRN (schemeID=CRN)
    'address' => [
        'street'      => 'Sudair St',
        'building_no' => '4230',   // 4 digits (KSA-17)
        'city'        => 'Riyadh',
        'district'    => 'Azzahra',
        'postal_code' => '12987',
        'country'     => 'SA',
    ],
];

$buyer = [
    'name' => 'Dania Air Control System Factory',
    'vat'  => '319*********0003', // buyer VAT; or set buyer_id instead
    'buyer_id' => null,
    'address' => [
        'street'      => 'King Fahd Rd',
        'building_no' => '1250',   // 4 digits (KSA-18)
        'city'        => 'Riyadh',
        'district'    => 'Al Olaya',
        'postal_code' => '11564',
        'country'     => 'SA',
    ],
];

$items = [
    ['name' => 'Air Grill 25x25', 'qty' => 2,  'price' => 100.00],
    ['name' => 'Air Grill 30x30', 'qty' => 5,  'price' => 120.00],
    ['name' => 'Air Grill 50x50', 'qty' => 17, 'price' => 165.15],
    // ...add more lines as needed
];

// Context (SAR enforced; 15% VAT)
$ctx = Context::fromArray([
    'company_key' => 'dania',
    'currency'    => 'SAR',
    'tax_rate'    => 15,
    'seller'      => $seller,
]);

$engine = new Engine($ctx);

// These can be computed from storage/history for chaining
$invoiceNumber = 'INV-2025-0008';
$counter       = 8;     // ICV (KSA-16)
$previousHash  = null;  // PIH (KSA-13); null => zeros for first

$builder = $engine->invoice()
    ->standard()
    ->number($invoiceNumber)
    ->counter($counter)
    ->issueAt($issueDate, $issueTime)
    ->supplyDate($supplyDate) // required for standard invoices
    ->buyer(
        $buyer['name'],
        $buyer['address'],
        $buyer['vat'],
        $buyer['buyer_id']
    )
    ->previousHash($previousHash);

foreach ($items as $item) {
    $builder->addItem($item['name'], $item['qty'], $item['price']);
}

$invoice = $builder->build();

// Outputs
$xml  = $invoice->toXml();
$hash = $invoice->hash();
$qr   = $invoice->qrBase64(); // TLV-encoded base64

// Optional: QR PNG rendering
$writer = new PngWriter();
$qrImg = $writer->write(
    QrCode::create($qr)
        ->setEncoding(new Encoding('UTF-8'))
        ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
        ->setSize(320)
        ->setMargin(10)
);
$qrImg->saveToFile(__DIR__.'/invoices/'.$invoiceNumber.'.qr.png');

file_put_contents(__DIR__.'/invoices/'.$invoiceNumber.'.xml', $xml);
file_put_contents(__DIR__.'/invoices/'.$invoiceNumber.'.hash.txt', $hash);
file_put_contents(__DIR__.'/invoices/'.$invoiceNumber.'.qr.txt', $qr);

echo "Invoice generated: {$invoiceNumber}\n";
```

Hints to avoid warnings in the example above:
- Fill every seller/buyer address field (street, 4-digit building_no, city, district, postal_code, country).
- Always set supplyDate for standard invoices.
- Provide buyer VAT or buyer ID (scheme NAT) for standard invoices.
- Keep TaxCurrencyCode equal to DocumentCurrencyCode (SAR) unless you must differ; if you change it, BG-23 (TaxSubtotal) will be omitted by design.
- Maintain chaining: increment counter (ICV) and feed previousHash from the prior invoice hash.

---

## Data Checklist (avoid common warnings/errors)
- Currency: SAR (BR-KSA-68).
- Seller identity: name, vat, single crn (alphanumeric, schemeID=CRN).
- Seller address: street, building_no (4 digits), city, district, postal_code, country.
- Standard invoices:
  - buyerName present.
  - buyerVat or buyerId (scheme NAT by default) provided.
  - supplyDate provided (KSA-5).
  - Buyer address when country = SA: street, building_no (4 digits), city, district, postal_code, country.
- Items: quantity > 0, price >= 0, name present, VAT category (defaults to S), unit code (defaults to EA).
- Chaining: counter (ICV) required; previousHash (PIH) required (zeros auto for first).

---

## Builder Reference
- standard() / simplified()
- number(string $id)
- counter(string|int $icv) // KSA-16
- previousHash(?string $hash) // KSA-13, hex64 or base64; null => zeros
- issueAt(string $date, string $time)
- supplyDate(string $date) // required for standard
- buyer(string $name, array $address, ?string $vat = null, ?string $buyerId = null)
  - Address keys: street, building_no, city, district, postal_code, country
- addItem(string $name, float $qty, float $unitPrice, ?float $vatRate = null, string $vatCategory = 'S', string $unitCode = 'EA')

---

## Output & Validation Notes
- XML ordering matches UBL 2.1 and ZATCA expectations:
  - Header -> InvoicePeriod (for standard) -> AdditionalDocumentReference (ICV/PIH) -> Parties -> Totals -> Lines.
  - PostalAddress uses PostalZone then District; no CitySubdivisionName.
- Tax totals:
  - Always one document-level TaxTotal.
  - TaxSubtotal (BG-23) is included only when TaxCurrencyCode == DocumentCurrencyCode.
  - Line-level TaxTotal contains TaxAmount (KSA-11) and RoundingAmount = line gross (KSA-12).
- Totals use integer cents internally to avoid rounding drift (BR-CO-15).
- CRN is sanitized to alphanumeric before emitting with schemeID="CRN" (BR-KSA-08).

---

## Common ZATCA Findings and How to Avoid Them
- BR-CO-18 (missing VAT breakdown): ensure TaxCurrencyCode matches DocumentCurrencyCode (or leave unset) so BG-23 is emitted.
- BR-KSA-EN16931-09 (TaxSubtotal present with different tax currency): if you set a different tax currency, no TaxSubtotal will be emitted; prefer same currency unless required.
- BR-KSA-15 (supply date missing): set supplyDate for standard invoices.
- BR-KSA-52/53 (line VAT and gross missing): keep line TaxTotal with TaxAmount and RoundingAmount (already handled).
- BR-KSA-08 (seller ID): provide one CRN, alphanumeric, via seller crn.
- BR-KSA-09 / BR-KSA-63 (address completeness): fill all address fields listed in the checklist; building numbers must be 4 digits.
- BR-KSA-F-06-C28 (district length): districts are auto-truncated to 127 chars; ensure non-empty when required.

---

## Laravel Adapter (Optional)
```bash
php artisan vendor:publish --tag=zatca-engine-config
```
```php
use ZatcaEngine;

$xml = ZatcaEngine::company('dania')
  ->invoice()
  ->standard()
  ->number('INV-2025-0001')
  ->supplyDate('2025-12-12')
  ->buyer(
    'Customer',
    [
      'street' => 'Street',
      'building_no' => '1234',
      'city' => 'Riyadh',
      'district' => 'District',
      'postal_code' => '11564',
      'country' => 'SA',
    ],
    '319...'
  )
  ->addItem('Item A', 2, 100)
  ->build()
  ->toXml();
```

---

## Troubleshooting Checklist
- Verify all required seller/buyer fields are present (see Data Checklist).
- Keep TaxCurrencyCode aligned with DocumentCurrencyCode unless you must differ (then expect no TaxSubtotal).
- For first invoice in a chain, previousHash(null) to auto-fill zeros.
- Use 4-digit building_no for both seller and buyer (when country is SA).
- Regenerate XML after any data change and re-run the ZATCA validator.

---

## License
MIT
