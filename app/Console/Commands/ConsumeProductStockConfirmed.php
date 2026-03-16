<?php

namespace App\Console\Commands;

use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Logging\LogMessage;
use App\Models\CheckoutItem;
use App\Services\CheckoutService;
use App\Services\EventPayloadBuilder;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;

class ConsumeProductStockConfirmed extends BaseConsumer
{
    protected $signature = 'consume:stock-confirmed|consume:product-stock-confirmed';
    protected $description = 'Consume stock.confirmed events and publish order.ready_for_fulfillment';

    protected function eventName(): string
    {
        return EventTopic::STOCK_CONFIRMED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.stock_confirmed');
    }

    protected function routingKeys(): array
    {
        return [
            EventTopic::STOCK_CONFIRMED->value,
            EventTopic::PRODUCT_STOCK_CONFIRMED->value,
        ];
    }

    protected function process(array $payload): void
    {
        logger()->info('PRODUCT_STOCK_CONFIRMED_RECEIVED', [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $reservationId = $data['reservation_id'] ?? null;

        if (!$checkoutId || !$orderId) {
            logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                'payload' => $payload,
                'reason' => 'MISSING_CHECKOUT_ID_OR_ORDER_ID',
            ]);
            return;
        }

        $correlationId = (string) ($payload['correlation_id'] ?? $data['correlation_id'] ?? $checkoutId);

        $checkoutService = app(CheckoutService::class);
        $checkout = $checkoutService->findById($checkoutId);
        if (!$checkout) {
            logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                'checkout_id' => $checkoutId,
                'reason' => 'CHECKOUT_NOT_FOUND',
            ]);
            return;
        }

        $order = $checkout->order;
        if (!$order || $order->order_id !== $orderId) {
            logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                'checkout_id' => $checkoutId,
                'order_id' => $orderId,
                'reason' => 'ORDER_NOT_FOUND_OR_MISMATCH',
            ]);
            return;
        }

        app(OrderInterface::class)->updateByOrderId($orderId, [
            'status_id' => OrderStatus::READY_FOR_FULFILLMENT->value,
            'updated_at' => now(),
        ]);

        $checkout->loadMissing('items');
        $eventItems = $checkout->items->map(static function (CheckoutItem $item): array {
            return [
                'product_id' => $item->product_id,
                'sku' => $item->product_sku,
                'slug' => $item->product_slug,
                'name' => $item->product_title,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->price,
                'line_total' => isset($item->line_total) ? (float) $item->line_total : ((float) $item->price * (int) $item->qty),
                'currency' => $item->currency ?? 'USD',
            ];
        })->values()->all();

        $eventPayload = EventPayloadBuilder::build(EventTopic::ORDER_READY_FOR_FULFILLMENT, $correlationId, [
            'order_id' => $orderId,
            'checkout_id' => $checkoutId,
            'reservation_id' => $reservationId,
            'user_id' => (string) $checkout->user_id,
            'status' => 'READY_FOR_FULFILLMENT',
            'deliveryAddress' => $checkout->delivery_address,
            'items' => $eventItems,
            'updatedAt' => now()->utc()->toIso8601String(),
        ]);

        Publisher::publishStatic(EventTopic::ORDER_READY_FOR_FULFILLMENT, $eventPayload);

        logger()->info('ORDER_READY_FOR_FULFILLMENT_PUBLISHED', [
            'order_id' => $orderId,
            'checkout_id' => $checkoutId,
            'reservation_id' => $reservationId,
        ]);
    }
}
