<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;

class ConsumePaymentFailed extends BaseConsumer
{
    protected $signature = 'consume:payment-failed';
    protected $description = 'Consume payment failed events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_FAILED->value];
    }

    protected function process(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            logger('PAYMENT_FAILED payload missing order_id', ['payload' => $payload]);
            return;
        }

        $reason = $data['reason'] ?? null;
        app(OrderService::class)->fail($orderId, $reason);

        logger('PAYMENT_FAILED EVENT PROCESSED', [
            'order_id' => $orderId,
            'routing_key' => EventTopic::PAYMENT_FAILED->value,
            'reason' => $reason,
        ]);
    }
}
