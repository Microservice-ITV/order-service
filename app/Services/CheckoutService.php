<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CheckoutSession;
use Illuminate\Support\Str;

class CheckoutService
{
    public function create(array $attributes = []): CheckoutSession
    {
        return CheckoutSession::create([
            'id' => (string) Str::uuid(),
            'status_id' => OrderStatus::PENDING_STOCK->value,
            ...$attributes,
        ]);
    }

    public function reject(string $checkoutId, ?string $reason = null): void
    {
        CheckoutSession::query()
            ->where('id', '=', $checkoutId)
            ->where('status_id', '=', OrderStatus::PENDING_STOCK->value)
            ->update([
                'status_id' => OrderStatus::FAILED_STOCK->value,
                'failure_reason' => $reason,
            ]);
    }

    public function markStockConfirmed(string $checkoutId, float $total, string $reservation_id): bool
    {
        $updated = CheckoutSession::query()
            ->where('id', '=', $checkoutId)
            ->where('status_id', '=', OrderStatus::PENDING_STOCK->value)
            ->update([
                'status_id' => OrderStatus::STOCK_CONFIRMED->value,
                'total' => $total,
                'reservation_id' => $reservation_id,
                'updated_at'    => now(),
            ]);

            return $updated === 1;
    }

    public function markPendingPayment(string $checkoutId): void
    {
        CheckoutSession::query()
            ->where('id', '=', $checkoutId)
            ->where('status_id', '=', OrderStatus::STOCK_CONFIRMED->value)
            ->update([
                'status_id' => OrderStatus::PENDING_PAYMENT->value,
            ]);
    }

    public function confirm(string $checkoutId): void
    {
        CheckoutSession::query()
            ->where('id', '=', $checkoutId)
            ->where('status_id', '=', OrderStatus::PENDING_PAYMENT->value)
            ->update([
                'status_id' => OrderStatus::PAID->value,
            ]);
    }

    public function fail(string $checkoutId, ?string $reason = null): void
    {
        CheckoutSession::query()
            ->where('id', '=', $checkoutId)
            ->where('status_id', '=', OrderStatus::PENDING_PAYMENT->value)
            ->update([
                'status_id' => OrderStatus::FAILED_PAYMENT->value,
                'failure_reason' => $reason,
            ]);
    }
}
