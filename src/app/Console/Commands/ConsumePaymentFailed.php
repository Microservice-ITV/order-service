<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Repositories\SagaRepository;
use App\Services\EventPayloadBuilder;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
use App\Logging\LogMessage;

class ConsumePaymentFailed extends BaseConsumer
{
    protected $signature = 'consume:payment-failed';
    protected $description = 'Consume payment failed events';

    protected function eventName(): string
    {
        return EventTopic::PAYMENT_FAILED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment_failed');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_FAILED->value];
    }

    public function process(array $payload): void
    {
        logger()->info(LogMessage::PAYMENT_FAILED_RECEIVED, [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutId) {
            logger()->warning(LogMessage::PAYMENT_FAILED_MISSING_CHECKOUT_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        $reason = $data['reason'] ?? null;
        $checkoutService = app(CheckoutService::class);
        if (!$checkoutService->fail($checkoutId, $reason)) {
            logger()->warning(LogMessage::PAYMENT_FAIL_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
            ]);
            return;
        }

        // Advance the saga tracker: payment processing failed → compensation flagged.
        $sagaRepository = app(SagaRepository::class);
        $saga = $sagaRepository->findByOrderId($checkoutId);
        if ($saga && !in_array('PAYMENT_PROCESSING', $saga->failed_steps ?? [], true)) {
            $sagaRepository->failStep($saga->saga_id, 'PAYMENT_PROCESSING', $reason ?? 'PAYMENT_FAILED');

            logger()->info('SAGA_STEP_FAILED_FROM_PAYMENT_FAILED', [
                'saga_id'  => $saga->saga_id,
                'step'     => 'PAYMENT_PROCESSING',
                'checkout_id' => $checkoutId,
                'reason'   => $reason,
            ]);
        }

        $checkout = $checkoutService->findById($checkoutId);
        $orderId = $checkout?->order?->order_id;
        $correlationId = (string) ($payload['correlation_id'] ?? $data['correlation_id'] ?? $checkout?->correlation_id ?? '');
        if ($correlationId !== '') {
            $eventPayload = EventPayloadBuilder::build(EventTopic::ORDER_CANCELLED, $correlationId, [
                'order_id' => $orderId,
                'checkout_id' => $checkoutId,
                'user_id' => (string) ($checkout?->user_id ?? ''),
                'status' => 'CANCELLED',
                'updatedAt' => now()->utc()->toIso8601String(),
                'reason' => $reason,
            ]);
            Publisher::publishStatic(EventTopic::ORDER_CANCELLED, $eventPayload);
        }

        logger()->info(LogMessage::CHECKOUT_PAYMENT_FAILED, [
            'checkout_id' => $checkoutId,
            'routing_key' => EventTopic::PAYMENT_FAILED->value,
            'reason'      => $reason,
        ]);
    }
}
