<?php

namespace Meita\ZatcaEngine\Xml;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

final class StrictXmlBuilder
{
    private DOMDocument $doc;

    public function __construct(string $version = '1.0', string $encoding = 'utf-8', bool $formatOutput = true)
    {
        $this->doc = new DOMDocument($version, $encoding);
        $this->doc->formatOutput = $formatOutput;
    }

    public function doc(): DOMDocument
    {
        return $this->doc;
    }

    public function rootInvoice(): DOMElement
    {
        $inv = $this->doc->createElement('Invoice');
        $inv->setAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $inv->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $inv->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        return $inv;
    }

    public function el(string $name, ?string $value = null, array $attrs = []): DOMElement
    {
        $el = $this->doc->createElement($name);
        if ($value !== null) {
            $el->nodeValue = $value;
        }
        foreach ($attrs as $k => $v) {
            $el->setAttribute((string)$k, (string)$v);
        }
        return $el;
    }

    public function add(DOMElement $parent, string $name, ?string $value = null, array $attrs = []): DOMElement
    {
        $el = $this->el($name, $value, $attrs);
        $parent->appendChild($el);
        return $el;
    }

    public function requireNonEmpty(string $label, mixed $value): string
    {
        $v = trim((string)$value);
        if ($v === '') {
            throw new InvalidArgumentException($label . ' is required and cannot be empty.');
        }
        return $v;
    }

    public function requireDigits(string $label, mixed $value, int $lenMin = 1, ?int $lenExact = null): string
    {
        $v = preg_replace('/\D+/', '', (string)$value);
        if ($v === '') {
            throw new InvalidArgumentException($label . ' must contain digits.');
        }
        if ($lenExact !== null && strlen($v) !== $lenExact) {
            throw new InvalidArgumentException($label . " must be exactly {$lenExact} digits.");
        }
        if (strlen($v) < $lenMin) {
            throw new InvalidArgumentException($label . " must be at least {$lenMin} digits.");
        }
        return $v;
    }

    public function pad4Building(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string)$value);
        $digits = $digits === '' ? '0' : $digits;
        return str_pad(substr($digits, 0, 4), 4, '0', STR_PAD_LEFT);
    }

    public function money(float $amount): string
    {
        // Always 2 decimals, dot separator
        return number_format($amount, 2, '.', '');
    }

    /**
     * UBL/ZATCA strict: In PostalAddress, DO NOT use CitySubdivisionName.
     * Use District instead (ZATCA expects district).
     */
    public function addPostalAddress(
        DOMElement $party,
        array $addr,
        string $country = 'SA',
        bool $requireDistrict = true
    ): DOMElement {
        $street  = $this->requireNonEmpty('Address StreetName', $addr['street'] ?? null);
        $city    = $this->requireNonEmpty('Address CityName', $addr['city'] ?? null);
        $postal  = $this->requireNonEmpty('Address PostalZone', $addr['postal_code'] ?? null);
        $bno     = $this->pad4Building($addr['building_no'] ?? '');
        $district = trim((string)($addr['district'] ?? ''));

        if ($requireDistrict && $district === '') {
            throw new InvalidArgumentException('Address District is required (ZATCA KSA-3/KSA-4). Provide address[district].');
        }
        if ($district === '') {
            $district = 'N/A';
        }
        if (strlen($district) > 127) {
            $district = substr($district, 0, 127);
        }

        $pa = $this->add($party, 'cac:PostalAddress');
        $this->add($pa, 'cbc:StreetName', $street);
        $this->add($pa, 'cbc:BuildingNumber', $bno);
        $this->add($pa, 'cbc:CityName', $city);
        $this->add($pa, 'cbc:PostalZone', $postal);

        // Keep UBL ordering: District appears after PostalZone
        $this->add($pa, 'cbc:District', $district);

        $c = $this->add($pa, 'cac:Country');
        $this->add($c, 'cbc:IdentificationCode', strtoupper($country));

        return $pa;
    }

    public function assertNoCitySubdivisionName(string $xml): void
    {
        if (strpos($xml, ':CitySubdivisionName') !== false) {
            throw new RuntimeException('Invalid XML: CitySubdivisionName is not allowed in UBL PostalAddress. Use cbc:District.');
        }
    }
}
