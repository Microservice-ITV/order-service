<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;

class ConsumeProductRejected extends BaseConsumer
{
    // protected function topic(): string
    // {
    //     return EventTopic::PRODUCT_REJECTED->value;
    // }
    protected function routingKeys(): array
    {
        return [EventTopic::PRODUCT_REJECTED->value];
    }
    
    protected function process(array $payload): void
    {
        app(OrderService::class)->reject($payload['order_id']);
    }
}
