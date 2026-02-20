<?php

namespace App\Repositories;

use App\Contracts\OrderInterface;
use App\Models\Order;

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
        return $this->model->newQuery()
            ->where('checkout_id', $checkoutId)
            ->update($data);
    }
}
