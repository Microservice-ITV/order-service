<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;

class ConsumePaymentSucceeded extends BaseConsumer
{
    // protected function topic(): string
    // {
    //     return EventTopic::PAYMENT_SUCCEEDED->value;
    // }
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
