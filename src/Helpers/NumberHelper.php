<?php
namespace Meita\ZatcaEngine\Helpers;

final class NumberHelper
{
    public static function money(float $value): string
    {
        // Always 2 decimals, dot separator
        return number_format($value + 0.0000001, 2, '.', '');
    }

    public static function round2(float $value): float
    {
        return round($value, 2);
    }
}
