<?php
namespace Meita\ZatcaEngine\Helpers;

final class DateHelper
{
    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function nowTime(): string
    {
        return date('H:i:s');
    }
}
