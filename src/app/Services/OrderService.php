<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Enums\OrderStatus;
use App\Models\CheckoutItem;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShopOrder;
use App\Support\PromotionAllocationSplitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly OrderInterface $orders
    ) {}

    public function createOrder(CheckoutSession $checkout, string $correlationId, ?string $reservationId = null): Order
    {
        $checkoutId = $checkout->id;
        $checkout->loadMissing('items');
        $totalSubtotal = (float) ($checkout->items->sum('line_total') ?? 0);
        $shippingTotal = (float) ($checkout->shipping_total ?? 0);
        $discountTotal = (float) ($checkout->promotion_discount_total ?? 0);
        $grandTotal = (float) ($checkout->total ?? 0);
        $isCod = ($checkout->payment_method ?? '') === 'cod';

        return $this->orders->createOrder([
            'order_id'       => (string) Str::uuid(),
            'checkout_id'    => $checkoutId,
            'user_id'        => (string) ($checkout->user_id ?? ''),
            'reservation_id' => $reservationId,
            'order_status'   => OrderStatus::CONFIRMED->value,
            'correlation_id' => $correlationId,
            'currency'       => 'USD',
            'subtotal'       => $totalSubtotal,
            'shipping_total' => $shippingTotal,
            'discount_total' => $discountTotal,
            'grand_total'    => $grandTotal,
            'payment_status' => $isCod ? 'COD' : 'PENDING',
        ]);
    }

    /**
     * Snapshot the checkout line items into order_items for a confirmed order.
     * Also creates shop_orders and assigns shop_order_id to each order_item.
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
                    'subtotal'          => $item->line_total,
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

        // Create shop_orders and assign shop_order_id to order_items
        $this->createShopOrders($rows, $checkout, $order);
    }

    /**
     * Create shop_orders from order_items and update order_items with shop_order_id.
     */
    private function createShopOrders(array $rows, CheckoutSession $checkout, Order $order): void
    {
        // Group items by shop_id
        $shopGroups = [];
        foreach ($rows as $row) {
            $shopId = $row['shop_id'];
            if (!isset($shopGroups[$shopId])) {
                $shopGroups[$shopId] = ['items' => [], 'subtotal' => 0];
            }
            $shopGroups[$shopId]['items'][] = $row;
            $shopGroups[$shopId]['subtotal'] += (float) $row['subtotal'];
        }

        $shopIds = array_keys($shopGroups);
        $shopSubtotals = [];
        foreach ($shopGroups as $shopId => $group) {
            $shopSubtotals[$shopId] = round($group['subtotal'], 2);
        }

        $shippingTotal = (float) ($checkout->shipping_total ?? 0);
        $discountTotal = (float) ($checkout->promotion_discount_total ?? 0);

        // Prefer the quote's per-shop discount allocations (exact attribution:
        // a shop-scoped promo only discounts its own shop). Legacy snapshots
        // without allocations fall back to subtotal-proportional splitting.
        // Both paths sum exactly to the order-level discount so that
        // Σ shop grand totals equals the charged order total.
        $allocations = is_array($checkout->promotion_snapshot['allocations'] ?? null)
            ? $checkout->promotion_snapshot['allocations']
            : [];
        $discountByShop = PromotionAllocationSplitter::splitPerShop(
            $allocations,
            $shopIds,
            $shopSubtotals,
            $discountTotal,
        );
        $shippingByShop = PromotionAllocationSplitter::proportional(
            $shopIds,
            $shopSubtotals,
            $shippingTotal,
        );

        $isCod = ($checkout->payment_method ?? '') === 'cod';

        foreach ($shopGroups as $shopId => $group) {
            $shopSubtotal = $shopSubtotals[$shopId];
            $shopShipping = $shippingByShop[$shopId];
            $shopDiscount = $discountByShop[$shopId];
            $shopGrandTotal = round($shopSubtotal + $shopShipping - $shopDiscount, 2);

            $shopOrder = ShopOrder::create([
                'id'              => (string) Str::uuid(),
                'order_id'        => $order->order_id,
                'shop_id'         => $shopId,
                'subtotal'        => $shopSubtotal,
                'shipping_total'  => $shopShipping,
                'discount_total'  => $shopDiscount,
                'grand_total'     => $shopGrandTotal,
                'order_status'    => $order->order_status,
                'payment_status'  => $isCod ? 'COD' : 'PENDING',
            ]);

            // Update order_items with shop_order_id
            $itemIds = array_column($group['items'], 'id');
            OrderItem::whereIn('id', $itemIds)->update(['shop_order_id' => $shopOrder->id]);
        }
    }

    public function attachPayment(string $checkoutId, string $paymentId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'payment_id' => $paymentId,
            'updated_at' => now(),
        ]) === 1;
    }

    public function markPaid(CheckoutSession $checkout): bool
    {
        $isCod = ($checkout->payment_method ?? '') === 'cod';
        $update = [
            'order_status'  => OrderStatus::PAID->value,
            'updated_at'    => now(),
        ];
        if (!$isCod) {
            $update['payment_status'] = 'PAID';
        }

        return $this->orders->updateByCheckoutId($checkout->id, $update) === 1;
    }

    public function cancel(string $checkoutId): bool
    {
        return $this->orders->updateByCheckoutId($checkoutId, [
            'order_status'  => OrderStatus::CANCELLED->value,
            'updated_at' => now(),
        ]) === 1;
    }

    public function markShopOrdersPaid(string $orderId, bool $isCod): int
    {
        if ($isCod) {
            return 0;
        }

        return ShopOrder::where('order_id', $orderId)
            ->where('payment_status', '!=', 'PAID')
            ->update(['payment_status' => 'PAID', 'updated_at' => now()]);
    }

    /**
     * Whether every shop_order for this order has reached READY_FOR_FULFILLMENT.
     * Used to gate the order-level transition — an order is only ready to ship
     * when ALL its shops have confirmed stock.
     */
    public function isReadyForFulfillment(string $orderId): bool
    {
        return ! ShopOrder::where('order_id', $orderId)
            ->where('order_status', '!=', OrderStatus::READY_FOR_FULFILLMENT->value)
            ->exists();
    }

    /**
     * Derive the order-level status from all shop_orders.
     * - All shop_orders SHIPPED or higher → FULFILLED
     * - Some shop_orders SHIPPED          → PARTIALLY_FULFILLED
     * - Otherwise                           → READY_FOR_FULFILLMENT
     */
    public function derivedOrderStatus(string $orderId): int
    {
        $shops = ShopOrder::where('order_id', $orderId)
            ->select('order_status')
            ->get();

        $total = $shops->count();
        // SHIPPED or beyond (DELIVERED) counts as shipped for derivation.
        $shippedCount = $shops->whereIn('order_status', [
            OrderStatus::SHIPPED->value,
            OrderStatus::DELIVERED->value,
        ])->count();

        if ($total > 0 && $shippedCount === $total) {
            return OrderStatus::FULFILLED->value;
        }

        if ($shippedCount > 0) {
            return OrderStatus::PARTIALLY_FULFILLED->value;
        }

        return OrderStatus::READY_FOR_FULFILLMENT->value;
    }

    /**
     * Per-shop financial breakdown of an order, for downstream projections
     * (ecommerce-read-service) and seller-facing order pages.
     *
     * @return list<array{shop_order_id: string, shop_id: string, subtotal: float, discount_total: float, shipping_total: float, grand_total: float}>
     */
    public function shopBreakdown(string $orderId): array
    {
        return ShopOrder::query()
            ->where('order_id', $orderId)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (ShopOrder $shopOrder): array => [
                'shop_order_id'      => (string) $shopOrder->id,
                'shop_id'            => (string) $shopOrder->shop_id,
                'subtotal'           => (float) $shopOrder->subtotal,
                'discount_total'     => (float) $shopOrder->discount_total,
                'shipping_total'     => (float) $shopOrder->shipping_total,
                'grand_total'        => (float) $shopOrder->grand_total,
                'status'             => OrderStatus::tryFrom((int) $shopOrder->order_status)?->name ?? '',
                'tracking_number'    => $shopOrder->tracking_number,
                'courier_name'       => $shopOrder->courier_name,
                'shipment_created_at'=> $shopOrder->shipment_created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}