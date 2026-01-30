<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Logging\LogMessage;
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
        return config('rabbitmq.queues.payment_success');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_SUCCEEDED->value];
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

        if (!$checkoutId || !$correlationId) {
            logger()->warning(LogMessage::PAYMENT_SUCCEEDED_INVALID_PAYLOAD, [
                'payload' => $payload,
            ]);
            return;
        }

        logger()->info(LogMessage::PAYMENT_SUCCEEDED_RECEIVED, [
            'checkout_id'    => $checkoutId,
            'payment_id'     => $paymentId,
            'correlation_id' => $correlationId,
        ]);

        try {
            DB::transaction(function () use (
                $checkoutId,
                $paymentId,
                $correlationId,
                &$order
            ) {
                /**
                 * Confirm checkout (IDEMPOTENT)
                 * - Must atomically check & update state
                 */
                $confirmed = app(CheckoutService::class)
                    ->confirm($checkoutId);

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
                $order = app(OrderService::class)->createOrder(
                    checkoutId: $checkoutId,
                    correlationId: $correlationId
                );

                /**
                 * Attach payment (optional, idempotent)
                 */
                if ($paymentId) {
                    app(OrderService::class)->attachPayment(
                        checkoutId: $checkoutId,
                        paymentId: $paymentId
                    );
                }
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
             * Publish ORDER_SUCCEEDED event
             * Downstream services (stock, shipping, email, etc.)
             */
            Publisher::publish(
                EventTopic::ORDER_SUCCEEDED,
                [
                    'event'          => EventTopic::ORDER_SUCCEEDED->value,
                    'correlation_id' => $correlationId,
                    'data' => [
                        'order_id'      => $order->order_id,
                        'checkout_id'   => $checkoutId,
                        'payment_id'    => $paymentId,
                        'reservation_id'=> $order->reservation_id,
                    ],
                ]
            );

        } catch (Throwable $e) {
            logger()->error(LogMessage::PAYMENT_SUCCEEDED_PROCESSING_FAILED, [
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
