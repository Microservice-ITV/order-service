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
        app(OrderService::class)->confirm($payload['order_id']);
        session()->forget('cart');
    }
}
