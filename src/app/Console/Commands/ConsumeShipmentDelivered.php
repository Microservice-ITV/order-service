<?php

namespace App\Console\Commands;

use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Logging\LogMessage;
use App\Models\Order;
use App\Models\ShopOrder;
use App\Services\RabbitMQ\BaseConsumer;

/**
 * Delivery confirmation marks shop_orders DELIVERED. COD collection remains a
 * separate shop-owner action and must never be triggered automatically.
 *
 * Multi-shop orders: each shop ships its own package, so a single
 * shipment.delivered only completes that shop's portion. The order-level
 * status becomes DELIVERED once every shop_order is DELIVERED.
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

        $trackingNumber = $data['tracking_number']
            ?? $data['trackingNumber']
            ?? ($data['delivery']['tracking_number'] ?? null)
            ?? ($data['delivery']['trackingNumber'] ?? null);

        $deliveredUpdate = [
            'order_status' => OrderStatus::DELIVERED->value,
            'updated_at'   => now(),
        ];

        $updated = 0;
        if (!empty($trackingNumber)) {
            // Per-shop shipment: complete exactly the shop_order that carries
            // this tracking number.
            $updated = ShopOrder::where('order_id', $order->order_id)
                ->where('order_status', OrderStatus::SHIPPED->value)
                ->where('tracking_number', (string) $trackingNumber)
                ->update($deliveredUpdate);
        }

        if ($updated === 0) {
            $hasPerShopTracking = ShopOrder::where('order_id', $order->order_id)
                ->whereNotNull('tracking_number')
                ->exists();

            if (!$hasPerShopTracking) {
                // Legacy whole-order delivery (no per-shop tracking stored):
                // every still-shipped portion is delivered together.
                ShopOrder::where('order_id', $order->order_id)
                    ->where('order_status', OrderStatus::SHIPPED->value)
                    ->update($deliveredUpdate);
            } elseif (empty($trackingNumber)) {
                // Per-shop order, but this shipment was created without a
                // tracking number — complete the shop's untracked portion.
                ShopOrder::where('order_id', $order->order_id)
                    ->where('order_status', OrderStatus::SHIPPED->value)
                    ->whereNull('tracking_number')
                    ->update($deliveredUpdate);
            }
            // else: replayed event for an already-delivered shop — no-op.
        }

        // Order-level DELIVERED only when every shop has been delivered.
        $allDelivered = ! ShopOrder::where('order_id', $order->order_id)
            ->where('order_status', '!=', OrderStatus::DELIVERED->value)
            ->exists();

        if ($allDelivered && $order->order_status !== OrderStatus::DELIVERED->value) {
            app(OrderInterface::class)->updateByOrderId($order->order_id, [
                'order_status' => OrderStatus::DELIVERED->value,
                'updated_at' => now(),
            ]);
        }

        logger()->info('ORDER_SHIPMENT_DELIVERY_PROCESSED', [
            'order_id' => $order->order_id,
            'all_delivered' => $allDelivered,
            'payment_id' => $order->payment_id,
        ]);
    }
}
