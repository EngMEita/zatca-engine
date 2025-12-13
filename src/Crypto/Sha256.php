<?php
namespace Meita\ZatcaEngine\Crypto;

final class Sha256
{
    public static function hashHex(string $data): string
    {
        return hash('sha256', $data);
    }

    public static function hashBase64(string $data): string
    {
        return base64_encode(hash('sha256', $data, true));
    }
}
