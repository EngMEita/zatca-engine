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

        /* ================= HEADER ================= */
        $inv->appendChild($doc->createElement('cbc:UBLVersionID', '2.1'));
        $inv->appendChild($doc->createElement('cbc:CustomizationID', 'urn:ubl:specification:standard:1.0'));
        $inv->appendChild($doc->createElement('cbc:ProfileID', 'reporting:1.0'));
        $inv->appendChild($doc->createElement('cbc:ProfileExecutionID', '1.0'));
        $inv->appendChild($doc->createElement('cbc:ID', $this->m->invoiceNumber));
        $inv->appendChild($doc->createElement('cbc:UUID', $this->m->uuid));
        $inv->appendChild($doc->createElement('cbc:IssueDate', $this->m->issueDate));
        $inv->appendChild($doc->createElement('cbc:IssueTime', $this->m->issueTime));

        $typeCode = $this->m->type === 'standard' ? '388' : '389';
        $typeEl = $doc->createElement('cbc:InvoiceTypeCode', $typeCode);
        $typeEl->setAttribute('name', $this->m->transactionCode);
        $inv->appendChild($typeEl);

        $inv->appendChild($doc->createElement('cbc:DocumentCurrencyCode', $currency));
        $inv->appendChild($doc->createElement('cbc:TaxCurrencyCode', $currency));

        if ($this->m->type === 'standard') {
            $period = $doc->createElement('cac:InvoicePeriod');
            $period->appendChild(
                $doc->createElement('cbc:StartDate', $this->m->supplyDate ?? $this->m->issueDate)
            );
            $inv->appendChild($period);
        }

        /* ================= SELLER ================= */
        $sup = $doc->createElement('cac:AccountingSupplierParty');
        $p   = $doc->createElement('cac:Party');

        $district = $sAddr['district'] ?? 'Al Olaya';

        $pn = $doc->createElement('cac:PartyName');
        $pn->appendChild($doc->createElement('cbc:Name', $seller['name']));
        $p->appendChild($pn);

        $pa = $doc->createElement('cac:PostalAddress');
        $pa->appendChild($doc->createElement('cbc:StreetName', $sAddr['street']));
        $pa->appendChild($doc->createElement('cbc:BuildingNumber', str_pad($sAddr['building_no'], 4, '0', STR_PAD_LEFT)));
        $pa->appendChild($doc->createElement('cbc:CityName', $sAddr['city']));
        $pa->appendChild($doc->createElement('cbc:CitySubdivisionName', $district));
        $pa->appendChild($doc->createElement('cbc:PostalZone', $sAddr['postal_code']));
        $c = $doc->createElement('cac:Country');
        $c->appendChild($doc->createElement('cbc:IdentificationCode', 'SA'));
        $pa->appendChild($c);
        $p->appendChild($pa);

        $pts = $doc->createElement('cac:PartyTaxScheme');
        $pts->appendChild($doc->createElement('cbc:CompanyID', $seller['vat']));
        $ts = $doc->createElement('cac:TaxScheme');
        $ts->appendChild($doc->createElement('cbc:ID', 'VAT'));
        $pts->appendChild($ts);
        $p->appendChild($pts);

        $ple = $doc->createElement('cac:PartyLegalEntity');
        $ple->appendChild($doc->createElement('cbc:RegistrationName', $seller['name']));
        $cid = $doc->createElement('cbc:CompanyID', $seller['crn']);
        $cid->setAttribute('schemeID', 'CRN');
        $ple->appendChild($cid);
        $p->appendChild($ple);

        $sup->appendChild($p);
        $inv->appendChild($sup);

        /* ================= BUYER ================= */
        if ($this->m->type === 'standard') {
            $cus = $doc->createElement('cac:AccountingCustomerParty');
            $cp  = $doc->createElement('cac:Party');

            $bpn = $doc->createElement('cac:PartyName');
            $bpn->appendChild($doc->createElement('cbc:Name', $this->m->buyerName));
            $cp->appendChild($bpn);

            $bAddr = $this->m->buyerAddress;
            $bdistrict = $bAddr['district'] ?? 'N/A';

            $bpa = $doc->createElement('cac:PostalAddress');
            $bpa->appendChild($doc->createElement('cbc:StreetName', $bAddr['street']));
            $bpa->appendChild($doc->createElement('cbc:BuildingNumber', str_pad($bAddr['building_no'], 4, '0', STR_PAD_LEFT)));
            $bpa->appendChild($doc->createElement('cbc:CityName', $bAddr['city']));
            $bpa->appendChild($doc->createElement('cbc:CitySubdivisionName', $bdistrict));
            $bpa->appendChild($doc->createElement('cbc:PostalZone', $bAddr['postal_code']));
            $bc = $doc->createElement('cac:Country');
            $bc->appendChild($doc->createElement('cbc:IdentificationCode', 'SA'));
            $bpa->appendChild($bc);
            $cp->appendChild($bpa);

            $cus->appendChild($cp);
            $inv->appendChild($cus);
        }

        /* ================= TAX TOTAL (BG-23 REQUIRED) ================= */
        $net = 0.0;
        foreach ($this->m->items as $it) {
            $net += $it['qty'] * $it['price'];
        }
        $vat   = $net * ($taxRate / 100);
        $gross = $net + $vat;

        $taxTotal = $doc->createElement('cac:TaxTotal');
        $ta = $doc->createElement('cbc:TaxAmount', NumberHelper::money($vat));
        $ta->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($ta);

        $sub = $doc->createElement('cac:TaxSubtotal');
        $txb = $doc->createElement('cbc:TaxableAmount', NumberHelper::money($net));
        $txb->setAttribute('currencyID', $currency);
        $sub->appendChild($txb);

        $subVat = $doc->createElement('cbc:TaxAmount', NumberHelper::money($vat));
        $subVat->setAttribute('currencyID', $currency);
        $sub->appendChild($subVat);

        $cat = $doc->createElement('cac:TaxCategory');
        $cat->appendChild($doc->createElement('cbc:ID', 'S'));
        $cat->appendChild($doc->createElement('cbc:Percent', (string)$taxRate));
        $cts = $doc->createElement('cac:TaxScheme');
        $cts->appendChild($doc->createElement('cbc:ID', 'VAT'));
        $cat->appendChild($cts);
        $sub->appendChild($cat);

        $taxTotal->appendChild($sub);
        $inv->appendChild($taxTotal);

        /* ================= LEGAL TOTAL ================= */
        $legal = $doc->createElement('cac:LegalMonetaryTotal');

        $le = $doc->createElement('cbc:LineExtensionAmount', NumberHelper::money($net));
        $le->setAttribute('currencyID', $currency);
        $legal->appendChild($le);

        $te = $doc->createElement('cbc:TaxExclusiveAmount', NumberHelper::money($net));
        $te->setAttribute('currencyID', $currency);
        $legal->appendChild($te);

        $ti = $doc->createElement('cbc:TaxInclusiveAmount', NumberHelper::money($gross));
        $ti->setAttribute('currencyID', $currency);
        $legal->appendChild($ti);

        $pay = $doc->createElement('cbc:PayableAmount', NumberHelper::money($gross));
        $pay->setAttribute('currencyID', $currency);
        $legal->appendChild($pay);

        $inv->appendChild($legal);

        /* ================= LINES (MUST BE LAST) ================= */
        foreach ($this->m->items as $i => $it) {
            $line = $doc->createElement('cac:InvoiceLine');
            $line->appendChild($doc->createElement('cbc:ID', (string)($i + 1)));

            $q = $doc->createElement('cbc:InvoicedQuantity', $it['qty']);
            $q->setAttribute('unitCode', $it['unit_code'] ?? 'EA');
            $line->appendChild($q);

            $le = $doc->createElement('cbc:LineExtensionAmount', NumberHelper::money($it['qty'] * $it['price']));
            $le->setAttribute('currencyID', $currency);
            $line->appendChild($le);

            $lineTax = $doc->createElement('cac:TaxTotal');
            $lv = $doc->createElement('cbc:TaxAmount', NumberHelper::money($it['qty'] * $it['price'] * ($taxRate / 100)));
            $lv->setAttribute('currencyID', $currency);
            $lineTax->appendChild($lv);
            $lg = $doc->createElement('cbc:RoundingAmount', NumberHelper::money($it['qty'] * $it['price'] * (1 + $taxRate / 100)));
            $lg->setAttribute('currencyID', $currency);
            $lineTax->appendChild($lg);
            $line->appendChild($lineTax);

            $item = $doc->createElement('cac:Item');
            $item->appendChild($doc->createElement('cbc:Name', $it['name']));
            $line->appendChild($item);

            $inv->appendChild($line);
        }

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

    public function qrBase64(): string
    {
        return QrGenerator::generateBase64([
            'seller_name'  => $this->ctx->seller()['name'],
            'seller_vat'   => $this->ctx->seller()['vat'],
            'timestamp'    => $this->m->issueDate . 'T' . $this->m->issueTime,
            'total'        => NumberHelper::money($this->ctx->total()),
            'vat_total'    => NumberHelper::money($this->ctx->vat()),
            'invoice_hash' => Sha256::hashBase64($this->canonicalXml()),
        ]);
    }
}
