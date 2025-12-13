<?php

namespace Meita\ZatcaEngine\Invoice;

use DOMDocument;
use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Helpers\NumberHelper;
use Meita\ZatcaEngine\Crypto\Canonicalizer;
use Meita\ZatcaEngine\Crypto\Sha256;
use Meita\ZatcaEngine\QR\QrGenerator;

final class InvoiceDocument
{
    public function __construct(
        private readonly Context $ctx,
        private readonly InvoiceModel $m
    ) {}

    public function model(): InvoiceModel
    {
        return $this->m;
    }

    public function toXml(): string
    {
        $currency = $this->ctx->currency();
        $taxRate  = $this->ctx->taxRate();

        $seller = $this->ctx->seller();
        $sAddr  = $seller['address'] ?? [];

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;

        $inv = $doc->createElement('Invoice');
        $inv->setAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $inv->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $inv->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // Header
        $inv->appendChild($doc->createElement('cbc:UBLVersionID', '2.1'));
        $inv->appendChild($doc->createElement('cbc:CustomizationID', 'urn:ubl:specification:standard:1.0'));
        $inv->appendChild($doc->createElement('cbc:ProfileID', 'reporting:1.0'));
        $inv->appendChild($doc->createElement('cbc:ProfileExecutionID', '1.0'));
        $inv->appendChild($doc->createElement('cbc:ID', $this->m->invoiceNumber));
        $inv->appendChild($doc->createElement('cbc:UUID', $this->m->uuid));
        $inv->appendChild($doc->createElement('cbc:IssueDate', $this->m->issueDate));
        $inv->appendChild($doc->createElement('cbc:IssueTime', $this->m->issueTime));

        $invoiceTypeCode = $this->m->type === 'standard' ? '388' : '389';
        $typeEl = $doc->createElement('cbc:InvoiceTypeCode', $invoiceTypeCode);
        $typeEl->setAttribute('name', $this->m->transactionCode);
        $inv->appendChild($typeEl);

        // Currencies (BT-5 & BT-6) – TaxCurrencyCode is mandatory in ZATCA
        $inv->appendChild($doc->createElement('cbc:DocumentCurrencyCode', $currency));
        $taxCurrency = $this->m->taxCurrencyCode ?? $currency;
        $inv->appendChild($doc->createElement('cbc:TaxCurrencyCode', $taxCurrency));

        // Supply date (KSA-5) for standard invoices via InvoicePeriod
        if ($this->m->type === 'standard') {
            $period = $doc->createElement('cac:InvoicePeriod');
            $period->appendChild($doc->createElement('cbc:StartDate', $this->m->supplyDate ?? $this->m->issueDate));
            $inv->appendChild($period);
        }

        // =========================
        // Seller Party (AccountingSupplierParty)
        // =========================
        $sup = $doc->createElement('cac:AccountingSupplierParty');
        $p   = $doc->createElement('cac:Party');

        $sellerName = trim((string)($seller['name'] ?? ''));
        $sellerVat  = preg_replace('/\s+/', '', (string)($seller['vat'] ?? ''));
        $sellerCrn  = preg_replace('/\s+/', '', (string)($seller['crn'] ?? ''));

        if ($sellerName === '' || $sellerVat === '' || $sellerCrn === '') {
            throw new \InvalidArgumentException('Seller name, VAT and CRN are required for ZATCA invoices.');
        }

        $street  = trim((string)($sAddr['street'] ?? ''));
        $city    = trim((string)($sAddr['city'] ?? ''));
        $postal  = trim((string)($sAddr['postal_code'] ?? ''));
        $country = strtoupper(trim((string)($sAddr['country'] ?? 'SA')));

        $bnoRaw = preg_replace('/\D+/', '', (string)($sAddr['building_no'] ?? ''));
        $bno    = str_pad(substr($bnoRaw, 0, 4), 4, '0', STR_PAD_LEFT);

        if ($street === '' || $city === '' || $postal === '') {
            throw new \InvalidArgumentException('Seller address street/city/postal_code are required.');
        }

        // PartyName
        $pn = $doc->createElement('cac:PartyName');
        $pn->appendChild($doc->createElement('cbc:Name', $sellerName));
        $p->appendChild($pn);

        // ✅ PostalAddress (BG-5) – REQUIRED by BR-08
        $postalAddress = $doc->createElement('cac:PostalAddress');
        $postalAddress->appendChild($doc->createElement('cbc:StreetName', $street));
        $postalAddress->appendChild($doc->createElement('cbc:BuildingNumber', $bno));
        $postalAddress->appendChild($doc->createElement('cbc:CityName', $city));
        $postalAddress->appendChild($doc->createElement('cbc:PostalZone', $postal));
        $countryEl = $doc->createElement('cac:Country');
        $countryEl->appendChild($doc->createElement('cbc:IdentificationCode', $country));
        $postalAddress->appendChild($countryEl);
        $p->appendChild($postalAddress);

        // ✅ PartyTaxScheme – VAT only (do NOT put address here)
        $pts = $doc->createElement('cac:PartyTaxScheme');
        $pts->appendChild($doc->createElement('cbc:CompanyID', $sellerVat));
        $ts = $doc->createElement('cac:TaxScheme');
        $ts->appendChild($doc->createElement('cbc:ID', 'VAT'));
        $pts->appendChild($ts);
        $p->appendChild($pts);

        // ✅ PartyLegalEntity – put CRN here (NOT PartyIdentification)
        $ple = $doc->createElement('cac:PartyLegalEntity');
        $ple->appendChild($doc->createElement('cbc:RegistrationName', $sellerName));

        $crnEl = $doc->createElement('cbc:CompanyID', $sellerCrn);
        $crnEl->setAttribute('schemeID', 'CRN');
        $ple->appendChild($crnEl);

        $p->appendChild($ple);

        $sup->appendChild($p);
        $inv->appendChild($sup);

        // =========================
        // Buyer Party (AccountingCustomerParty) – required for standard
        // =========================
        if ($this->m->type === 'standard') {
            $buyerName = trim((string)($this->m->buyerName ?? ''));
            if ($buyerName === '') {
                throw new \InvalidArgumentException('Buyer name is mandatory for standard (tax) invoices.');
            }

            $cus = $doc->createElement('cac:AccountingCustomerParty');
            $cp  = $doc->createElement('cac:Party');

            $cpn = $doc->createElement('cac:PartyName');
            $cpn->appendChild($doc->createElement('cbc:Name', $buyerName));
            $cp->appendChild($cpn);

            $bAddr = $this->m->buyerAddress ?? [];
            $bStreet  = trim((string)($bAddr['street'] ?? ''));
            $bCity    = trim((string)($bAddr['city'] ?? ''));
            $bPostal  = trim((string)($bAddr['postal_code'] ?? ''));
            $bCountry = strtoupper(trim((string)($bAddr['country'] ?? 'SA')));

            $bBnoRaw = preg_replace('/\D+/', '', (string)($bAddr['building_no'] ?? ''));
            $bBno    = str_pad(substr($bBnoRaw, 0, 4), 4, '0', STR_PAD_LEFT);

            // ensure not empty to avoid BR-KSA-F-06 warnings
            if ($bStreet === '') $bStreet = 'N/A';
            if ($bCity === '')   $bCity   = 'N/A';
            if ($bPostal === '') $bPostal = '00000';

            // ✅ Buyer PostalAddress
            $bPostalAddress = $doc->createElement('cac:PostalAddress');
            $bPostalAddress->appendChild($doc->createElement('cbc:StreetName', $bStreet));
            $bPostalAddress->appendChild($doc->createElement('cbc:BuildingNumber', $bBno));
            $bPostalAddress->appendChild($doc->createElement('cbc:CityName', $bCity));
            $bPostalAddress->appendChild($doc->createElement('cbc:PostalZone', $bPostal));
            $bCountryEl = $doc->createElement('cac:Country');
            $bCountryEl->appendChild($doc->createElement('cbc:IdentificationCode', $bCountry));
            $bPostalAddress->appendChild($bCountryEl);
            $cp->appendChild($bPostalAddress);

            // Buyer VAT (optional)
            $cts = $doc->createElement('cac:PartyTaxScheme');
            if ($this->m->buyerVat) {
                $cts->appendChild($doc->createElement('cbc:CompanyID', (string)$this->m->buyerVat));
            }
            $ctsTs = $doc->createElement('cac:TaxScheme');
            $ctsTs->appendChild($doc->createElement('cbc:ID', 'VAT'));
            $cts->appendChild($ctsTs);
            $cp->appendChild($cts);

            $cus->appendChild($cp);
            $inv->appendChild($cus);
        }

        // =========================
        // Lines
        // =========================
        $netTotal = 0.0;
        foreach ($this->m->items as $i => $it) {
            $qty   = (float)$it['qty'];
            $price = (float)$it['price'];

            $lineNet   = $qty * $price;
            $netTotal += $lineNet;

            $lineVat   = $lineNet * ((float)$it['vat_rate'] / 100);
            $lineGross = $lineNet + $lineVat;

            $line = $doc->createElement('cac:InvoiceLine');
            $line->appendChild($doc->createElement('cbc:ID', (string)($i + 1)));

            $q = $doc->createElement('cbc:InvoicedQuantity', (string)$qty);
            $q->setAttribute('unitCode', $it['unit_code'] ?? 'EA');
            $line->appendChild($q);

            $le = $doc->createElement('cbc:LineExtensionAmount', NumberHelper::money($lineNet));
            $le->setAttribute('currencyID', $currency);
            $line->appendChild($le);

            // KSA-11 & KSA-12
            $lineTaxTotal = $doc->createElement('cac:TaxTotal');

            $ltVat = $doc->createElement('cbc:TaxAmount', NumberHelper::money($lineVat));
            $ltVat->setAttribute('currencyID', $currency);
            $lineTaxTotal->appendChild($ltVat);

            $ltGross = $doc->createElement('cbc:RoundingAmount', NumberHelper::money($lineGross));
            $ltGross->setAttribute('currencyID', $currency);
            $lineTaxTotal->appendChild($ltGross);

            $line->appendChild($lineTaxTotal);

            $itemEl = $doc->createElement('cac:Item');
            $itemEl->appendChild($doc->createElement('cbc:Name', $it['name']));

            $ctc = $doc->createElement('cac:ClassifiedTaxCategory');
            $ctc->appendChild($doc->createElement('cbc:ID', $it['vat_category'] ?? 'S'));
            $ctc->appendChild($doc->createElement('cbc:Percent', (string)$it['vat_rate']));
            $ctcTs = $doc->createElement('cac:TaxScheme');
            $ctcTs->appendChild($doc->createElement('cbc:ID', 'VAT'));
            $ctc->appendChild($ctcTs);
            $itemEl->appendChild($ctc);

            $line->appendChild($itemEl);

            $priceEl = $doc->createElement('cac:Price');
            $pa = $doc->createElement('cbc:PriceAmount', NumberHelper::money($price));
            $pa->setAttribute('currencyID', $currency);
            $priceEl->appendChild($pa);
            $line->appendChild($priceEl);

            $inv->appendChild($line);
        }

        $vatTotal   = $netTotal * ($taxRate / 100);
        $grossTotal = $netTotal + $vatTotal;

        // =========================
        // Document TaxTotal (single, without TaxSubtotal to avoid BR-KSA-EN16931-09)
        // =========================
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxAmount = $doc->createElement('cbc:TaxAmount', NumberHelper::money($vatTotal));
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);
        $inv->appendChild($taxTotal);

        // =========================
        // LegalMonetaryTotal (ordered to satisfy UBL XSD)
        // =========================
        $legal = $doc->createElement('cac:LegalMonetaryTotal');

        $leTot = $doc->createElement('cbc:LineExtensionAmount', NumberHelper::money($netTotal));
        $leTot->setAttribute('currencyID', $currency);
        $legal->appendChild($leTot);

        $te = $doc->createElement('cbc:TaxExclusiveAmount', NumberHelper::money($netTotal));
        $te->setAttribute('currencyID', $currency);
        $legal->appendChild($te);

        $tiTot = $doc->createElement('cbc:TaxInclusiveAmount', NumberHelper::money($grossTotal));
        $tiTot->setAttribute('currencyID', $currency);
        $legal->appendChild($tiTot);

        $pay = $doc->createElement('cbc:PayableAmount', NumberHelper::money($grossTotal));
        $pay->setAttribute('currencyID', $currency);
        $legal->appendChild($pay);

        $inv->appendChild($legal);

        // AdditionalDocumentReference placeholders (ICV / PIH)
        $adr1 = $doc->createElement('cac:AdditionalDocumentReference');
        $adr1->appendChild($doc->createElement('cbc:ID', 'ICV'));
        $adr1->appendChild($doc->createElement('cbc:UUID', $this->m->icv));
        $inv->appendChild($adr1);

        $pih = (string)($this->m->previousHash ?? '');
        if ($pih === '') {
            $pihB64 = base64_encode(str_repeat("\x00", 32));
        } else {
            $pihTrim = preg_replace('/\s+/', '', $pih);
            if (preg_match('/^[0-9a-fA-F]{64}$/', $pihTrim)) {
                $pihB64 = base64_encode(hex2bin($pihTrim));
            } else {
                $pihB64 = $pihTrim;
            }
        }

        $adr2 = $doc->createElement('cac:AdditionalDocumentReference');
        $adr2->appendChild($doc->createElement('cbc:ID', 'PIH'));
        $att = $doc->createElement('cac:Attachment');
        $bin = $doc->createElement('cbc:EmbeddedDocumentBinaryObject', $pihB64);
        $bin->setAttribute('mimeCode', 'text/plain');
        $att->appendChild($bin);
        $adr2->appendChild($att);
        $inv->appendChild($adr2);

        $doc->appendChild($inv);
        return $doc->saveXML();
    }

    public function canonicalXml(): string
    {
        return Canonicalizer::c14n($this->toXml());
    }

    public function hash(): string
    {
        return Sha256::hashHex($this->canonicalXml());
    }

    public function qrBase64(array $overrides = []): string
    {
        $seller  = $this->ctx->seller();
        $taxRate = $this->ctx->taxRate();

        $net = 0.0;
        foreach ($this->m->items as $it) {
            $net += (float)$it['qty'] * (float)$it['price'];
        }
        $vat   = $net * ($taxRate / 100);
        $gross = $net + $vat;

        $data = array_merge([
            'seller_name'  => $seller['name'] ?? '',
            'seller_vat'   => $seller['vat'] ?? '',
            'timestamp'    => $this->m->issueDate . 'T' . $this->m->issueTime,
            'total'        => NumberHelper::money($gross),
            'vat_total'    => NumberHelper::money($vat),
            'invoice_hash' => Sha256::hashBase64($this->canonicalXml()),
        ], $overrides);

        return QrGenerator::generateBase64($data);
    }
}
