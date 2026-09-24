<?php

namespace App\Contracts;

use App\Models\ShopOrder;

interface ShopOrderInterface extends BaseInterface
{
    public function createByOrderId(string $orderId, array $attributes): ShopOrder;

    public function findByOrderId(string $orderId): array;
}
