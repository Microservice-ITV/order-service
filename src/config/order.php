<?php

return [
    /**
     * Platform commission on item subtotal (excludes shipping) for wallet settlement.
     * Shops receive their share of (item_subtotal - commission) plus per-shop shipping.
     */
    'wallet_platform_commission_rate' => (float) env('ORDER_WALLET_PLATFORM_COMMISSION_RATE', 0.05),
];
