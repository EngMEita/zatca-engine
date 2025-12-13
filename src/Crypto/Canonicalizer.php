<?php
namespace Meita\ZatcaEngine\Crypto;

final class Canonicalizer
{
    public static function c14n(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);
        // Exclusive canonicalization without comments
        return $dom->C14N(true, false);
    }
}
