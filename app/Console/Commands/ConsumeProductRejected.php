<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;

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
        logger('PRODUCT_REJECTED EVENT RECEIVED', ['payload' => $payload]);
        $data = $payload['data'] ?? $payload;
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            logger('PRODUCT_REJECTED payload missing order_id', ['payload' => $payload]);
            return;
        }

        $reason = $data['reason'] ?? null;
        app(OrderService::class)->reject($orderId, $reason);

        logger('PRODUCT_REJECTED EVENT PROCESSED', [
            'order_id' => $orderId,
            'routing_key' => EventTopic::PRODUCT_REJECTED->value,
            'reason' => $reason,
        ]);
    }
}
