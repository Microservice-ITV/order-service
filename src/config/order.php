<?php

return [
    /**
     * Temporary platform commission rate for payment allocations and wallet settlement.
     * Shops receive their share of (item_subtotal - commission) plus per-shop shipping.
     */
    'platform_commission_rate' => (float) env('ORDER_PLATFORM_COMMISSION_RATE', 0.0),
    // The business return window may be shortened by deployment config, but never exceeds one week.
    'return_window_days' => min(7, max(1, (int) env('ORDER_RETURN_WINDOW_DAYS', 7))),
];
