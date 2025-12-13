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

    public function model(): InvoiceModel { return $this->m; }

    public function toXml(): string
    {
        $currency = $this->ctx->currency();
        $taxRate = $this->ctx->taxRate();

        $seller = $this->ctx->seller();
        $sAddr = $seller['address'];

        $doc = new DOMDocument('1.0','utf-8');
        $doc->formatOutput = true;

        $inv = $doc->createElement('Invoice');
        $inv->setAttribute('xmlns','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $inv->setAttribute('xmlns:cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $inv->setAttribute('xmlns:cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // Header
        $inv->appendChild($doc->createElement('cbc:UBLVersionID','2.1'));
        $inv->appendChild($doc->createElement('cbc:CustomizationID','urn:ubl:specification:standard:1.0'));
        $inv->appendChild($doc->createElement('cbc:ProfileID','reporting:1.0'));
        $inv->appendChild($doc->createElement('cbc:ProfileExecutionID','1.0'));
        $inv->appendChild($doc->createElement('cbc:ID',$this->m->invoiceNumber));
        $inv->appendChild($doc->createElement('cbc:UUID',$this->m->uuid));
        $inv->appendChild($doc->createElement('cbc:IssueDate',$this->m->issueDate));
        $inv->appendChild($doc->createElement('cbc:IssueTime',$this->m->issueTime));

        $invoiceTypeCode = $this->m->type === 'standard' ? '388' : '389';
        $typeEl = $doc->createElement('cbc:InvoiceTypeCode',$invoiceTypeCode);
        $typeEl->setAttribute('name',$this->m->transactionCode);
        $inv->appendChild($typeEl);

        $inv->appendChild($doc->createElement('cbc:DocumentCurrencyCode',$currency));
        $inv->appendChild($doc->createElement('cbc:TaxCurrencyCode',$currency));

        // Supply date (Delivery) for standard
        if ($this->m->type === 'standard') {
            $delivery = $doc->createElement('cac:Delivery');
            $delivery->appendChild($doc->createElement('cbc:ActualDeliveryDate',$this->m->issueDate));
            $inv->appendChild($delivery);
        }

        // Seller Party
        $sup = $doc->createElement('cac:AccountingSupplierParty');
        $p = $doc->createElement('cac:Party');

        $pn = $doc->createElement('cac:PartyName');
        $pn->appendChild($doc->createElement('cbc:Name',$seller['name']));
        $p->appendChild($pn);

        $pts = $doc->createElement('cac:PartyTaxScheme');
        $pts->appendChild($doc->createElement('cbc:CompanyID',$seller['vat']));
        $reg = $doc->createElement('cac:RegistrationAddress');
        $reg->appendChild($doc->createElement('cbc:StreetName',$sAddr['street']));
        $reg->appendChild($doc->createElement('cbc:BuildingNumber',$sAddr['building_no']));
        $reg->appendChild($doc->createElement('cbc:CityName',$sAddr['city']));
        $reg->appendChild($doc->createElement('cbc:PostalZone',$sAddr['postal_code']));
        $c = $doc->createElement('cac:Country');
        $c->appendChild($doc->createElement('cbc:IdentificationCode',$sAddr['country'] ?? 'SA'));
        $reg->appendChild($c);
        $pts->appendChild($reg);
        $ts = $doc->createElement('cac:TaxScheme');
        $ts->appendChild($doc->createElement('cbc:ID','VAT'));
        $pts->appendChild($ts);
        $p->appendChild($pts);

        $ple = $doc->createElement('cac:PartyLegalEntity');
        $crn = $doc->createElement('cbc:CompanyID',$seller['crn']);
        $crn->setAttribute('schemeID','CRN');
        $ple->appendChild($crn);
        $p->appendChild($ple);

        $sup->appendChild($p);
        $inv->appendChild($sup);

        // Buyer Party (standard requires)
        if ($this->m->buyerName || $this->m->buyerVat) {
            $cus = $doc->createElement('cac:AccountingCustomerParty');
            $cp = $doc->createElement('cac:Party');

            $cpn = $doc->createElement('cac:PartyName');
            $cpn->appendChild($doc->createElement('cbc:Name',$this->m->buyerName ?? 'Buyer'));
            $cp->appendChild($cpn);

            $cts = $doc->createElement('cac:PartyTaxScheme');
            if ($this->m->buyerVat) $cts->appendChild($doc->createElement('cbc:CompanyID',$this->m->buyerVat));

            $bAddr = $this->m->buyerAddress;
            $breg = $doc->createElement('cac:RegistrationAddress');
            $breg->appendChild($doc->createElement('cbc:StreetName',$bAddr['street'] ?? ''));
            $breg->appendChild($doc->createElement('cbc:BuildingNumber',$bAddr['building_no'] ?? ''));
            $breg->appendChild($doc->createElement('cbc:CityName',$bAddr['city'] ?? ''));
            $breg->appendChild($doc->createElement('cbc:PostalZone',$bAddr['postal_code'] ?? ''));
            $bc = $doc->createElement('cac:Country');
            $bc->appendChild($doc->createElement('cbc:IdentificationCode',$bAddr['country'] ?? 'SA'));
            $breg->appendChild($bc);
            $cts->appendChild($breg);

            $ctsTs = $doc->createElement('cac:TaxScheme');
            $ctsTs->appendChild($doc->createElement('cbc:ID','VAT'));
            $cts->appendChild($ctsTs);

            $cp->appendChild($cts);
            $cus->appendChild($cp);
            $inv->appendChild($cus);
        }

        // Lines
        $netTotal = 0.0;
        foreach ($this->m->items as $i => $it) {
            $qty = (float)$it['qty'];
            $price = (float)$it['price'];
            $lineNet = $qty * $price;
            $netTotal += $lineNet;

            $lineVat = $lineNet * ((float)$it['vat_rate'] / 100);
            $lineGross = $lineNet + $lineVat;

            $line = $doc->createElement('cac:InvoiceLine');
            $line->appendChild($doc->createElement('cbc:ID',(string)($i+1)));

            $q = $doc->createElement('cbc:InvoicedQuantity', (string)$qty);
            $q->setAttribute('unitCode', $it['unit_code'] ?? 'EA');
            $line->appendChild($q);

            $le = $doc->createElement('cbc:LineExtensionAmount', NumberHelper::money($lineNet));
            $le->setAttribute('currencyID', $currency);
            $line->appendChild($le);

            // KSA-12 (line amount with VAT)
            $ti = $doc->createElement('cbc:TaxInclusiveAmount', NumberHelper::money($lineGross));
            $ti->setAttribute('currencyID', $currency);
            $line->appendChild($ti);

            $itemEl = $doc->createElement('cac:Item');
            $itemEl->appendChild($doc->createElement('cbc:Name', $it['name']));

            $ctc = $doc->createElement('cac:ClassifiedTaxCategory');
            $ctc->appendChild($doc->createElement('cbc:ID', $it['vat_category'] ?? 'S'));
            $ctc->appendChild($doc->createElement('cbc:Percent', (string)$it['vat_rate']));
            $ctcTs = $doc->createElement('cac:TaxScheme');
            $ctcTs->appendChild($doc->createElement('cbc:ID','VAT'));
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

        $vatTotal = $netTotal * ($taxRate/100);
        $grossTotal = $netTotal + $vatTotal;

        // TaxTotal (single, document-level)
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxAmount = $doc->createElement('cbc:TaxAmount', NumberHelper::money($vatTotal));
        $taxAmount->setAttribute('currencyID',$currency);
        $taxTotal->appendChild($taxAmount);

        // Add subtotal breakdown (recommended)
        $sub = $doc->createElement('cac:TaxSubtotal');
        $taxable = $doc->createElement('cbc:TaxableAmount', NumberHelper::money($netTotal));
        $taxable->setAttribute('currencyID',$currency);
        $sub->appendChild($taxable);
        $subTax = $doc->createElement('cbc:TaxAmount', NumberHelper::money($vatTotal));
        $subTax->setAttribute('currencyID',$currency);
        $sub->appendChild($subTax);

        $cat = $doc->createElement('cac:TaxCategory');
        $cat->appendChild($doc->createElement('cbc:ID','S'));
        $cat->appendChild($doc->createElement('cbc:Percent',(string)$taxRate));
        $catTs = $doc->createElement('cac:TaxScheme');
        $catTs->appendChild($doc->createElement('cbc:ID','VAT'));
        $cat->appendChild($catTs);
        $sub->appendChild($cat);
        $taxTotal->appendChild($sub);
        $inv->appendChild($taxTotal);

        // LegalMonetaryTotal (ordered to satisfy UBL XSD)
        $legal = $doc->createElement('cac:LegalMonetaryTotal');

        $leTot = $doc->createElement('cbc:LineExtensionAmount', NumberHelper::money($netTotal));
        $leTot->setAttribute('currencyID',$currency);
        $legal->appendChild($leTot);

        $te = $doc->createElement('cbc:TaxExclusiveAmount', NumberHelper::money($netTotal));
        $te->setAttribute('currencyID',$currency);
        $legal->appendChild($te);

        $tiTot = $doc->createElement('cbc:TaxInclusiveAmount', NumberHelper::money($grossTotal));
        $tiTot->setAttribute('currencyID',$currency);
        $legal->appendChild($tiTot);

        $pay = $doc->createElement('cbc:PayableAmount', NumberHelper::money($grossTotal));
        $pay->setAttribute('currencyID',$currency);
        $legal->appendChild($pay);

        $inv->appendChild($legal);

        // AdditionalDocumentReference placeholders (ICV / PIH) Phase 2 ready
        $adr1 = $doc->createElement('cac:AdditionalDocumentReference');
        $adr1->appendChild($doc->createElement('cbc:ID','ICV'));
        $adr1->appendChild($doc->createElement('cbc:UUID',$this->m->icv));
        $inv->appendChild($adr1);

        if ($this->m->previousHash) {
            $adr2 = $doc->createElement('cac:AdditionalDocumentReference');
            $adr2->appendChild($doc->createElement('cbc:ID','PIH'));
            $att = $doc->createElement('cac:Attachment');
            $bin = $doc->createElement('cbc:EmbeddedDocumentBinaryObject', base64_encode(hex2bin($this->m->previousHash)));
            $bin->setAttribute('mimeCode','text/plain');
            $att->appendChild($bin);
            $adr2->appendChild($att);
            $inv->appendChild($adr2);
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

    public function qrBase64(array $overrides = []): string
    {
        $seller = $this->ctx->seller();
        $currency = $this->ctx->currency();
        $taxRate = $this->ctx->taxRate();

        // Recompute totals quickly from model to avoid parsing XML
        $net = 0.0;
        foreach ($this->m->items as $it) $net += (float)$it['qty'] * (float)$it['price'];
        $vat = $net * ($taxRate/100);
        $gross = $net + $vat;

        $data = array_merge([
            'seller_name' => $seller['name'] ?? '',
            'seller_vat'  => $seller['vat'] ?? '',
            'timestamp'   => $this->m->issueDate . 'T' . $this->m->issueTime,
            'total'       => NumberHelper::money($gross),
            'vat_total'   => NumberHelper::money($vat),
            'invoice_hash'=> Sha256::hashBase64($this->canonicalXml()),
        ], $overrides);

        return QrGenerator::generateBase64($data);
    }
}
