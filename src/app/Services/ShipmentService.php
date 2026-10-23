<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\CheckoutItem;
use App\Models\Order;
use App\Models\ShopOrder;
use App\Services\EventPayloadBuilder;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShipmentService
{
    public function __construct(
        private readonly OrderInterface $orders
    ) {}

    /**
     * Create a shipment. When $shopId is provided only that shop's
     * items are shipped (per-shop fulfillment); otherwise the whole
     * order ships at once (legacy behaviour, still gated on ALL shops
     * being ready).
     *
     * @param array{courier_code?: string|null, courier_name?: string|null, courier_phone?: string|null, tracking_number?: string|null, way_bill_no: string, tracking_url?: string|null} $input
     * @return Order
     * @throws BusinessException
     */
    public function createShipment(string $orderId, array $input, ?string $shopId = null): Order
    {
        /** @var \App\Models\Order|null $order */
        $order = $this->orders->findById($orderId);
        if (!$order) {
            throw new BusinessException('Order not found', 404);
        }

        $wayBillNo = trim((string) ($input['way_bill_no'] ?? ''));
        if ($wayBillNo === '') {
            throw new BusinessException('Waybill number is required before creating a shipment', 422);
        }

        // Legacy (no shop_id) path only accepts READY, or an already-shipped
        // order for idempotent retries. Per-shop path gates on shop_order status.
        $alreadyShippedOrderStatuses = [
            OrderStatus::SHIPPED->value,
            OrderStatus::FULFILLED->value,
            OrderStatus::DELIVERED->value,
        ];
        if (
            $shopId === null
            && $order->order_status !== OrderStatus::READY_FOR_FULFILLMENT->value
            && !in_array((int) $order->order_status, $alreadyShippedOrderStatuses, true)
        ) {
            throw new BusinessException(
                'Order must be in READY_FOR_FULFILLMENT status to create shipment',
                409,
                ['current_status_id' => $order->order_status]
            );
        }

        $trackingNumber = $input['tracking_number'] ?? null;
        $trackingUrl = $input['tracking_url'] ?? null;

        if (empty($trackingNumber) && config('shipping.auto_generate_tracking', true)) {
            $trackingNumber = $this->generateTrackingNumber($orderId);
        }

        if ($shopId !== null) {
            return $this->shipShop($order, $shopId, $trackingNumber, $trackingUrl, $wayBillNo);
        }

        if (ShopOrder::where('order_id', $orderId)->whereNotNull('delivery_company_id')->exists()) {
            throw new BusinessException(
                'Orders with configured Delivery Companies must be shipped per shop',
                409,
                ['reason' => 'per_shop_shipment_required']
            );
        }

        // --- Legacy path: all-or-nothing shipment (gated on all shops ready) ---
        $updateData = [
            'courier_code' => $input['courier_code'] ?? null,
            'courier_name' => $input['courier_name'] ?? null,
            'courier_phone' => $input['courier_phone'] ?? null,
            'tracking_number' => $trackingNumber,
            'way_bill_no' => $wayBillNo,
            'tracking_url' => $trackingUrl,
            'order_status' => OrderStatus::SHIPPED->value,
            'shipment_created_at' => now(),
            'updated_at' => now(),
        ];

        $created = DB::transaction(function () use ($orderId, $wayBillNo, $updateData): bool {
            $lockedOrder = Order::where('order_id', $orderId)->lockForUpdate()->first();
            if (!$lockedOrder) {
                throw new BusinessException('Order not found', 404);
            }

            $this->assertWayBillNoCanBeSet($lockedOrder, $wayBillNo);

            // Idempotent: already shipped/fulfilled — return success without re-emitting events.
            if (in_array((int) $lockedOrder->order_status, [
                OrderStatus::SHIPPED->value,
                OrderStatus::FULFILLED->value,
                OrderStatus::DELIVERED->value,
            ], true)) {
                return false;
            }

            if ($lockedOrder->order_status !== OrderStatus::READY_FOR_FULFILLMENT->value) {
                throw new BusinessException(
                    'Order must be in READY_FOR_FULFILLMENT status to create shipment',
                    409,
                    ['current_status_id' => $lockedOrder->order_status]
                );
            }

            $pendingShopCount = ShopOrder::where('order_id', $orderId)
                ->where('order_status', '!=', OrderStatus::READY_FOR_FULFILLMENT->value)
                ->count();
            if ($pendingShopCount > 0) {
                throw new BusinessException(
                    'All shops must be ready for fulfillment before shipment',
                    409,
                    ['pending_shop_count' => $pendingShopCount]
                );
            }

            $this->orders->updateByOrderId($orderId, $updateData);

            return true;
        });

        $order->refresh();

        if ($created) {
            $this->publishShipmentCreated($order);
        }

        return $order;
    }

    /**
     * Ship a single shop's portion of the order.
     */
    private function shipShop(
        Order $order,
        string $shopId,
        ?string $trackingNumber,
        ?string $trackingUrl,
        string $wayBillNo,
    ): Order {
        /** @var array{status: int, created: bool} $result */
        $result = DB::transaction(function () use (
            $order,
            $shopId,
            $trackingNumber,
            $trackingUrl,
            $wayBillNo,
        ): array {
            $lockedOrder = Order::where('order_id', $order->order_id)->lockForUpdate()->first();
            if (!$lockedOrder) {
                throw new BusinessException('Order not found', 404);
            }
            $this->assertWayBillNoCanBeSet($lockedOrder, $wayBillNo);

            $lockedShopOrder = ShopOrder::where('order_id', $order->order_id)
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->first();
            if (!$lockedShopOrder) {
                throw new BusinessException('Shop not found in this order', 404);
            }

            // Idempotent: shop already shipped — success without rewriting tracking/events.
            if (in_array((int) $lockedShopOrder->order_status, [
                OrderStatus::SHIPPED->value,
                OrderStatus::DELIVERED->value,
            ], true)) {
                return [
                    'status' => app(OrderService::class)->derivedOrderStatus($order->order_id),
                    'created' => false,
                ];
            }

            if ($lockedShopOrder->order_status !== OrderStatus::READY_FOR_FULFILLMENT->value) {
                throw new BusinessException(
                    'This shop is not ready for fulfillment',
                    409,
                    ['shop_order_status' => $lockedShopOrder->order_status]
                );
            }
            // delivery_company_id may be null when the shop has no company configured
            // or no route for the buyer — checkout already allows that; shipping must too.

            // Store the order-wide waybill atomically with this shop's shipment.
            $lockedShopOrder->update([
                'tracking_number' => $trackingNumber,
                'courier_code' => null,
                'courier_name' => null,
                'courier_phone' => null,
                'tracking_url' => $trackingUrl,
                'order_status' => OrderStatus::SHIPPED->value,
                'shipment_created_at' => now(),
                'updated_at' => now(),
            ]);

            $derivedStatus = app(OrderService::class)->derivedOrderStatus($order->order_id);
            $this->orders->updateOrderStatus($order->order_id, [
                'order_status' => $derivedStatus,
                'way_bill_no' => $wayBillNo,
                'updated_at' => now(),
            ]);

            return ['status' => $derivedStatus, 'created' => true];
        });

        $order->refresh();

        if (!$result['created']) {
            return $order;
        }

        // Last shop to ship completes fulfillment — tell downstream services
        // (read model flips the buyer-visible status to FULFILLED).
        if ($result['status'] === OrderStatus::FULFILLED->value) {
            $this->publishOrderFulfilled($order);
        }

        $this->publishShipmentCreated($order, $shopId);

        return $order;
    }

    private function assertWayBillNoCanBeSet(Order $order, string $wayBillNo): void
    {
        $existingWayBillNo = trim((string) $order->way_bill_no);
        if ($existingWayBillNo !== '' && !hash_equals($existingWayBillNo, $wayBillNo)) {
            throw new BusinessException(
                'Waybill number cannot be changed after shipment has started',
                409,
                ['way_bill_no' => $existingWayBillNo],
            );
        }
    }

    /**
     * Publish order.fulfilled once every shop in the order has shipped.
     */
    private function publishOrderFulfilled(Order $order): void
    {
        $order->loadMissing('checkoutSession');
        $checkout = $order->checkoutSession;
        if (!$checkout) {
            return;
        }

        $payload = EventPayloadBuilder::build(
            EventTopic::ORDER_FULFILLED,
            $checkout->correlation_id ?? $order->checkout_id,
            [
                'order_id' => $order->order_id,
                'checkout_id' => $order->checkout_id,
                'user_id' => (string) $checkout->user_id,
                'status' => 'FULFILLED',
                'updatedAt' => now()->utc()->toIso8601String(),
            ],
        );
        Publisher::publishStatic(EventTopic::ORDER_FULFILLED, $payload);

        logger()->info('ORDER_FULFILLED_EVENT_PUBLISHED', [
            'order_id' => $order->order_id,
        ]);
    }

    private function generateTrackingNumber(string $orderId): string
    {
        $clean = str_replace('-', '', $orderId);

        return 'TRK-' . strtoupper(Str::substr($clean, 0, 12)) . '-' . time();
    }

    /**
     * Publish shipment.created event. When $shopId is given only that
     * shop's items are included so the read side creates a delivery
     * scoped to that shop. Courier/tracking are read from the
     * shop_order (per-shipment data).
     */
    private function publishShipmentCreated(Order $order, ?string $shopId = null): void
    {
        $order->loadMissing(['checkoutSession.items']);
        $checkout = $order->checkoutSession;
        if (!$checkout) {
            return;
        }

        $deliveryAddress = $checkout->delivery_address ?? [];
        $recipientName = $deliveryAddress['full_name'] ?? $deliveryAddress['recipient_name'] ?? '';
        $recipientPhone = $checkout->delivery_address['phone_no'] ?? $checkout->delivery_address['recipient_phone'] ?? '';

        $pickupAddress = $this->getPickupAddress();
        $shippingAddress = $deliveryAddress;

        $items = $checkout->items
            ->when($shopId, fn ($collection) => $collection->where('shop_id', $shopId))
            ->map(static function (CheckoutItem $item): array {
                return [
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'shop_id'    => $item->shop_id !== null ? (string) $item->shop_id : null,
                    'sku'        => $item->product_sku,
                    'name'       => $item->product_title,
                    'qty'        => (int) $item->qty,
                    'unit_price' => (float) $item->price,
                    'line_total' => isset($item->line_total) ? (float) $item->line_total : ((float) $item->price * (int) $item->qty),
                    'currency'   => $item->currency ?? 'USD',
                ];
            })
            ->values()
            ->all();

        // Courier/tracking come from the affected shop_order; the legacy
        // whole-order path falls back to the order-level columns.
        $so = null;
        if ($shopId !== null) {
            $so = ShopOrder::where('order_id', $order->order_id)
                ->where('shop_id', $shopId)
                ->first();
        }

        $courierCode = $so?->courier_code ?? $order->courier_code ?? '';
        $courierName = $so?->courier_name ?? $order->courier_name ?? '';
        $courierPhone = $so?->courier_phone ?? $order->courier_phone ?? '';
        $trackingNumber = $so?->tracking_number ?? $order->tracking_number;
        $trackingUrl = $so?->tracking_url ?? $order->tracking_url;

        $data = [
            'order_id'        => $order->order_id,
            'order_reference' => $order->checkout_id,
            'customer_id'     => (string) $checkout->user_id,
            'shop_id'         => $shopId,
            'shop_order_id'   => $so?->id,
            'deliveryCompanyId' => $so?->delivery_company_id,
            'order_status'    => OrderStatus::tryFrom((int) $order->order_status)?->name,
            'recipient_name'  => $recipientName,
            'recipient_phone' => $recipientPhone,
            'pickup_address'  => $pickupAddress,
            'delivery_address'=> $deliveryAddress,
            'shipping_address'=> $shippingAddress,
            'items'           => $items,
            'courier'         => [
                'code' => $courierCode,
                'name' => $courierName,
                'phone'=> $courierPhone,
            ],
            'tracking_number' => $trackingNumber,
            'way_bill_no'     => $order->way_bill_no,
            'tracking_url'    => $trackingUrl,
            // Fresh per-shop statuses (the shipping shop is now SHIPPED) so
            // the read model can recompute order-level progress exactly.
            'shop_breakdown'  => app(OrderService::class)->shopBreakdown($order->order_id),
        ];

        $correlationId = $checkout->correlation_id ?? $order->checkout_id;
        $payload = EventPayloadBuilder::build(EventTopic::SHIPMENT_CREATED, $correlationId, $data);
        Publisher::publishStatic(EventTopic::SHIPMENT_CREATED, $payload);

        logger()->info('SHIPMENT_CREATED_EVENT_PUBLISHED', [
            'order_id' => $order->order_id,
            'shop_id'  => $shopId,
            'payload'  => $payload,
        ]);
    }

    private function getPickupAddress(): ?array
    {
        $addr = config('shipping.default_pickup_address', []);
        if (!is_array($addr) || empty(array_filter($addr))) {
            return null;
        }

        return $addr;
    }
}
