<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ShopOrder;
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
            $payments = $this->fetchPayments($orderIds, $shopIds);

            $paymentOrderIds = array_map(
                static fn (array $payment): string => (string) ($payment['orderId'] ?? ''),
                $payments,
            );

            $statusMap = $this->orderStatusMap($paymentOrderIds);
            $sellerStatusMap = $this->sellerDeliveryStatusMap($paymentOrderIds, $shopIds);

            $rows = array_map(
                static fn (array $payment): array => [
                    ...$payment,
                    'deliveryStatus' => $statusMap[(string) ($payment['orderId'] ?? '')]['orderStatus'] ?? null,
                    'deliveryStatusId' => $statusMap[(string) ($payment['orderId'] ?? '')]['orderStatusId'] ?? null,
                    // Seller-scoped fulfillment progress (their shops only).
                    'sellerDeliveryStatus' => $sellerStatusMap[(string) ($payment['orderId'] ?? '')] ?? null,
                ],
                $payments,
            );

            return response()->json(['data' => array_values($rows)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /** @param string[] $orderIds @param string[] $shopIds @return array<int, array<string, mixed>> */
    private function fetchPayments(array $orderIds, array $shopIds = []): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($orderIds, self::MAX_ORDER_IDS_PER_REQUEST) as $chunk) {
            foreach ($this->payments->getPaymentsForOrders($chunk, $shopIds) as $payment) {
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
     * Aggregate fulfillment status across the SELLER's own shop_orders for
     * each order. Their shops move independently, so the honest view is:
     * all delivered → DELIVERED; all shipped-or-beyond → SHIPPED; otherwise
     * the least-advanced shop status (they still have work to do).
     *
     * @param string[] $orderIds
     * @param string[] $shopIds
     * @return array<string, string|null>
     */
    private function sellerDeliveryStatusMap(array $orderIds, array $shopIds): array
    {
        $orderIds = array_values(array_filter($orderIds));
        if ($orderIds === [] || $shopIds === []) {
            return [];
        }

        /** @var array<string, int[]> $statusesByOrder */
        $statusesByOrder = [];
        ShopOrder::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('shop_id', $shopIds)
            ->get(['order_id', 'order_status'])
            ->each(static function (ShopOrder $shopOrder) use (&$statusesByOrder): void {
                $statusesByOrder[(string) $shopOrder->order_id][] = (int) $shopOrder->order_status;
            });

        $map = [];
        foreach ($statusesByOrder as $orderId => $statuses) {
            $shippedLike = [
                OrderStatus::SHIPPED->value,
                OrderStatus::DELIVERED->value,
                OrderStatus::FULFILLED->value,
            ];
            $delivered = array_filter($statuses, static fn (int $s): bool => $s === OrderStatus::DELIVERED->value);
            $shipped = array_filter($statuses, static fn (int $s): bool => in_array($s, $shippedLike, true));

            if ($delivered !== [] && count($delivered) === count($statuses)) {
                $map[$orderId] = OrderStatus::DELIVERED->name;
            } elseif ($shipped !== [] && count($shipped) === count($statuses)) {
                $map[$orderId] = OrderStatus::SHIPPED->name;
            } else {
                $map[$orderId] = OrderStatus::tryFrom((int) min($statuses))?->name;
            }
        }

        return $map;
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
            ->with('orderStatus:id,name')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->each(function (Order $order) use (&$map): void {
                $map[$order->order_id] = [
                    'orderStatusId' => $order->order_status,
                    'orderStatus' => $order->orderStatus?->name,
                    'paymentId' => $order->payment_id,
                ];
            });

        return $map;
    }
}