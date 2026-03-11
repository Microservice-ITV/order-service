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
     * Create a shipment for an order. Order must be in READY_FOR_FULFILLMENT status.
     */
    public function __invoke(CreateShipmentRequest $request, string $orderId): JsonResponse
    {
        try {
            $order = $this->shipmentService->createShipment($orderId, $request->validated());
        } catch (BusinessException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                ...$e->getData(),
            ], $e->getStatus());
        }

        return response()->json([
            'order_id' => $order->order_id,
            'status' => 'SHIPPED',
            'message' => 'Shipment created successfully',
            'courier' => [
                'code' => $order->courier_code,
                'name' => $order->courier_name,
                'phone' => $order->courier_phone,
            ],
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_url,
            'shipment_created_at' => $order->shipment_created_at?->toIso8601String(),
        ], 201);
    }
}
