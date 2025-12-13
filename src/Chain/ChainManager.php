<?php
namespace Meita\ZatcaEngine\Chain;

final class ChainManager
{
    /**
     * Computes next ICV and holds previous invoice hash.
     * In a real system you would persist these values per company/environment.
     */
    public function __construct(private int $icv = 1, private ?string $previousHash = null) {}

    public function icv(): string { return (string)$this->icv; }
    public function previousHash(): ?string { return $this->previousHash; }

    public function advance(string $currentInvoiceHashHex): void
    {
        $this->previousHash = $currentInvoiceHashHex;
        $this->icv++;
    }
}
