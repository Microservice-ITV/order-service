<?php

namespace App\Services;

use App\Logging\LogMessage;
use App\Enums\OrderStatus;
use App\Models\CheckoutSession;
use App\Models\Order;
use Illuminate\Support\Str;

class CheckoutService
{
     public function create(array $attributes): CheckoutSession
    {
        $checkout = CheckoutSession::create([
            'id'        => (string) Str::uuid(),
            'status_id' => OrderStatus::PENDING_STOCK,
            ...$attributes,
        ]);

        logger()->info(LogMessage::CHECKOUT_CREATED, [
            'checkout_id'    => $checkout->id,
            'correlation_id' => $checkout->correlation_id,
        ]);

        return $checkout;
    }

    public function reject(string $checkoutId, ?string $reason = null): bool
    {
        $updated = CheckoutSession::query()
            ->where('id', $checkoutId)
            ->where('status_id', OrderStatus::PENDING_STOCK->value)
            ->update([
                'status_id'      => OrderStatus::FAILED_STOCK->value,
                'failure_reason' => $reason,
                'updated_at'     => now(),
            ]);

            if ($updated === 1) {
                logger()->info(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                    'checkout_id' => $checkoutId,
                    'reason'      => $reason,
                ]);
                return true;
            }
    
            logger()->warning(LogMessage::CHECKOUT_REJECT_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
            ]);
    
            return false;
    }


    public function markStockConfirmed(string $checkoutId, float $total, string $reservationId): bool
    {
        $updated = CheckoutSession::query()
            ->where('id', $checkoutId)
            ->where('status_id', OrderStatus::PENDING_STOCK->value)
            ->update([
                'status_id' => OrderStatus::STOCK_CONFIRMED->value,
                'total' => $total,
                'reservation_id' => $reservationId,
                'updated_at'    => now(),
            ]);

            if ($updated === 1) {
                logger()->info(LogMessage::CHECKOUT_STOCK_CONFIRMED, [
                    'checkout_id'    => $checkoutId,
                    'total'          => $total,
                    'reservation_id' => $reservationId,
                ]);
                return true;
            }
    
            logger()->debug(LogMessage::CHECKOUT_STATE_STOCK_IGNORED, [
                'checkout_id' => $checkoutId,
                'from'        => OrderStatus::PENDING_STOCK->name,
                'to'          => OrderStatus::STOCK_CONFIRMED->name,
            ]);
    
            return false;
    }

    public function markPendingPayment(string $checkoutId): bool
    {
        $updated = CheckoutSession::query()
            ->where('id', $checkoutId)
            ->where('status_id', OrderStatus::STOCK_CONFIRMED->value)
            ->update([
                'status_id' => OrderStatus::PENDING_PAYMENT->value,
                'updated_at' => now(),
            ]);

            if ($updated === 1) {
                logger()->info(LogMessage::CHECKOUT_MOVED_TO_PENDING_PAYMENT, [
                    'checkout_id' => $checkoutId,
                ]);
            }
    
            return $updated === 1;
    }

    public function confirm(string $checkoutId): bool
    {
        $updated = CheckoutSession::query()
            ->where('id', $checkoutId)
            ->where('status_id', OrderStatus::PENDING_PAYMENT->value)
            ->update([
                'status_id' => OrderStatus::PAID->value,
                'updated_at' => now(),
            ]);

            if ($updated === 1) {
                logger()->info(LogMessage::CHECKOUT_PAYMENT_CONFIRMED, [
                    'checkout_id' => $checkoutId,
                ]);
            }
    
            return $updated === 1;
    }

    public function createFromCheckout( string $checkoutId, string $correlationId, ?string $reservationId = null): Order 
    {
        return Order::create([
            'order_id'       => (string) Str::uuid(),
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
            'status_id'      => OrderStatus::CONFIRMED->value,
            'correlation_id' => $correlationId,
        ]);
    }

    public function fail(string $checkoutId, ?string $reason = null): bool
    {
        $updated = CheckoutSession::query()
            ->where('id', $checkoutId)
            ->where('status_id', OrderStatus::PENDING_PAYMENT->value)
            ->update([
                'status_id' => OrderStatus::FAILED_PAYMENT->value,
                'failure_reason' => $reason,
                'updated_at'     => now(),
            ]);

            if ($updated === 1) {
                logger()->info(LogMessage::CHECKOUT_PAYMENT_FAILED, [
                    'checkout_id' => $checkoutId,
                    'reason'      => $reason,
                ]);
            }
    
            return $updated === 1;
    }
}
