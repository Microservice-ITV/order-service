<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderService
{
    public function __construct(
        private OrderInterface $orderRepo
    ) {}

    public function list(int $userId): LengthAwarePaginator
    {
        return $this->orderRepo->paginateByUser($userId);
    }

    public function orderDetails(int $userId, int $orderId): Order
    {
        return $this->orderRepo->getByUserAndId($userId, $orderId);
    }

    public function markPaid(int $orderId): void
    {
        $this->orderRepo->markAsPaid($orderId);
    }
}
