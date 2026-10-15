<?php

return [
    /**
     * Temporary platform commission rate for payment allocations and wallet settlement.
     * Shops receive their share of (item_subtotal - commission) plus per-shop shipping.
     */
    'platform_commission_rate' => (float) env('ORDER_PLATFORM_COMMISSION_RATE', 0.0),
];
