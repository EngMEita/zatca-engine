# meita/zatca-engine

A **framework-agnostic** PHP engine for generating **ZATCA Phase 2** compliant e-invoices using **UBL 2.1**, with built-in validation, hashing, signing hooks, QR (TLV), and invoice chaining (ICV / PIH).

> Target: produce XML that passes ZATCA simulator **without XSD errors** and is structured for Phase 2 flows (Clearance/Reporting).

---

## Requirements

- PHP **8.1+**
- Extensions: `ext-dom`, `ext-openssl`

No runtime dependencies besides PHP extensions.

---

## Installation

```bash
composer require meita/zatca-engine
```

---

## Concepts

### Context (multi-company)
A `Context` holds company-specific configuration (seller identity, address, tax rate, environment, keys, etc.).
You can create **multiple contexts at the same time** (multi-tenant / multi-company) without global config.

### Engine
`Engine` is the entry point. You create it with a `Context` and build invoices from it.

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
      'postal_code' => '12987',
      'country' => 'SA',
    ],
  ],
]);

$engine = new Engine($ctx);

$invoice = $engine->invoice()
  ->standard() // or ->simplified()
  ->number('INV-2025-0001')
  ->issueAt('2025-12-13', '09:26:57')
  ->buyer('MohEita Company', '319123456700003', [
      'street' => 'King Fahd Rd',
      'building_no' => '1234',
      'city' => 'Riyadh',
      'postal_code' => '11564',
      'country' => 'SA',
  ])
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
  ->buyer('Customer', '319...')
  ->addItem('Item A', 2, 100)
  ->build()
  ->toXml();
```

---

## Validation (Phase 2 oriented)

Before generating XML, the engine enforces common Phase 2 constraints:
- UBL ordering for `LegalMonetaryTotal` (prevents XSD errors)
- Line amounts include VAT (`TaxInclusiveAmount` per line)
- `ClassifiedTaxCategory` is inside `Item`
- Single `TaxTotal` at document level
- Mandatory seller / buyer minimal fields
- KSA building number format (4 digits) and address non-empty (for standard)

If anything is invalid, `Phase2ValidationException` is thrown with a structured error list.

---

## Extending / Integrating Signing & Clearance

The engine provides a native OpenSSL signing helper (ECDSA) as a building block.
You can integrate your Clearance / Reporting HTTP client in your app without changing the core.

---

## License

MIT