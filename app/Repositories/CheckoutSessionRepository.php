<?php

namespace App\Repositories;

use App\Contracts\CheckoutSessionInterface;
use App\Models\CheckoutSession;

final class CheckoutSessionRepository extends BaseRepository implements CheckoutSessionInterface
{
    public function __construct(CheckoutSession $model)
    {
        $this->setModel($model);
    }

    public function findByIdempotencyHash(string $hash): ?CheckoutSession
    {
        /** @var ?CheckoutSession $checkout */
        $checkout = $this->model->newQuery()
            ->where('idempotency_key', $hash)
            ->first();

        return $checkout;
    }

    public function updateWhere(array $where, array $data): int
    {
        return $this->model->newQuery()
            ->where($where)
            ->update($data);
    }
}

