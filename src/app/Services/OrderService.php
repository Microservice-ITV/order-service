<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Enums\OrderStatus;
use App\Models\CheckoutItem;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly OrderInterface $orders
    ) {}

    public function createOrder(string $checkoutId, string $correlationId, ?string $reservationId = null): Order
    {
        return $this->orders->createOrder([
            'order_id'       => (string) Str::uuid(),
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
            'status_id'      => OrderStatus::CONFIRMED->value,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * Snapshot the checkout line items into order_items for a confirmed order.
     * Runs inside the same transaction as order creation (idempotent per order).
     */
    public function snapshotCheckoutItems(CheckoutSession $checkout, Order $order): void
    {
        $rows = $checkout->items
            ->map(function (CheckoutItem $item) use ($order): array {
                return [
                    'id'      => (string) Str::uuid(),
                    'order_id'            => $order->order_id,
                    'product_id'          => $item->product_id,
                    'variant_id'          => $item->variant_id,
                    'qty'                 => $item->qty,
                    'price'               => $item->price,
                    'line_total'          => $item->line_total,
                    'available_stock'     => $item->available_stock,
                    'product_slug'        => $item->product_slug,
                    'product_title'       => $item->product_title,
                    'product_description' => $item->product_description,
                    'product_sku'         => $item->product_sku,
                    'category_id'         => $item->category_id,
                    'shop_id'             => $item->shop_id,
                    'image_url'           => $item->image_url,
                    'currency'            => $item->currency,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ];
            })
            ->filter(fn (array $row): bool => $row['product_id'] !== null && $row['product_id'] !== '')
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        OrderItem::insert($rows);
    }

    public function attachPayment(string $checkoutId, string $paymentId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'payment_id' => $paymentId,
            'updated_at' => now(),
        ]) === 1;
    }

    public function markPaid(string $checkoutId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'status_id' => OrderStatus::PAID->value,
            'updated_at' => now(),
        ]) === 1;
    }

    public function cancel(string $checkoutId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'status_id'  => OrderStatus::CANCELLED->value,
            'updated_at' => now(),
        ]) === 1;
    }
}