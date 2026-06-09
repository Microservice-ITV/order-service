<?php

namespace App\Contracts;

use App\Models\Order;

interface OrderInterface extends BaseInterface
{
    public function createOrder(array $attributes): Order;

    public function findByCheckoutId(string $checkoutId): ?Order;

    public function updateByCheckoutId(string $checkoutId, array $data): int;

    public function updateByOrderId(string $orderId, array $data): int;
}
