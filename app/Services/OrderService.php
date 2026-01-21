<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Str;

class OrderService
{
    public function create(): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'status' => OrderStatus::CREATED
        ]);
    }

    public function reject(string $orderId): void
    {
        Order::where('id', $orderId)
            ->where('status', OrderStatus::CREATED)
            ->update(['status' => OrderStatus::REJECTED]);
    }

    public function markPendingPayment(string $orderId, float $total): void
    {
        Order::where('id', $orderId)
            ->where('status', OrderStatus::CREATED)
            ->update([
                'status' => OrderStatus::PENDING_PAYMENT,
                'total' => $total
            ]);
    }

    public function confirm(string $orderId): void
    {
        Order::where('id', $orderId)
            ->where('status', OrderStatus::PENDING_PAYMENT)
            ->update(['status' => OrderStatus::CONFIRMED]);
    }

    public function fail(string $orderId): void
    {
        Order::where('id', $orderId)
            ->update(['status' => OrderStatus::FAILED]);
    }
}
