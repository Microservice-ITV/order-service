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
    protected $signature = 'consume:product-validated';
    protected $description = 'Consume product validated events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.product');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PRODUCT_VALIDATED->value];
    }

    protected function process(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            logger('PRODUCT_VALIDATED payload missing order_id', ['payload' => $payload]);
            return;
        }

        DB::transaction(function () use ($data, $orderId) {
            
            $total = $data['total'] ?? $this->calculateOrderTotal($orderId);

            app(OrderService::class)
                ->markStockConfirmed($orderId, $total);

            Publisher::publish(EventTopic::PAYMENT_REQUESTED, [
                'event' => EventTopic::PAYMENT_REQUESTED->value,
                'version' => 'v1',
                'timestamp' => now()->utc()->toIso8601String(),
                'producer' => 'order-service',
                'data' => [
                    'order_id' => $orderId,
                    'amount' => $total,
                ],
            ]);

            logger('PAYMENT_REQUESTED EVENT PUBLISHED', [
                'order_id' => $orderId,
                'routing_key' => EventTopic::PAYMENT_REQUESTED->value,
            ]);
        });
    }

    private function calculateOrderTotal(string $orderId): float
    {
        return (float) OrderItem::where('order_id', $orderId)
            ->get()
            ->sum(function (OrderItem $item) {
                return $item->qty * (float) ($item->price ?? 0);
            });
    }
}
