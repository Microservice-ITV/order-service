<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Pickup Address
    |--------------------------------------------------------------------------
    | Used in shipment.created event when no warehouse/seller address is stored.
    | Set to null to omit pickup_address from the payload.
    */
    'default_pickup_address' => [
        'full_name' => env('SHIPPING_PICKUP_NAME'),
        'phone_no' => env('SHIPPING_PICKUP_PHONE'),
        'address_line_1' => env('SHIPPING_PICKUP_ADDRESS_LINE_1'),
        'city' => env('SHIPPING_PICKUP_CITY'),
        'country' => env('SHIPPING_PICKUP_COUNTRY'),
        'country_code' => env('SHIPPING_PICKUP_COUNTRY_CODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-generate tracking number
    |--------------------------------------------------------------------------
    | When true, generates a tracking number if not provided in the request.
    */
    'auto_generate_tracking' => env('SHIPPING_AUTO_GENERATE_TRACKING', true),
];
