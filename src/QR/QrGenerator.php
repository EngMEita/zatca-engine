<?php
namespace Meita\ZatcaEngine\QR;

use Meita\ZatcaEngine\Helpers\ZatcaHelper;

final class QrGenerator
{
    /**
     * Phase 2 QR: common fields (seller name, VAT, timestamp, total, VAT amount, invoice hash, signature, public key)
     * Provide what you have; missing fields can be filled later in your clearance/reporting pipeline.
     */
    public static function generateBase64(array $data): string
    {
        $tags = [];
        // Tag 1-5 are required for simplified invoices (classic), Phase 2 adds more tags.
        $tags[] = [1, (string)($data['seller_name'] ?? '')];
        $tags[] = [2, (string)($data['seller_vat'] ?? '')];
        $tags[] = [3, (string)($data['timestamp'] ?? '')];
        $tags[] = [4, (string)($data['total'] ?? '')];
        $tags[] = [5, (string)($data['vat_total'] ?? '')];

        if (!empty($data['invoice_hash'])) $tags[] = [6, (string)$data['invoice_hash']];
        if (!empty($data['signature']))    $tags[] = [7, (string)$data['signature']];
        if (!empty($data['public_key']))   $tags[] = [8, (string)$data['public_key']];

        return ZatcaHelper::tlvBase64($tags);
    }
}
