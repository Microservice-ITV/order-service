<?php

namespace App\Repositories;

use App\Contracts\OrderInterface;
use App\Models\Order;
use App\Models\ShopOrder;

final class OrderRepository extends BaseRepository implements OrderInterface
{
    public function __construct(Order $model)
    {
        $this->setModel($model);
    }

    public function createOrder(array $attributes): Order
    {
        /** @var Order $order */
        $order = $this->create($attributes);

        return $order;
    }

    public function findByCheckoutId(string $checkoutId): ?Order
    {
        /** @var Order|null $order */
        $order = $this->model->newQuery()
            ->where('checkout_id', $checkoutId)
            ->first();

        return $order;
    }

    public function updateByCheckoutId(string $checkoutId, array $data): int
    {
        $updated = $this->model->newQuery()
            ->where('checkout_id', $checkoutId)
            ->update($data);

        if ($updated > 0 && array_key_exists('order_status', $data)) {
            $orderIds = $this->model->newQuery()
                ->where('checkout_id', $checkoutId)
                ->pluck('order_id');

            $this->syncShopOrdersStatus($orderIds, (int) $data['order_status']);
        }

        return $updated;
    }

    public function updateByOrderId(string $orderId, array $data): int
    {
        $updated = $this->model->newQuery()
            ->where('order_id', $orderId)
            ->update($data);

        if ($updated > 0 && array_key_exists('order_status', $data)) {
            $this->syncShopOrdersStatus([$orderId], (int) $data['order_status']);
        }

        return $updated;
    }

    /**
     * Update the order-level status WITHOUT syncing shop_orders.
     * Use this for intermediate states (PARTIALLY_FULFILLED, FULFILLED,
     * READY_FOR_FULFILLMENT) where per-shop statuses are managed
     * independently.
     */
    public function updateOrderStatus(string $orderId, array $data): int
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->update($data);
    }

    /**
     * Keep shop_orders.order_status in sync with the parent order's status.
     */
    private function syncShopOrdersStatus(iterable $orderIds, int $status): void
    {
        ShopOrder::whereIn('order_id', $orderIds)
            ->where('order_status', '!=', $status)
            ->update([
                'order_status' => $status,
                'updated_at' => now(),
            ]);
    }
}
