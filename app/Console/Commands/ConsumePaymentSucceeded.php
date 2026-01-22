<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;

class ConsumePaymentSucceeded extends BaseConsumer
{
    protected $signature = 'consume:payment-succeeded';
    protected $description = 'Consume payment succeeded events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_SUCCEEDED->value];
    }

    protected function process(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            logger('PAYMENT_SUCCEEDED payload missing order_id', ['payload' => $payload]);
            return;
        }

        app(OrderService::class)->confirm($orderId);
        session()->forget('cart');

        logger('PAYMENT_SUCCEEDED EVENT PROCESSED', [
            'order_id' => $orderId,
            'routing_key' => EventTopic::PAYMENT_SUCCEEDED->value,
        ]);
    }
}
