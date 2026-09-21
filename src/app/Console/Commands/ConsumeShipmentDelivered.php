<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Logging\LogMessage;
use App\Models\Order;
use App\Services\RabbitMQ\BaseConsumer;

/**
 * Delivery confirmation marks the order DELIVERED. COD collection remains a
 * separate shop-owner action and must never be triggered automatically.
 */
class ConsumeShipmentDelivered extends BaseConsumer
{
    protected $signature = 'consume:shipment-delivered';
    protected $description = 'Consume shipment.delivered events and mark orders delivered';

    protected function eventName(): string
    {
        return EventTopic::SHIPMENT_DELIVERED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.shipment_delivered');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::SHIPMENT_DELIVERED->value];
    }

    public function process(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            logger()->warning(LogMessage::PAYMENT_COD_COLLECT_MISSING_ORDER_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        logger()->info('SHIPMENT_DELIVERED_RECEIVED', [
            'order_id' => $orderId,
            'payload'  => $payload,
        ]);

        $order = Order::query()->where('order_id', (string) $orderId)->first();
        if (!$order) {
            logger()->info('SHIPMENT_DELIVERED_ORDER_NOT_FOUND', [
                'order_id' => $orderId,
            ]);
            return;
        }

        if ($order->status_id !== OrderStatus::DELIVERED->value) {
            $order->update(['status_id' => OrderStatus::DELIVERED->value]);
        }

        logger()->info('ORDER_MARKED_DELIVERED_FROM_SHIPMENT', [
            'order_id' => $order->order_id,
            'payment_id' => $order->payment_id,
        ]);
    }
}
