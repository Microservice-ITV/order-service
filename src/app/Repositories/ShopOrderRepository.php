<?php

namespace App\Repositories;

use App\Contracts\ShopOrderInterface;
use App\Models\ShopOrder;

final class ShopOrderRepository extends BaseRepository implements ShopOrderInterface
{
    public function __construct(ShopOrder $model)
    {
        $this->setModel($model);
    }

    public function createByOrderId(string $orderId, array $attributes): ShopOrder
    {
        $attributes['order_id'] = $orderId;

        /** @var ShopOrder $shopOrder */
        $shopOrder = $this->create($attributes);

        return $shopOrder;
    }

    public function findByOrderId(string $orderId): array
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->get()
            ->all();
    }
}
