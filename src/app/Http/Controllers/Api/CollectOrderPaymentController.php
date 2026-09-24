<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class CollectOrderPaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Collect COD only after the order has been confirmed as delivered. */
    public function __invoke(string $orderId): JsonResponse
    {
        $order = Order::query()->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        if ($order->order_status !== OrderStatus::DELIVERED->value) {
            return response()->json(['error' => 'COD payment can be collected only after delivery'], 409);
        }
        if (!$order->payment_id) {
            return response()->json(['error' => 'Order has no payment record'], 409);
        }

        try {
            $payment = $this->payments->collectPayment($order->payment_id);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'order_id' => $order->order_id,
            'payment_id' => $payment['payment_id'],
            'status' => $payment['status'],
        ]);
    }
}
