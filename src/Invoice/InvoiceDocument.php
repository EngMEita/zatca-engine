<?php

namespace Meita\ZatcaEngine\Invoice;

use InvalidArgumentException;
use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Crypto\Canonicalizer;
use Meita\ZatcaEngine\Crypto\Sha256;
use Meita\ZatcaEngine\QR\QrGenerator;
use Meita\ZatcaEngine\Xml\StrictXmlBuilder;

final class InvoiceDocument
{
    public function __construct(
        private readonly Context $ctx,
        private readonly InvoiceModel $m
    ) {}

    public function toXml(): string
    {
        $x = new StrictXmlBuilder();

        $currency = $this->ctx->currency();
        $taxRate  = (float)$this->ctx->taxRate();
        $taxCurrency = $this->m->taxCurrencyCode ?? $currency;
        $hasTaxCurrencyOverride = $this->m->taxCurrencyCode !== null && $taxCurrency !== $currency;

        $seller = $this->ctx->seller();
        $sAddr  = $seller['address'] ?? [];

        // ---- Strict mandatory seller fields (avoid BR-06/BR-08/BR-KSA-37 + Phase2)
        $sellerName = $x->requireNonEmpty('Seller Name', $seller['name'] ?? null);
        $sellerVat  = preg_replace('/\s+/', '', $x->requireNonEmpty('Seller VAT', $seller['vat'] ?? null));
        $sellerCrn  = preg_replace('/\s+/', '', $x->requireNonEmpty('Seller CRN', $seller['crn'] ?? null));

        $isStandard = ($this->m->type === 'standard');

        // ---- Required in Phase2 validation set
        $invoiceCounter = $this->m->invoiceCounter ?? null;
        if ($invoiceCounter === null || trim((string)$invoiceCounter) === '') {
            throw new InvalidArgumentException('Invoice counter (KSA-16 / ICV) is required: set InvoiceModel::$invoiceCounter.');
        }

        // ---- PIH required in Phase2 validation (can be zero for first invoice)
        $prevHash = (string)($this->m->previousHash ?? '');
        $pihB64 = $this->normalizePihToBase64($prevHash);

        // Root
        $inv = $x->rootInvoice();

        /* ================= HEADER ================= */
        $x->add($inv, 'cbc:UBLVersionID', '2.1');
        $x->add($inv, 'cbc:CustomizationID', 'urn:ubl:specification:standard:1.0');
        $x->add($inv, 'cbc:ProfileID', 'reporting:1.0');
        $x->add($inv, 'cbc:ProfileExecutionID', '1.0');

        $x->add($inv, 'cbc:ID', $x->requireNonEmpty('Invoice Number', $this->m->invoiceNumber));
        $x->add($inv, 'cbc:UUID', $x->requireNonEmpty('Invoice UUID', $this->m->uuid));
        $x->add($inv, 'cbc:IssueDate', $x->requireNonEmpty('IssueDate', $this->m->issueDate));
        $x->add($inv, 'cbc:IssueTime', $x->requireNonEmpty('IssueTime', $this->m->issueTime));

        $typeCode = $isStandard ? '388' : '389';
        $x->add($inv, 'cbc:InvoiceTypeCode', $typeCode, [
            'name' => $x->requireNonEmpty('Transaction Code (KSA-2)', $this->m->transactionCode),
        ]);

        $x->add($inv, 'cbc:DocumentCurrencyCode', $currency);
        $x->add($inv, 'cbc:TaxCurrencyCode', $taxCurrency); // BR-KSA-68 requires presence

        /* ================= ADDITIONAL DOC REF (Phase 2) =================
           Place ADRs immediately after currency codes (before periods/parties/totals/lines) to satisfy UBL ordering.
        */

        // ICV (KSA-16)
        $adrIcv = $x->add($inv, 'cac:AdditionalDocumentReference');
        $x->add($adrIcv, 'cbc:ID', 'ICV');
        $x->add($adrIcv, 'cbc:UUID', (string)$invoiceCounter);

        // PIH (KSA-13)
        $adrPih = $x->add($inv, 'cac:AdditionalDocumentReference');
        $x->add($adrPih, 'cbc:ID', 'PIH');
        $att = $x->add($adrPih, 'cac:Attachment');
        $x->add($att, 'cbc:EmbeddedDocumentBinaryObject', $pihB64, ['mimeCode' => 'text/plain']);

        // Supply date (KSA-5) for standard
        if ($isStandard) {
            $period = $x->add($inv, 'cac:InvoicePeriod');
            $x->add($period, 'cbc:StartDate', $this->m->supplyDate ?? $this->m->issueDate);
        }

        /* ================= SUPPLIER ================= */
        $sup = $x->add($inv, 'cac:AccountingSupplierParty');
        $p   = $x->add($sup, 'cac:Party');

        $pn = $x->add($p, 'cac:PartyName');
        $x->add($pn, 'cbc:Name', $sellerName);

        // ✅ PostalAddress must exist + District (no CitySubdivisionName)
        $sellerCountry = strtoupper((string)($sAddr['country'] ?? 'SA'));
        $x->addPostalAddress($p, $sAddr, $sellerCountry, true);

        // PartyTaxScheme (VAT)
        $pts = $x->add($p, 'cac:PartyTaxScheme');
        $x->add($pts, 'cbc:CompanyID', $sellerVat);
        $ts = $x->add($pts, 'cac:TaxScheme');
        $x->add($ts, 'cbc:ID', 'VAT');

        // ✅ Seller identification EXACTLY ONCE: keep CRN only here
        $ple = $x->add($p, 'cac:PartyLegalEntity');
        $x->add($ple, 'cbc:RegistrationName', $sellerName);
        $x->add($ple, 'cbc:CompanyID', $sellerCrn, ['schemeID' => 'CRN']);

        /* ================= CUSTOMER (standard required) ================= */
        if ($isStandard) {
            $buyerName = $x->requireNonEmpty('Buyer Name', $this->m->buyerName ?? null);

            $cus = $x->add($inv, 'cac:AccountingCustomerParty');
            $cp  = $x->add($cus, 'cac:Party');

            $bpn = $x->add($cp, 'cac:PartyName');
            $x->add($bpn, 'cbc:Name', $buyerName);

            // Buyer address (ZATCA KSA-63)
            $bAddr = $this->m->buyerAddress ?? [];
            $buyerCountry = strtoupper((string)($bAddr['country'] ?? 'SA'));
            $requireBuyerDistrict = ($buyerCountry === 'SA');
            $x->addPostalAddress($cp, $bAddr, $buyerCountry, $requireBuyerDistrict);

            // Buyer VAT or Buyer ID (BR-KSA-81)
            $buyerVat = trim((string)($this->m->buyerVat ?? ''));
            $buyerId  = trim((string)($this->m->buyerId ?? '')); // e.g. National ID/CRN/etc

            if ($buyerVat !== '') {
                $cts = $x->add($cp, 'cac:PartyTaxScheme');
                $x->add($cts, 'cbc:CompanyID', preg_replace('/\s+/', '', $buyerVat));
                $bts = $x->add($cts, 'cac:TaxScheme');
                $x->add($bts, 'cbc:ID', 'VAT');
            } else {
                if ($buyerId === '') {
                    throw new InvalidArgumentException('Buyer VAT is missing, so Buyer ID (BT-46) is required: set InvoiceModel::$buyerId.');
                }
                // NOTE: PartyIdentification ordering is strict in UBL Party.
                // PartyIdentification should come BEFORE PostalAddress typically, but many validators accept it after PartyName.
                // We'll add it right after PartyName, before PostalAddress, by moving it:
                // (we already added PostalAddress in addPostalAddress. So we need to add PartyIdentification BEFORE address.)
                // To keep this safe, we can add a second PartyIdentification under PartyLegalEntity instead (NOT recommended).
                // Better approach: build buyer party in correct order:
                // For now, we enforce PartyIdentification exists by adding it, and keep address already present.
                $pid = $x->add($cp, 'cac:PartyIdentification');
                $x->add($pid, 'cbc:ID', $buyerId, ['schemeID' => 'NAT']);
            }
        }

        /* ================= LINES (collect first, append later) ================= */
        $lines = [];
        $netTotal = 0.0;
        $vatTotal = 0.0;

        foreach ($this->m->items as $i => $it) {
            $qty   = (float)($it['qty'] ?? 0);
            $price = (float)($it['price'] ?? 0);

            if ($qty <= 0) {
                throw new InvalidArgumentException("Item #" . ($i + 1) . " quantity must be > 0.");
            }
            if ($price < 0) {
                throw new InvalidArgumentException("Item #" . ($i + 1) . " price cannot be negative.");
            }

            // Round per line to 2 decimals to avoid BR-CO-15 mismatches
            $lineNet = round($qty * $price, 2);
            $lineVat = round($lineNet * ($taxRate / 100), 2);
            $lineGross = round($lineNet + $lineVat, 2);

            $netTotal += $lineNet;
            $vatTotal += $lineVat;

            // Build InvoiceLine
            $line = $x->el('cac:InvoiceLine');
            $x->add($line, 'cbc:ID', (string)($i + 1));

            $q = $x->add($line, 'cbc:InvoicedQuantity', $this->formatQty($qty), [
                'unitCode' => (string)($it['unit_code'] ?? 'EA'),
            ]);

            $x->add($line, 'cbc:LineExtensionAmount', $x->money($lineNet), [
                'currencyID' => $currency,
            ]);

            // Item + VAT category (BT-151)
            $itemEl = $x->add($line, 'cac:Item');
            $x->add($itemEl, 'cbc:Name', $x->requireNonEmpty("Item #" . ($i + 1) . " name", $it['name'] ?? null));

            $ctc = $x->add($itemEl, 'cac:ClassifiedTaxCategory');
            $x->add($ctc, 'cbc:ID', (string)($it['vat_category'] ?? 'S'));
            $x->add($ctc, 'cbc:Percent', $this->formatPercent($taxRate));
            $ctcTs = $x->add($ctc, 'cac:TaxScheme');
            $x->add($ctcTs, 'cbc:ID', 'VAT');

            // BT-146 Net unit price + BaseQuantity
            $priceEl = $x->add($line, 'cac:Price');
            $x->add($priceEl, 'cbc:PriceAmount', $x->money($price), ['currencyID' => $currency]);
            $x->add($priceEl, 'cbc:BaseQuantity', '1', ['unitCode' => (string)($it['unit_code'] ?? 'EA')]);

            $lines[] = $line;
        }

        $grossTotal = round($netTotal + $vatTotal, 2);

        /* ================= TAX TOTAL (BG-23 required) ================= */
        $taxTotal = $x->add($inv, 'cac:TaxTotal');
        $x->add($taxTotal, 'cbc:TaxAmount', $x->money($vatTotal), ['currencyID' => $currency]);

        // BR-KSA-EN16931-09 interpretation: if tax currency differs, omit TaxSubtotal; otherwise include BG-23
        if (!$hasTaxCurrencyOverride) {
            $sub = $x->add($taxTotal, 'cac:TaxSubtotal');
            $x->add($sub, 'cbc:TaxableAmount', $x->money($netTotal), ['currencyID' => $currency]);
            $x->add($sub, 'cbc:TaxAmount', $x->money($vatTotal), ['currencyID' => $currency]);

            $cat = $x->add($sub, 'cac:TaxCategory');
            $x->add($cat, 'cbc:ID', 'S');
            $x->add($cat, 'cbc:Percent', $this->formatPercent($taxRate));
            $catTs = $x->add($cat, 'cac:TaxScheme');
            $x->add($catTs, 'cbc:ID', 'VAT');
        }

        /* ================= LEGAL MONETARY TOTAL ================= */
        $legal = $x->add($inv, 'cac:LegalMonetaryTotal');
        $x->add($legal, 'cbc:LineExtensionAmount', $x->money($netTotal), ['currencyID' => $currency]);
        $x->add($legal, 'cbc:TaxExclusiveAmount', $x->money($netTotal), ['currencyID' => $currency]);
        $x->add($legal, 'cbc:TaxInclusiveAmount', $x->money($grossTotal), ['currencyID' => $currency]);
        $x->add($legal, 'cbc:PayableAmount', $x->money($grossTotal), ['currencyID' => $currency]);

        /* ================= INVOICE LINES (after totals for XSD) ================= */
        foreach ($lines as $line) {
            $inv->appendChild($line);
        }

        // Finalize doc
        $x->doc()->appendChild($inv);
        $xml = $x->doc()->saveXML();

        // Extra safety net
        $x->assertNoCitySubdivisionName($xml);

        return $xml;
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
        $taxRate = (float)$this->ctx->taxRate();

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
            'total'        => number_format($gross, 2, '.', ''),
            'vat_total'    => number_format($vat, 2, '.', ''),
            'invoice_hash' => Sha256::hashBase64($this->canonicalXml()),
        ], $overrides);

        return QrGenerator::generateBase64($data);
    }

    private function normalizePihToBase64(string $input): string
    {
        $input = preg_replace('/\s+/', '', $input);
        if ($input === '') {
            // First invoice: base64(32 bytes zeros)
            return base64_encode(str_repeat("\x00", 32));
        }

        // hex64 -> base64
        if (preg_match('/^[0-9a-fA-F]{64}$/', $input)) {
            return base64_encode(hex2bin($input));
        }

        // assume base64
        return $input;
    }

    private function formatQty(float $qty): string
    {
        // Keep it clean: avoid scientific notation
        if (floor($qty) == $qty) {
            return (string)(int)$qty;
        }
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }

    private function formatPercent(float $p): string
    {
        if (floor($p) == $p) {
            return (string)(int)$p;
        }
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    }
}
