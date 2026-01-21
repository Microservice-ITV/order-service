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
        app(OrderService::class)->fail($payload['order_id']);
    }
}
