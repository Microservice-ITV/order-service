<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Logging\LogMessage;

class ConsumeProductRejected extends BaseConsumer
{
    protected $signature = 'consume:product-rejected';
    protected $description = 'Consume product rejected events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.product_rejected');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PRODUCT_REJECTED->value];
    }
    
    protected function process(array $payload): void
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

        match ($checkoutService->reject($checkoutId, $reason)) {
            true => logger()->info(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => $reason,
            ]),
            false => logger()->warning(LogMessage::CHECKOUT_REJECT_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
            ]),
        };
        
    }
}
