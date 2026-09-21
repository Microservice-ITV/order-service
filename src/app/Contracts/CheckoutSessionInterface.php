<?php

namespace App\Contracts;

use App\Models\CheckoutSession;

interface CheckoutSessionInterface extends BaseInterface
{
    public function findByIdempotencyHash(string $hash): ?CheckoutSession;

    public function findWithItemsById(string $checkoutId): ?CheckoutSession;

    public function updateWhere(array $where, array $data): int;
}

