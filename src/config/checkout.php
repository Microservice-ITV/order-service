<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Checkout Expiration (Minutes)
    |--------------------------------------------------------------------------
    |
    | Determines how long a checkout session remains valid before it expires.
    | This is used to populate the `expire_at` field on checkout sessions and
    | to propagate the value to downstream services via events.
    |
    */
    'expire_after_minutes' => (int) env('CHECKOUT_EXPIRE_AFTER_MINUTES', 15),
];

