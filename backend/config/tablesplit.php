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
        'TABLE500' => [
            'type' => 'fixed_before_tax',
            'amount' => '500',
            'minimum_subtotal' => '0',
            'categories' => ['food', 'beverage', 'alcohol'],
        ],
    ],

    'kitchen' => [
        'priorities' => [
            'VIP' => 30,
            'first' => 20,
        ],
        'default_rank' => 10,
        'policy' => 'Cook higher priority first (VIP, then first, then normal). When priority matches, cook the earlier ticket, then the shorter preparation, then the lower ticket id. The request now is the clock for waiting time and is not a sort key.',
    ],

];
