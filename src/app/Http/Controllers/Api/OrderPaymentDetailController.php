<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class OrderPaymentDetailController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(string $paymentId): JsonResponse
    {
        try {
            $data = $this->payments->getPaymentDetail($paymentId);
        } catch (\Throwable $e) {
            $status = $e->getMessage() === 'payment_not_found' ? 404 : 502;
            return response()->json(['error' => $e->getMessage()], $status);
        }

        $orderId = (string) ($data['orderId'] ?? '');
        if ($orderId !== '') {
            $order = Order::query()
                ->with('orderStatus:id,name')
                ->where('order_id', $orderId)
                ->first();

            if ($order) {
                $data['order'] = [
                    'orderId' => $order->order_id,
                    'checkoutId' => $order->checkout_id,
                    'paymentId' => $order->payment_id,
                    'reservationId' => $order->reservation_id,
                    'orderStatusId' => $order->order_status,
                    'orderStatus' => $order->orderStatus?->name,
                    'trackingNumber' => $order->tracking_number,
                    'trackingUrl' => $order->tracking_url,
                    'courierCode' => $order->courier_code,
                    'courierName' => $order->courier_name,
                    'shipmentCreatedAt' => $order->shipment_created_at?->toIso8601String(),
                ];
            }
        }

        return response()->json(['data' => $data]);
    }
}