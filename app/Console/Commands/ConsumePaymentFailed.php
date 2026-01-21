<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;

class ConsumePaymentFailed extends BaseConsumer
{
    // protected function topic(): string
    // {
    //     return EventTopic::PAYMENT_FAILED->value;
    // }
    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_FAILED->value];
    }

    protected function process(array $payload): void
    {
        app(OrderService::class)->fail($payload['order_id']);
    }
}
