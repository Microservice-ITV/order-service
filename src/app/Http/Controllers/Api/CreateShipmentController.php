<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateShipmentRequest;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;

class CreateShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipmentService
    ) {}

    /**
     * Create a shipment for an order. Pass `shop_id` to ship only that
     * shop's portion (multi-owner orders — per-shop fulfillment);
     * omit it to ship the whole order at once.
     */
    public function __invoke(CreateShipmentRequest $request, string $orderId): JsonResponse
    {
        $shopId = $request->validated('shop_id');

        try {
            $order = $this->shipmentService->createShipment(
                $orderId,
                $request->validated(),
                $shopId,
            );
        } catch (BusinessException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                ...$e->getData(),
            ], $e->getStatus());
        }

        // Per-shop shipments carry courier/tracking on the shop_order row;
        // legacy whole-order shipments keep them on the order.
        $shopOrder = $shopId
            ? \App\Models\ShopOrder::where('order_id', $order->order_id)
                ->where('shop_id', $shopId)
                ->first()
            : null;

        return response()->json([
            'order_id' => $order->order_id,
            'order_status' => \App\Enums\OrderStatus::tryFrom((int) $order->order_status)?->name ?? 'SHIPPED',
            'shop_id' => $shopId,
            'message' => 'Shipment created successfully',
            'courier' => [
                'code' => $shopOrder?->courier_code ?? $order->courier_code,
                'name' => $shopOrder?->courier_name ?? $order->courier_name,
                'phone' => $shopOrder?->courier_phone ?? $order->courier_phone,
            ],
            'tracking_number' => $shopOrder?->tracking_number ?? $order->tracking_number,
            'way_bill_no' => $order->way_bill_no,
            'tracking_url' => $shopOrder?->tracking_url ?? $order->tracking_url,
            'shipment_created_at' => ($shopOrder?->shipment_created_at ?? $order->shipment_created_at)?->toIso8601String(),
        ], 201);
    }
}
