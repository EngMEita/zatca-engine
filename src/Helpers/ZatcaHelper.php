<?php
namespace Meita\ZatcaEngine\Helpers;

use Meita\ZatcaEngine\QR\TLV;

final class ZatcaHelper
{
    public static function uuid(): string { return UuidHelper::v4(); }
    public static function money(float $v): string { return NumberHelper::money($v); }
    public static function vat(float $net, float $ratePercent): float { return NumberHelper::round2($net * ($ratePercent/100)); }
    public static function gross(float $net, float $ratePercent): float { return NumberHelper::round2($net + self::vat($net, $ratePercent)); }

    public static function assertCurrency(string $currency): void
    {
        if ($currency !== 'SAR') {
            throw new \InvalidArgumentException("ZATCA currency must be SAR. Given: {$currency}");
        }
    }

    public static function assertBuildingNumber(string $buildingNo): void
    {
        if (!preg_match('/^\d{4}$/', $buildingNo)) {
            throw new \InvalidArgumentException("Building number must be exactly 4 digits.");
        }
    }

    public static function tlvBase64(array $tags): string
    {
        return base64_encode(TLV::encode($tags));
    }
}
