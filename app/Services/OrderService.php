<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Str;

class OrderService
{
    public function create(array $attributes = []): Order
    {
        return Order::create([
            'id' => (string) Str::uuid(),
            'status_id' => OrderStatus::PENDING_STOCK->value,
            ...$attributes,
        ]);
    }

    public function reject(string $orderId, ?string $reason = null): void
    {
        Order::query()
            ->where('id', $orderId)
            ->where('status_id', OrderStatus::PENDING_STOCK->value)
            ->update([
            'status_id' => OrderStatus::FAILED_STOCK->value,
            'failure_reason' => $reason,
        ]);
    }

    public function markStockConfirmed(string $orderId, float $total): void
    {
        Order::query()
            ->where('id', $orderId)
            ->where('status_id', OrderStatus::PENDING_STOCK->value)
            ->update([
                'status_id' => OrderStatus::STOCK_CONFIRMED->value,
                'total' => $total,
            ]);
    }

    public function markPendingPayment(string $orderId): void
    {
        Order::query()
            ->where('id', $orderId)
            ->where('status_id', OrderStatus::STOCK_CONFIRMED->value)
            ->update([
                'status_id' => OrderStatus::PENDING_PAYMENT->value,
            ]);
    }

    public function confirm(string $orderId): void
    {
        Order::query()
            ->where('id', $orderId)
            ->where('status_id', OrderStatus::PENDING_PAYMENT->value)
            ->update([
                'status_id' => OrderStatus::PAID->value,
            ]);
    }

    public function fail(string $orderId, ?string $reason = null): void
    {
        Order::query()
            ->where('id', $orderId)
            ->where('status_id', OrderStatus::PENDING_PAYMENT->value)
            ->update([
                'status_id' => OrderStatus::FAILED_PAYMENT->value,
                'failure_reason' => $reason,
            ]);
    }
}
