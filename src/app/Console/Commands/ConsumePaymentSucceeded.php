<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Repositories\SagaRepository;
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

    protected function eventName(): string
    {
        return EventTopic::PAYMENT_COMPLETED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment_completed');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_COMPLETED->value];
    }

    public function process(array $payload): void
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

            // Advance the saga tracker: payment processing succeeded.
            $sagaRepository = app(SagaRepository::class);
            $saga = $sagaRepository->findByOrderId($checkoutId);
            if ($saga && !in_array('PAYMENT_PROCESSING', $saga->completed_steps ?? [], true)) {
                $sagaRepository->completeStep($saga->saga_id, 'PAYMENT_PROCESSING');

                logger()->info('SAGA_STEP_COMPLETED_FROM_PAYMENT_SUCCEEDED', [
                    'saga_id'  => $saga->saga_id,
                    'step'     => 'PAYMENT_PROCESSING',
                    'checkout_id' => $checkoutId,
                    'order_id' => $order->order_id,
                ]);
            }

            logger()->info(LogMessage::ORDER_CREATED_AFTER_PAYMENT, [
                'order_id'       => $order->order_id,
                'checkout_id'    => $checkoutId,
                'payment_id'     => $paymentId,
                'correlation_id' => $correlationId,
            ]);

            /**
             * Publish ORDER_PAID event
             * Downstream services (stock, shipping, email, etc.)
             * Refresh checkout so promotion_snapshot reflects the latest address/promo quote
             * (the in-memory model may have been loaded before Place Order updated it).
             */
            $checkout = $checkoutService->findById($checkoutId) ?? $checkout;
            $checkout->loadMissing('items');
            $lineItems = $checkout->items->map(static function ($item): array {
                return [
                    'name' => (string) ($item->product_title ?? 'Order item'),
                    'quantity' => (int) $item->qty,
                    'unitPrice' => number_format((float) $item->price, 2, '.', ''),
                    'total' => number_format((float) $item->line_total, 2, '.', ''),
                ];
            })->values()->all();
            $promotionSnapshot = is_array($checkout->promotion_snapshot)
                ? $checkout->promotion_snapshot
                : [];

            $payload = EventPayloadBuilder::build(EventTopic::ORDER_PAID, $correlationId, [
                'order_id'       => $order->order_id,
                'checkout_id'    => $checkoutId,
                'user_id'        => (string) $checkout->user_id,
                'status'         => 'ORDER_PAID',
                'payment_id'     => $paymentId,
                'transaction_id' => $transactionId,
                'amount'         => $amount,
                'currency'       => $currency ?? 'USD',
                'reservation_id' => $order->reservation_id,
                'deliveryAddress' => $checkout->delivery_address,
                'line_items' => $lineItems,
                'expire_at'      => $checkout->getExpireAtIso(),
                'updatedAt'      => is_string($sourceUpdatedAt) && $sourceUpdatedAt !== ''
                    ? $sourceUpdatedAt
                    : now()->utc()->toIso8601String(),
                // Promotion usage commit payload (consumed by promotion-service)
                'promotion_ids' => array_values($promotionSnapshot['promotion_ids'] ?? []),
                'promotion_discount_total' => (float) ($checkout->promotion_discount_total ?? 0),
                'applied_rewards' => array_values($promotionSnapshot['applied_rewards'] ?? []),
                'promo_code' => $promotionSnapshot['promo_code'] ?? null,
            ]);
            Publisher::publishStatic(EventTopic::ORDER_PAID, $payload);

            logger()->info('ORDER_PAID_EVENT_PUBLISHED', [
                'checkout_id' => $checkoutId,
                'order_id' => $order->order_id,
                'promotion_ids' => $payload['data']['promotion_ids'] ?? [],
                'promo_code' => $payload['data']['promo_code'] ?? null,
                'promotion_discount_total' => $payload['data']['promotion_discount_total'] ?? 0,
            ]);

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
