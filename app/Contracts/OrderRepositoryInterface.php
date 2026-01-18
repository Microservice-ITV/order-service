<?php

namespace App\Repositories\Interfaces;

use App\Contracts\BaseInterface;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface extends BaseInterface
{
    public function createOrder(int $userId, array $items, float $total): Order;

    public function paginateByUser(int $userId): LengthAwarePaginator;

    public function getByUserAndId(int $userId, int $orderId): Order;
}
