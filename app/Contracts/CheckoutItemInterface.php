<?php

namespace App\Contracts;

interface CheckoutItemInterface extends BaseInterface
{
    public function insertMany(array $rows): bool;
}

