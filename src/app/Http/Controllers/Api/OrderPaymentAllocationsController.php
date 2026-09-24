<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-shop payment allocations (order_amount / discount / shipping / fee /
 * payout) for an order, proxied from payment-service. Clients pass their own
 * shop ids via ?shop_ids= so seller views only expose their shops' splits.
 */
class OrderPaymentAllocationsController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request, string $orderId): JsonResponse
    {
        $order = Order::query()->where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }

        // Unpaid orders (and COD before collection) have no allocations yet.
        if ($order->payment_id === null || $order->payment_id === '') {
            return response()->json(['data' => []]);
        }

        $shopIds = array_values(array_filter(
            explode(',', (string) $request->query('shop_ids', '')),
            static fn ($id): bool => is_string($id) && $id !== '',
        ));

        try {
            $allocations = $this->payments->getPaymentAllocations(
                (string) $order->payment_id,
                $shopIds,
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => array_values($allocations)]);
    }
}
