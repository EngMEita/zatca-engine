<?php
namespace Meita\ZatcaEngine\Core;

use InvalidArgumentException;

final class Context
{
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromArray(array $data): self
    {
        $data['company_key'] = $data['company_key'] ?? 'default';
        $data['currency'] = $data['currency'] ?? 'SAR';
        $data['tax_rate'] = $data['tax_rate'] ?? 15;

        if (!isset($data['seller']) || !is_array($data['seller'])) {
            throw new InvalidArgumentException("Context requires 'seller' array.");
        }

        return new self($data);
    }

    public function companyKey(): string
    {
        return (string)($this->data['company_key'] ?? 'default');
    }

    public function currency(): string
    {
        return (string)$this->data['currency'];
    }

    public function taxRate(): float
    {
        return (float)$this->data['tax_rate'];
    }

    public function seller(): array
    {
        return $this->data['seller'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->data;
    }
}
