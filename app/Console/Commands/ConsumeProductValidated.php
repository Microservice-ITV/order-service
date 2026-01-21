<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;

class ConsumeProductValidated extends BaseConsumer
{
    protected function routingKeys(): array
    {
        return [EventTopic::PRODUCT_VALIDATED->value];
    }

    protected function process(array $payload): void
    {
        DB::transaction(function () use ($payload) {
            app(OrderService::class)
                ->markPendingPayment($payload['order_id'], $payload['total']);

            foreach ($payload['items'] as $item) {
                OrderItem::create([
                    'order_id' => $payload['order_id'],
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'price' => $item['price']
                ]);
            }

            Publisher::publish(EventTopic::PAYMENT_REQUESTED, [
                'order_id' => $payload['order_id'],
                'amount' => $payload['total']
            ]);
        });
    }
}
