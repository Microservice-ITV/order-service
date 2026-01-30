<?php

namespace App\Services;

use App\Logging\LogMessage;
use App\Enums\OrderStatus;
use App\Models\CheckoutSession;
use App\Models\Order;
use Illuminate\Support\Str;

class OrderService
{
    public function createOrder( string $checkoutId, string $correlationId, ?string $reservationId = null): Order 
    {
        return Order::create([
            'order_id'       => (string) Str::uuid(),
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
            'status_id'      => OrderStatus::CONFIRMED->value,
            'correlation_id' => $correlationId,
        ]);
    }
    public function attachPayment( string $checkoutId, string $paymentId): bool {
        return Order::query()
            ->where('checkout_id', $checkoutId)
            ->update([
                'payment_id' => $paymentId,
                'updated_at' => now(),
            ]) === 1;
    }

    public function cancel(string $checkoutId): bool
    {
        return Order::query()
            ->where('checkout_id', $checkoutId)
            ->update([
                'status_id'  => OrderStatus::CANCELLED->value,
                'updated_at' => now(),
            ]) === 1;
    }
}
