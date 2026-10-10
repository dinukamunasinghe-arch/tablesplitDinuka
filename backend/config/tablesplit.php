<?php

return [

    'final_decimal_places' => 0,

    'service_charge_rate' => '0.10',

    'vat_rate' => '0.18',

    'categories' => [
        'food' => ['service_charge' => true],
        'beverage' => ['service_charge' => true],
        'alcohol' => ['service_charge' => false],
    ],

    'discounts' => [
        'HAPPY20' => [
            'type' => 'percent_before_tax',
            'rate' => '0.20',
            'minimum_subtotal' => '0',
            'categories' => ['beverage'],
        ],
        'TABLE500' => [
            'type' => 'fixed_before_tax',
            'amount' => '500',
            'minimum_subtotal' => '5000',
            'categories' => ['food', 'beverage', 'alcohol'],
        ],
    ],

    'kitchen' => [
        'priorities' => [
            'VIP' => 30,
            'first' => 20,
        ],
        'default_rank' => 10,
        'policy' => 'One cook works one ticket at a time. The clock starts at now and moves forward by that ticket\'s prep time. At each pick, only tickets already created are waiting. Higher priority is cooked first (VIP, then first, then normal). Ties use earlier creation, then shorter prep, then ticket id.',
    ],

];
