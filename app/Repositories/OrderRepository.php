<?php

namespace App\Repositories;

use App\Contracts\OrderInterface;
use App\Models\Order;
use App\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderRepository extends BaseRepository implements OrderInterface
{
    public function __construct(Order $model)
    {
        parent::__construct($model);
    }

    /**
     * Order creation is NEVER a simple create()
     */
    public function createPendingOrder(
        int $userId,
        array $items,
        float $total
    ): Order {
        $order = $this->model->create([
            'user_id'      => $userId,
            'status'       => Order::STATUS_PENDING,
            'total_amount' => $total,
        ]);

        $order->items()->createMany($items);

        return $order;
    }

    public function paginateByUser(int $userId): LengthAwarePaginator
    {
        return $this->model
            ->with('items')
            ->where('user_id', $userId)
            ->latest()
            ->paginate(10);
    }

    public function getByUserAndId(int $userId, int $orderId): Order
    {
        return $this->model
            ->with('items')
            ->where('user_id', $userId)
            ->where('id', $orderId)
            ->firstOrFail();
    }

    public function markAsPaid(int $orderId): void
    {
        $this->model
            ->where('id', $orderId)
            ->update(['status' => Order::STATUS_PAID]);
    }

    public function markAsCancelled(int $orderId): void
    {
        $this->model
            ->where('id', $orderId)
            ->update(['status' => Order::STATUS_CANCELLED]);
    }
}
