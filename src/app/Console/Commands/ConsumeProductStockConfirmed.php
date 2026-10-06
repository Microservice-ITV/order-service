<?php

namespace App\Console\Commands;

use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Logging\LogMessage;
use App\Models\CheckoutItem;
use App\Models\ShopOrder;
use App\Services\CheckoutService;
use App\Services\EventPayloadBuilder;
use App\Services\OrderService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;

class ConsumeProductStockConfirmed extends BaseConsumer
{
    protected $signature = 'consume:stock-confirmed|consume:product-stock-confirmed';
    protected $description = 'Consume product.stock.confirmed events and publish order.ready_for_fulfillment';

    protected function eventName(): string
    {
        return EventTopic::PRODUCT_STOCK_CONFIRMED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.stock_confirmed');
    }

    protected function routingKeys(): array
    {
        return [
            EventTopic::PRODUCT_STOCK_CONFIRMED->value,
        ];
    }

    public function process(array $payload): void
    {
        logger()->info('PRODUCT_STOCK_CONFIRMED_RECEIVED', [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $reservationId = $data['reservation_id'] ?? null;

        if (!$checkoutId || !$orderId) {
            logger()->warning(LogMessage::PRODUCT_STOCK_CONFIRMED_INVALID_PAYLOAD, [
                'payload' => $payload,
                'reason' => 'MISSING_CHECKOUT_ID_OR_ORDER_ID',
            ]);
            return;
        }

        $correlationId = (string) ($payload['correlation_id'] ?? $data['correlation_id'] ?? $checkoutId);

        $checkoutService = app(CheckoutService::class);
        $checkout = $checkoutService->findById($checkoutId);
        if (!$checkout) {
            logger()->warning(LogMessage::PRODUCT_STOCK_CONFIRMED_INVALID_PAYLOAD, [
                'checkout_id' => $checkoutId,
                'reason' => 'CHECKOUT_NOT_FOUND',
            ]);
            return;
        }

        $order = $checkout->order;
        if (!$order || $order->order_id !== $orderId) {
            logger()->warning(LogMessage::PRODUCT_STOCK_CONFIRMED_INVALID_PAYLOAD, [
                'checkout_id' => $checkoutId,
                'order_id' => $orderId,
                'reason' => 'ORDER_NOT_FOUND_OR_MISMATCH',
            ]);
            return;
        }

        // Load checkout items first so we can attribute readiness per shop.
        $checkout->loadMissing('items');
        $eventItems = $checkout->items->map(static function (CheckoutItem $item): array {
            return [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'shop_id' => $item->shop_id !== null ? (string) $item->shop_id : null,
                'sku' => $item->product_sku,
                'slug' => $item->product_slug,
                'name' => $item->product_title,
                'description' => $item->product_description,
                'image_url' => $item->image_url,
                'category_id' => $item->category_id,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->price,
                'subtotal' => isset($item->line_total) ? (float) $item->line_total : ((float) $item->price * (int) $item->qty),
                'currency' => $item->currency ?? 'USD',
                'available_stock' => $item->available_stock !== null ? (int) $item->available_stock : null,
            ];
        })->values()->all();

        // Stock confirmation covers the whole checkout reservation, so every
        // shop_order in the order becomes READY_FOR_FULFILLMENT together.
        // Per-shop independence applies at the shipping stage, not here.
        ShopOrder::where('order_id', $orderId)
            ->where('order_status', '!=', OrderStatus::READY_FOR_FULFILLMENT->value)
            ->update(['order_status' => OrderStatus::READY_FOR_FULFILLMENT->value, 'updated_at' => now()]);

        // Promote the ORDER-level status only when all shops are ready.
        // Until then the order stays CONFIRMED and the readiness event is not
        // published downstream.
        $allReady = ! ShopOrder::where('order_id', $orderId)
            ->where('order_status', '!=', OrderStatus::READY_FOR_FULFILLMENT->value)
            ->exists();

        if (! $allReady) {
            logger()->info('ORDER_STILL_PENDING_ALL_SHOPS', [
                'order_id'   => $orderId,
                'checkout_id' => $checkoutId,
            ]);

            return;
        }

        app(OrderInterface::class)->updateByOrderId($orderId, [
            'order_status' => OrderStatus::READY_FOR_FULFILLMENT->value,
            'updated_at'   => now(),
        ]);

        $subtotal = (float) array_reduce(
            $eventItems,
            static fn (float $carry, array $item): float => $carry + (float) ($item['subtotal'] ?? 0),
            0.0
        );
        $shippingTotal = round((float) ($checkout->shipping_total ?? 0), 2);
        $discountTotal = round((float) ($checkout->promotion_discount_total ?? 0), 2);
        $bundleDiscount = round((float) ShopOrder::where('order_id', $orderId)->sum('bundle_discount'), 2);
        $grandTotal = round((float) ($checkout->total ?? max(0, $subtotal + $shippingTotal - $discountTotal)), 2);
        $currency = 'USD';
        if ($eventItems !== []) {
            $currency = (string) ($eventItems[0]['currency'] ?? 'USD');
        }

        $eventPayload = EventPayloadBuilder::build(EventTopic::ORDER_READY_FOR_FULFILLMENT, $correlationId, [
            'order_id' => $orderId,
            'checkout_id' => $checkoutId,
            'reservation_id' => $reservationId,
            'user_id' => (string) $checkout->user_id,
            'status' => 'READY_FOR_FULFILLMENT',
            'deliveryAddress' => $checkout->delivery_address,
            'items' => $eventItems,
            'subtotal' => round($subtotal, 2),
            'shipping_total' => $shippingTotal,
            'promotion_discount_total' => $discountTotal,
            'bundle_discount' => $bundleDiscount,
            'total' => $grandTotal,
            'currency' => $currency,
            // Per-shop attribution so the read side can render each shop's
            // own discount/shipping breakdown instead of re-splitting.
            'promotion_allocations' => array_values(
                is_array($checkout->promotion_snapshot['allocations'] ?? null)
                    ? $checkout->promotion_snapshot['allocations']
                    : []
            ),
            'shop_breakdown' => app(OrderService::class)->shopBreakdown($orderId),
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
