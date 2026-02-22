<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Services\EventPayloadBuilder;
use App\Logging\LogMessage;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConsumePaymentSucceeded extends BaseConsumer
{
    protected $signature = 'consume:payment-succeeded';
    protected $description = 'Consume payment succeeded events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment_completed');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_COMPLETED->value];
    }

    protected function process(array $payload): void
    {
        $data = $payload['data'] ?? [];

        // Single source of truth for correlation ID
        $correlationId = $payload['correlation_id']
            ?? $data['correlation_id']
            ?? null;

        $checkoutId = $data['checkout_id'] ?? null;
        $paymentId  = $data['payment_id'] ?? null;
        $transactionId = $data['transaction_id'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $currency = isset($data['currency']) ? (string) $data['currency'] : null;
        $sourceUpdatedAt = $data['updatedAt'] ?? null;
        $reservationId = $data['reservation_id'] ?? null;

        if (!$checkoutId || !$correlationId) {
            logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                'payload' => $payload,
            ]);
            return;
        }

        logger()->info(LogMessage::PAYMENT_COMPLETED_RECEIVED, [
            'checkout_id'    => $checkoutId,
            'payment_id'     => $paymentId,
            'reservation_id' => $reservationId,
            'correlation_id' => $correlationId,
        ]);

        try {
            $checkoutService = app(CheckoutService::class);
            $orderService = app(OrderService::class);

            // Validate checkout exists before processing payment
            $checkout = $checkoutService->findById($checkoutId);
            if (!$checkout) {
                logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                    'checkout_id' => $checkoutId,
                    'reason'      => 'CHECKOUT_NOT_FOUND',
                ]);
                return;
            }

            if (in_array($checkout->status_id, [OrderStatus::PAID, OrderStatus::CONFIRMED], true)) {
                logger()->info(LogMessage::PAYMENT_COMPLETED_ALREADY_PROCESSED, [
                    'checkout_id' => $checkoutId,
                    'payment_id'  => $paymentId,
                ]);
                return;
            }

            if ($checkout->isExpired()) {
                $checkoutService->fail($checkoutId, 'CHECKOUT_EXPIRED');

                logger()->warning(LogMessage::PAYMENT_COMPLETED_CHECKOUT_EXPIRED, [
                    'checkout_id' => $checkoutId,
                    'payment_id'  => $paymentId,
                    'expire_at'   => $checkout->getExpireAtIso(),
                ]);
                return;
            }

            DB::transaction(function () use (
                $checkoutId,
                $paymentId,
                $reservationId,
                $correlationId,
                $checkoutService,
                $orderService,
                &$order
            ) {
                /**
                 * Confirm checkout (IDEMPOTENT)
                 * - Must atomically check & update state
                 */
                $confirmed = $checkoutService->confirm($checkoutId);

                if (!$confirmed) {
                    logger()->warning(
                        LogMessage::PAYMENT_CONFIRM_IGNORED_INVALID_STATE,
                        ['checkout_id' => $checkoutId]
                    );
                    return;
                }

                /**
                 *  Create order (idempotent by checkout_id UNIQUE)
                 */
                $order = $orderService->createOrder(
                    checkoutId: $checkoutId,
                    correlationId: $correlationId,
                    reservationId: $reservationId
                );

                /**
                 * Attach payment (optional, idempotent)
                 */
                if ($paymentId) {
                    $orderService->attachPayment(
                        checkoutId: $checkoutId,
                        paymentId: $paymentId
                    );
                }

                $orderService->markPaid($checkoutId);
            });

            if (!isset($order)) {
                // Checkout already processed — safe to ignore
                return;
            }

            logger()->info(LogMessage::ORDER_CREATED_AFTER_PAYMENT, [
                'order_id'       => $order->order_id,
                'checkout_id'    => $checkoutId,
                'payment_id'     => $paymentId,
                'correlation_id' => $correlationId,
            ]);

            /**
             * Publish ORDER_COMPLETED event
             * Downstream services (stock, shipping, email, etc.)
             */
            $payload = EventPayloadBuilder::build(EventTopic::ORDER_COMPLETED, $correlationId, [
                'order_id'       => $order->order_id,
                'checkout_id'    => $checkoutId,
                'user_id'        => (string) $checkout->user_id,
                'status'         => 'COMPLETED',
                'payment_id'     => $paymentId,
                'transaction_id' => $transactionId,
                'amount'         => $amount,
                'currency'       => $currency ?? 'USD',
                'reservation_id' => $order->reservation_id,
                'expire_at'      => $checkout->getExpireAtIso(),
                'updatedAt'      => is_string($sourceUpdatedAt) && $sourceUpdatedAt !== ''
                    ? $sourceUpdatedAt
                    : now()->utc()->toIso8601String(),
            ]);
            Publisher::publishStatic(EventTopic::ORDER_COMPLETED, $payload);

        } catch (Throwable $e) {
            logger()->error(LogMessage::PAYMENT_COMPLETED_PROCESSING_FAILED, [
                'checkout_id'    => $checkoutId,
                'payment_id'     => $paymentId,
                'correlation_id' => $correlationId,
                'exception'      => $e,
            ]);

            // Let BaseConsumer decide retry / requeue behavior
            throw $e;
        }
    }
}
