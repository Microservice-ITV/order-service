<?php

namespace App\Contracts;

use App\Contracts\BaseInterface;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderInterface extends BaseInterface
{
    public function createPendingOrder(int $userId, array $items, float $total): Order;

    public function paginateByUser(int $userId): LengthAwarePaginator;

    public function getByUserAndId(int $userId, int $orderId): Order;

    public function markAsPaid(int $orderId): void;

    public function markAsCancelled(int $orderId): void;
}
