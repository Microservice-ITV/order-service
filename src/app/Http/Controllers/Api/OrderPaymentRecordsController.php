<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPaymentRecordsController extends Controller
{
    private const int MAX_ORDER_IDS_PER_REQUEST = 200;

    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): JsonResponse
    {
        $orderIds = array_values(array_filter(
            explode(',', (string) $request->query('order_ids', '')),
            static fn ($id) => is_string($id) && $id !== '',
        ));
        $shopIds = array_values(array_filter(
            explode(',', (string) $request->query('shop_ids', '')),
            static fn ($id) => is_string($id) && $id !== '',
        ));

        if ($shopIds !== []) {
            $orderIds = $this->orderIdsForShops($shopIds);
        }

        try {
            $payments = $this->fetchPayments($orderIds);

            $statusMap = $this->orderStatusMap(array_map(
                static fn (array $payment): string => (string) ($payment['orderId'] ?? ''),
                $payments,
            ));

            $rows = array_map(
                static fn (array $payment): array => [
                    ...$payment,
                    'deliveryStatus' => $statusMap[(string) ($payment['orderId'] ?? '')]['orderStatus'] ?? null,
                    'deliveryStatusId' => $statusMap[(string) ($payment['orderId'] ?? '')]['orderStatusId'] ?? null,
                ],
                $payments,
            );

            return response()->json(['data' => array_values($rows)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /** @param string[] $orderIds @return array<int, array<string, mixed>> */
    private function fetchPayments(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($orderIds, self::MAX_ORDER_IDS_PER_REQUEST) as $chunk) {
            foreach ($this->payments->getPaymentsForOrders($chunk) as $payment) {
                $rows[] = (array) $payment;
            }
        }

        return $rows;
    }

    /** @param string[] $shopIds @return string[] */
    private function orderIdsForShops(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }

        return Order::query()
            ->whereHas(
                'checkoutSession.items',
                static fn ($query) => $query->whereIn('shop_id', $shopIds),
            )
            ->pluck('order_id')
            ->all();
    }

    /**
     * @param string[] $orderIds
     * @return array<string, array{orderStatusId: int|null, orderStatus: string|null, paymentId: string|null}>
     */
    private function orderStatusMap(array $orderIds): array
    {
        $orderIds = array_values(array_filter($orderIds));

        if ($orderIds === []) {
            return [];
        }

        $map = [];
        Order::query()
            ->with('status:id,name')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->each(function (Order $order) use (&$map): void {
                $map[$order->order_id] = [
                    'orderStatusId' => $order->status_id,
                    'orderStatus' => $order->status?->name,
                    'paymentId' => $order->payment_id,
                ];
            });

        return $map;
    }
}