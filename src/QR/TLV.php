<?php
namespace Meita\ZatcaEngine\QR;

final class TLV
{
    /**
     * Encode TLV tags as binary string.
     * Input format: [ [tag(int), value(string)], ... ]
     */
    public static function encode(array $tags): string
    {
        $out = '';
        foreach ($tags as $pair) {
            [$tag, $value] = $pair;
            $value = (string)$value;
            $valBytes = $value; // UTF-8 string bytes
            $out .= chr((int)$tag);
            $out .= self::encodeLength(strlen($valBytes));
            $out .= $valBytes;
        }
        return $out;
    }

    private static function encodeLength(int $len): string
    {
        // ZATCA QR TLV uses a single byte length in most cases (< 256).
        if ($len < 0 || $len > 255) {
            throw new \InvalidArgumentException('TLV value too long (max 255 bytes per field in this implementation).');
        }
        return chr($len);
    }
}
