# meita/zatca-engine

A framework-agnostic PHP engine for generating ZATCA Phase 2 compliant e-invoices using UBL 2.1, with built-in validation, hashing, signing hooks, QR (TLV), and invoice chaining (ICV / PIH).

> Target: produce XML that passes ZATCA simulator without XSD errors and is structured for Phase 2 flows (Clearance/Reporting).

---

## Requirements

- PHP 8.1+
- Extensions: ext-dom, ext-openssl

No runtime dependencies besides PHP extensions.

---

## Installation

```bash
composer require meita/zatca-engine
```

---

## Checklist for valid Phase 2 XML

- Currency must be SAR.
- Seller: name, VAT, CRN (schemeID=CRN), and address with street, building_no (4 digits), city, district, postal_code, country.
- Standard invoices: buyer name, buyer country code, and buyer VAT or buyer ID (scheme NAT by default if VAT is absent).
- Buyer address (country = SA): street, building_no (4 digits), city, district, postal_code, country.
- PostalAddress ordering follows UBL: PostalZone precedes District; no CitySubdivisionName.
- Single TaxTotal at document level; line totals include VAT.
- ICV (counter) and PIH (previous hash) included via AdditionalDocumentReference.

---

## Quick Start (Plain PHP)

```php
require __DIR__.'/vendor/autoload.php';

use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Core\Engine;

$ctx = Context::fromArray([
  'company_key' => 'dania',
  'currency' => 'SAR',
  'tax_rate' => 15,

  'seller' => [
    'name' => 'DANIA AIR CONTROL SYSTEM FACTORY',
    'vat'  => '310123456700003',
    'crn'  => '2341682066',
    'address' => [
      'street' => 'Sudair Street',
      'building_no' => '4230',
      'city' => 'Riyadh',
      'district' => 'Al Nadheem',
      'postal_code' => '12987',
      'country' => 'SA',
    ],
  ],
]);

$engine = new Engine($ctx);

$invoice = $engine->invoice()
  ->standard() // or ->simplified()
  ->number('INV-2025-0001')
  ->counter(1)             // ICV (KSA-16)
  ->previousHash(null)     // PIH (KSA-13) base64/hex; null auto-fills zeros for first invoice
  ->issueAt('2025-12-13', '09:26:57')
  ->supplyDate('2025-12-12')
  ->buyer(
      'MohEita Company',
      [
        'street' => 'King Fahd Rd',
        'building_no' => '1234',
        'city' => 'Riyadh',
        'district' => 'Al Olaya',
        'postal_code' => '11564',
        'country' => 'SA',
      ],
      '319123456700003' // Buyer VAT (BT-48); if omitted, set buyerId instead
  )
  ->addItem('Air Grill 25x25', 2, 100)
  ->addItem('Air Grill 30x30', 5, 120)
  ->addItem('Air Grill 50x50', 17, 165.15)
  ->build();

$xml = $invoice->toXml();      // UBL 2.1 XML
$hash = $invoice->hash();      // SHA-256 over canonicalized XML
$qr   = $invoice->qrBase64();  // TLV QR as base64 (Phase 2 tags ready)

file_put_contents('invoice.xml', $xml);
file_put_contents('invoice.hash.txt', $hash);
file_put_contents('invoice.qr.txt', $qr);
```

---

## Laravel Adapter (Optional)

This repository contains a Laravel adapter. If you include this engine in a Laravel app, you can publish config and resolve the engine from the container.

### Publish config
```bash
php artisan vendor:publish --tag=zatca-engine-config
```

### Usage
```php
use ZatcaEngine;

$engine = ZatcaEngine::company('dania');

$xml = $engine->invoice()
  ->standard()
  ->number('INV-2025-0001')
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

## Validation (Phase 2 oriented)

Before generating XML, the engine enforces common Phase 2 constraints:
- UBL ordering for LegalMonetaryTotal and PostalAddress (PostalZone before District)
- Line amounts include VAT (TaxInclusiveAmount per line)
- ClassifiedTaxCategory is inside Item
- Single TaxTotal at document level (TaxSubtotal omitted when TaxCurrencyCode present)
- Mandatory seller / buyer minimal fields, with KSA address rules when country is SA
- Building number format (4 digits) and required ICV/PIH values

If anything is invalid, Phase2ValidationException is thrown with a structured error list.

---

## Extending / Integrating Signing and Clearance

The engine provides a native OpenSSL signing helper (ECDSA) as a building block. You can integrate your Clearance / Reporting HTTP client in your app without changing the core.

---

## License

MIT
