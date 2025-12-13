<?php
namespace Meita\ZatcaEngine\Parties;

final class Address
{
    public function __construct(
        public readonly string $street,
        public readonly string $buildingNo,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $country = 'SA'
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            street: (string)($a['street'] ?? ''),
            buildingNo: (string)($a['building_no'] ?? ''),
            city: (string)($a['city'] ?? ''),
            postalCode: (string)($a['postal_code'] ?? ''),
            country: (string)($a['country'] ?? 'SA'),
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'building_no' => $this->buildingNo,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];
    }
}
