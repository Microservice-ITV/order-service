<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\CheckoutItem;
use App\Models\Order;
use App\Services\EventPayloadBuilder;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Str;

final class ShipmentService
{
    public function __construct(
        private readonly OrderInterface $orders
    ) {}

    /**
     * Create a shipment for an order. Validates order status, persists courier/tracking
     * data, updates status to SHIPPED, and publishes shipment.created event.
     *
     * @param array{courier_code: string, courier_name: string, courier_phone: string, tracking_number?: string|null, tracking_url?: string|null} $input
     * @return Order
     * @throws BusinessException
     */
    public function createShipment(string $orderId, array $input): Order
    {
        /** @var \App\Models\Order|null $order */
        $order = $this->orders->findById($orderId);
        if (!$order) {
            throw new BusinessException('Order not found', 404);
        }

        if ($order->status_id !== OrderStatus::READY_FOR_FULFILLMENT->value) {
            throw new BusinessException(
                'Order must be in READY_FOR_FULFILLMENT status to create shipment',
                409,
                ['current_status_id' => $order->status_id]
            );
        }

        $trackingNumber = $input['tracking_number'] ?? null;
        $trackingUrl = $input['tracking_url'] ?? null;

        if (empty($trackingNumber) && config('shipping.auto_generate_tracking', true)) {
            $trackingNumber = $this->generateTrackingNumber($orderId);
        }

        $updateData = [
            'courier_code' => $input['courier_code'],
            'courier_name' => $input['courier_name'],
            'courier_phone' => $input['courier_phone'],
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'status_id' => OrderStatus::SHIPPED->value,
            'shipment_created_at' => now(),
            'updated_at' => now(),
        ];

        $this->orders->updateByOrderId($orderId, $updateData);

        $order->refresh();

        $this->publishShipmentCreated($order);

        return $order;
    }

    private function generateTrackingNumber(string $orderId): string
    {
        $clean = str_replace('-', '', $orderId);

        return 'TRK-' . strtoupper(Str::substr($clean, 0, 12)) . '-' . time();
    }

    private function publishShipmentCreated(Order $order): void
    {
        $order->loadMissing(['checkoutSession.items']);
        $checkout = $order->checkoutSession;
        if (!$checkout) {
            return;
        }

        $deliveryAddress = $checkout->delivery_address ?? [];
        $recipientName = $deliveryAddress['full_name'] ?? $deliveryAddress['recipient_name'] ?? '';
        $recipientPhone = $deliveryAddress['phone_no'] ?? $deliveryAddress['recipient_phone'] ?? '';

        $pickupAddress = $this->getPickupAddress();
        $shippingAddress = $deliveryAddress;

        $items = $checkout->items->map(static function (CheckoutItem $item): array {
            return [
                'product_id' => $item->product_id,
                'sku' => $item->product_sku,
                'name' => $item->product_title,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->price,
                'line_total' => isset($item->line_total) ? (float) $item->line_total : ((float) $item->price * (int) $item->qty),
                'currency' => $item->currency ?? 'USD',
            ];
        })->values()->all();

        $data = [
            'order_id' => $order->order_id,
            'order_reference' => $order->checkout_id,
            'customer_id' => (string) $checkout->user_id,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'pickup_address' => $pickupAddress,
            'delivery_address' => $deliveryAddress,
            'shipping_address' => $shippingAddress,
            'items' => $items,
            'courier' => [
                'code' => $order->courier_code,
                'name' => $order->courier_name,
                'phone' => $order->courier_phone,
            ],
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_url,
        ];

        $correlationId = $checkout->correlation_id ?? $order->checkout_id;
        $payload = EventPayloadBuilder::build(EventTopic::SHIPMENT_CREATED, $correlationId, $data);
        Publisher::publishStatic(EventTopic::SHIPMENT_CREATED, $payload);

        logger()->info('SHIPMENT_CREATED_EVENT_PUBLISHED', [
            'order_id' => $order->order_id,
            'payload' => $payload,
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
