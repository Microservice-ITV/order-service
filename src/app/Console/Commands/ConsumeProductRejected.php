<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Repositories\SagaRepository;
use App\Services\EventPayloadBuilder;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
use App\Logging\LogMessage;

class ConsumeProductRejected extends BaseConsumer
{
    protected $signature = 'consume:stock-rejected|consume:product-rejected';
    protected $description = 'Consume stock rejected events';

    protected function eventName(): string
    {
        return EventTopic::PRODUCT_REJECTED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.stock_rejected');
    }

    protected function routingKeys(): array
    {
        return [
            EventTopic::PRODUCT_REJECTED->value,
        ];
    }
    
    public function process(array $payload): void
    {
        logger()->info(LogMessage::PRODUCT_REJECTED_RECEIVED, [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutId) {
            logger()->warning(LogMessage::PRODUCT_REJECTED_MISSING_CHECKOUT_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        $reason = $data['reason'] ?? 'PRODUCT_VALIDATION_FAILED';

        $checkoutService = app(CheckoutService::class);
        $checkout = $checkoutService->findById($checkoutId);
        if ($checkout && in_array($checkout->status_id, [OrderStatus::PAID, OrderStatus::CONFIRMED], true)) {
            logger()->info(LogMessage::CHECKOUT_REJECT_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
                'status'      => $checkout->status_id->name,
            ]);
            return;
        }

        $rejected = $checkoutService->reject($checkoutId, $reason);
        if (!$rejected) {
            logger()->warning(LogMessage::CHECKOUT_REJECT_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
            ]);
            return;
        }

        // Advance the saga tracker: inventory reservation failed → compensation flagged.
        $sagaRepository = app(SagaRepository::class);
        $saga = $sagaRepository->findByOrderId($checkoutId);
        if ($saga && !in_array('INVENTORY_RESERVATION', $saga->failed_steps ?? [], true)) {
            $sagaRepository->failStep($saga->saga_id, 'INVENTORY_RESERVATION', $reason);

            logger()->info('SAGA_STEP_FAILED_FROM_STOCK_REJECTED', [
                'saga_id'  => $saga->saga_id,
                'step'     => 'INVENTORY_RESERVATION',
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

        logger()->info(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
            'checkout_id' => $checkoutId,
            'reason'      => $reason,
        ]);
        
    }
}
