<?php
require __DIR__ . '/../vendor/autoload.php';

use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Core\Engine;

$ctx = Context::fromArray([
  'company_key' => 'default',
  'currency' => 'SAR',
  'tax_rate' => 15,
  'seller' => [
    'name' => 'SMART EWS COMPANY',
    'vat' => '310123456700003',
    'crn' => '2341682066',
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

$doc = $engine->invoice()
  ->standard()
  ->number('INV-1')
  ->issueAt(date('Y-m-d'), date('H:i:s'))
  ->buyer('MohEita Company','319123456700003',[
    'street'=>'King Fahd Rd',
    'building_no'=>'1234',
    'city'=>'Riyadh',
    'postal_code'=>'11564',
    'country'=>'SA',
  ])
  ->addItem('Air Grill 25x25',2,100)
  ->build();

file_put_contents(__DIR__.'/invoice.xml', $doc->toXml());
file_put_contents(__DIR__.'/invoice.hash.txt', $doc->hash());
file_put_contents(__DIR__.'/invoice.qr.txt', $doc->qrBase64());

echo "Done.\n";
