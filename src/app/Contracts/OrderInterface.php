<?php

namespace App\Contracts;

use App\Models\Order;

interface OrderInterface extends BaseInterface
{
    public function createOrder(array $attributes): Order;

    public function findByCheckoutId(string $checkoutId): ?Order;

    public function updateByCheckoutId(string $checkoutId, array $data): int;

    public function updateByOrderId(string $orderId, array $data): int;

    /**
     * Update the order-level status without syncing shop_orders.
     * Used for per-shop intermediate states (PARTIALLY_FULFILLED,
     * FULFILLED).
     */
    public function updateOrderStatus(string $orderId, array $data): int;
}
