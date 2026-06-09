<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly OrderInterface $orders
    ) {}

    public function createOrder(string $checkoutId, string $correlationId, ?string $reservationId = null): Order
    {
        return $this->orders->createOrder([
            'order_id'       => (string) Str::uuid(),
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
            'status_id'      => OrderStatus::CONFIRMED->value,
            'correlation_id' => $correlationId,
        ]);
    }

    public function attachPayment(string $checkoutId, string $paymentId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'payment_id' => $paymentId,
            'updated_at' => now(),
        ]) === 1;
    }

    public function markPaid(string $checkoutId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'status_id' => OrderStatus::PAID->value,
            'updated_at' => now(),
        ]) === 1;
    }

    public function cancel(string $checkoutId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'status_id'  => OrderStatus::CANCELLED->value,
            'updated_at' => now(),
        ]) === 1;
    }
}
