<?php
namespace Meita\ZatcaEngine\Crypto;

final class EcdsaSigner
{
    /**
     * Signs a payload using OpenSSL. The key must be a valid EC private key (PEM).
     * NOTE: The exact key curve and signature formatting for ZATCA integration may
     * require additional processing in your Clearance client. This helper is a building block.
     */
    public static function signBase64(string $payload, string $privateKeyPem, string $algo = 'sha256'): string
    {
        $pkey = openssl_pkey_get_private($privateKeyPem);
        if (!$pkey) {
            throw new \RuntimeException('Invalid private key PEM.');
        }
        $sig = '';
        $ok = openssl_sign($payload, $sig, $pkey, $algo);
        openssl_pkey_free($pkey);
        if (!$ok) {
            throw new \RuntimeException('OpenSSL signing failed.');
        }
        return base64_encode($sig);
    }
}
