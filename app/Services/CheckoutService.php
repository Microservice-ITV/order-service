<?php

namespace App\Services;

use App\Logging\LogMessage;
use App\Enums\OrderStatus;
use App\Contracts\CheckoutSessionInterface;
use App\Models\CheckoutSession;
use App\Models\Order;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CheckoutService
{
    public function __construct(
        private readonly CheckoutSessionInterface $checkoutSessions
    ) {}

    /**
     * Calculate the expiration datetime for a checkout session.
     * Uses the configured expiration minutes from config.
     */
    public function calculateExpireAt(): Carbon
    {
        return now()
            ->utc()
            ->addMinutes((int) config('checkout.expire_after_minutes', 15));
    }

    /**
     * Hash an idempotency key for secure storage.
     * Truncates keys longer than 255 characters and hashes using app key.
     */
    public function hashIdempotencyKey(string $rawKey): string
    {
        if (\strlen($rawKey) > 255) {
            $rawKey = substr($rawKey, 0, 255);
        }

        return hash_hmac('sha256', $rawKey, (string) config('app.key'));
    }

    /**
     * Find a checkout session by its idempotency key hash.
     * Returns the checkout session if found, null otherwise.
     */
    public function findByIdempotencyHash(string $hash): ?CheckoutSession
    {
        return $this->checkoutSessions->findByIdempotencyHash($hash);
    }

    public function findById(string $checkoutId): ?CheckoutSession
    {
        return $this->checkoutSessions->findById($checkoutId);
    }

    /**
     * Validate that a checkout session exists and is not expired.
     * Returns the checkout session if valid, null otherwise.
     */
    public function validateCheckout(string $checkoutId): ?CheckoutSession
    {
        /** @var ?CheckoutSession $checkout */
        $checkout = $this->checkoutSessions->findById($checkoutId);

        if (!$checkout) {
            return null;
        }

        if ($checkout->isExpired()) {
            logger()->warning(LogMessage::CHECKOUT_EXPIRED, [
                'checkout_id' => $checkoutId,
                'expire_at'   => $checkout->getExpireAtIso(),
            ]);
            return null;
        }

        return $checkout;
    }

    public function create(array $attributes): CheckoutSession
    {
        /** @var CheckoutSession $checkout */
        $checkout = $this->checkoutSessions->create([
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
        $updated = $this->checkoutSessions->updateWhere([
            ['id', '=', $checkoutId],
            ['status_id', '=', OrderStatus::PENDING_STOCK->value],
        ], [
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
        $updated = $this->checkoutSessions->updateWhere([
            ['id', '=', $checkoutId],
            ['status_id', '=', OrderStatus::PENDING_STOCK->value],
        ], [
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
        $updated = $this->checkoutSessions->updateWhere([
            ['id', '=', $checkoutId],
            ['status_id', '=', OrderStatus::STOCK_CONFIRMED->value],
        ], [
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
        $updated = $this->checkoutSessions->updateWhere([
            ['id', '=', $checkoutId],
            ['status_id', '=', OrderStatus::PENDING_PAYMENT->value],
        ], [
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

    /**
     * Attach payment intent metadata (payment_id + client_secret) to checkout session.
     */
    public function attachPaymentIntent(string $checkoutId, string $paymentId, string $clientSecret): bool
    {
        $updated = $this->checkoutSessions->updateWhere([
            ['id', '=', $checkoutId],
        ], [
            'payment_id'    => $paymentId,
            'client_secret' => $clientSecret,
            'updated_at'    => now(),
        ]);

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
        $updated = $this->checkoutSessions->updateWhere([
            ['id', '=', $checkoutId],
            ['status_id', '=', OrderStatus::PENDING_PAYMENT->value],
        ], [
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
