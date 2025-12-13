<?php

return [
    /**
     * Multi-company profiles.
     * You can keep multiple seller configurations here and select by company key at runtime.
     */
    'companies' => [
        'default' => [
            'currency' => 'SAR',
            'tax_rate' => 15,
            'seller' => [
                'name' => 'YOUR COMPANY',
                'vat'  => '3XXXXXXXXXXXXXX',
                'crn'  => 'XXXXXXXXXX',
                'address' => [
                    'street' => 'Street',
                    'building_no' => '1234',
                    'city' => 'Riyadh',
                    'postal_code' => '12345',
                    'country' => 'SA',
                ],
            ],
        ],
    ],
];
