<?php

namespace App\Repositories\Eloquent;

use App\Models\Order;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    public function createOrder(int $userId, array $items, float $total): Order
    {
        $order = Order::create([
            'user_id'      => $userId,
            'total_amount' => $total,
            'status'       => Order::STATUS_PENDING,
        ]);

        $order->items()->createMany($items);

        return $order;
    }

    public function paginateByUser(int $userId): LengthAwarePaginator
    {
        return Order::with('items')
            ->where('user_id', $userId)
            ->latest()
            ->paginate(10);
    }

    public function getByUserAndId(int $userId, int $orderId): Order
    {
        return Order::with('items')
            ->where('user_id', $userId)
            ->where('id', $orderId)
            ->firstOrFail();
    }
}
