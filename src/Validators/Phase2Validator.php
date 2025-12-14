<?php
namespace Meita\ZatcaEngine\Validators;

use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Invoice\InvoiceModel;
use Meita\ZatcaEngine\Helpers\ZatcaHelper;

final class Phase2Validator
{
    public static function validate(Context $ctx, InvoiceModel $m): void
    {
        $errors = [];

        // Currency must be SAR
        try { ZatcaHelper::assertCurrency($ctx->currency()); }
        catch (\Throwable $e) { $errors[] = ['code'=>'KSA-CURRENCY', 'message'=>$e->getMessage(), 'field'=>'DocumentCurrencyCode']; }

        $seller = $ctx->seller();
        if (empty($seller['name'])) $errors[] = ['code'=>'BT-27', 'message'=>'Seller name is required.', 'field'=>'Seller.Name'];
        if (empty($seller['vat']))  $errors[] = ['code'=>'BT-31', 'message'=>'Seller VAT is required.', 'field'=>'Seller.VAT'];
        if (empty($seller['crn']))  $errors[] = ['code'=>'BT-29', 'message'=>'Seller CRN is required (schemeID=CRN).', 'field'=>'Seller.CRN'];

        $addr = $seller['address'] ?? [];
        if (empty($addr['street'])) $errors[] = ['code'=>'BT-35', 'message'=>'Seller street is required.', 'field'=>'Seller.Address.Street'];
        if (empty($addr['city']))   $errors[] = ['code'=>'BT-37', 'message'=>'Seller city is required.', 'field'=>'Seller.Address.City'];
        if (empty($addr['postal_code'])) $errors[] = ['code'=>'BT-38', 'message'=>'Seller postal code is required.', 'field'=>'Seller.Address.PostalZone'];
        if (empty($addr['district'])) $errors[] = ['code'=>'KSA-3', 'message'=>'Seller district is required.', 'field'=>'Seller.Address.District'];
        if (empty($addr['country'] ?? 'SA')) $errors[] = ['code'=>'BT-40', 'message'=>'Seller country code is required.', 'field'=>'Seller.Address.Country'];
        if (empty($addr['building_no'])) $errors[] = ['code'=>'BR-KSA-37', 'message'=>'Seller building number is required (4 digits).', 'field'=>'Seller.Address.BuildingNumber'];
        if (!empty($addr['building_no'])) {
            try { ZatcaHelper::assertBuildingNumber((string)$addr['building_no']); }
            catch (\Throwable $e) { $errors[] = ['code'=>'BR-KSA-37', 'message'=>$e->getMessage(), 'field'=>'Seller.Address.BuildingNumber']; }
        }

        if ($m->type === 'standard') {
            if (empty($m->buyerName)) $errors[] = ['code'=>'BT-44', 'message'=>'Buyer name is required for standard invoices.', 'field'=>'Buyer.Name'];
            // Buyer address minimal in KSA
            if (empty($m->buyerAddress['street'] ?? '')) $errors[] = ['code'=>'BT-50', 'message'=>'Buyer street is required for standard invoices.', 'field'=>'Buyer.Address.Street'];
            if (empty($m->buyerAddress['city'] ?? ''))   $errors[] = ['code'=>'BT-52', 'message'=>'Buyer city is required for standard invoices.', 'field'=>'Buyer.Address.City'];
            $buyerCountry = strtoupper((string)($m->buyerAddress['country'] ?? 'SA'));
            $isBuyerSa = ($buyerCountry === 'SA');
            if ($isBuyerSa && empty($m->buyerAddress['postal_code'] ?? '')) $errors[] = ['code'=>'BT-53', 'message'=>'Buyer postal code is required for standard invoices when country is SA.', 'field'=>'Buyer.Address.PostalZone'];
            if ($isBuyerSa && empty($m->buyerAddress['district'] ?? '')) $errors[] = ['code'=>'KSA-4', 'message'=>'Buyer district is required for standard invoices when country is SA.', 'field'=>'Buyer.Address.District'];
            if (empty($buyerCountry)) $errors[] = ['code'=>'BT-55', 'message'=>'Buyer country code is required for standard invoices.', 'field'=>'Buyer.Address.Country'];
            if (!empty($m->buyerAddress['building_no'] ?? '')) {
                try { ZatcaHelper::assertBuildingNumber((string)$m->buyerAddress['building_no']); }
                catch (\Throwable $e) { $errors[] = ['code'=>'BUYER-BUILD', 'message'=>$e->getMessage(), 'field'=>'Buyer.Address.BuildingNumber']; }
            } elseif ($isBuyerSa) {
                $errors[] = ['code'=>'Buyer-Building', 'message'=>'Buyer building number is required (4 digits) for standard invoices.', 'field'=>'Buyer.Address.BuildingNumber'];
            }
        }

        if (count($m->items) === 0) $errors[] = ['code'=>'BG-25', 'message'=>'At least one invoice line is required.', 'field'=>'InvoiceLine'];

        foreach ($m->items as $idx => $it) {
            $p = "InvoiceLine[".($idx+1)."]";
            if ($it['qty'] <= 0) $errors[] = ['code'=>'BT-129', 'message'=>'Quantity must be > 0.', 'field'=>$p.'.InvoicedQuantity'];
            if ($it['price'] < 0) $errors[] = ['code'=>'BT-146', 'message'=>'Unit price must be >= 0.', 'field'=>$p.'.PriceAmount'];
            if (empty($it['name'])) $errors[] = ['code'=>'BT-153', 'message'=>'Item name is required.', 'field'=>$p.'.Item.Name'];
        }

        if ($errors) {
            throw new Phase2ValidationException($errors);
        }
    }
}
