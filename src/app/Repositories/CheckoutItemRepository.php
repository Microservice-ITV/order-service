<?php

namespace App\Repositories;

use App\Contracts\CheckoutItemInterface;
use App\Models\CheckoutItem;

final class CheckoutItemRepository extends BaseRepository implements CheckoutItemInterface
{
    public function __construct(CheckoutItem $model)
    {
        $this->setModel($model);
    }

    public function insertMany(array $rows): bool
    {
        return $this->model->newQuery()->insert($rows);
    }
}

