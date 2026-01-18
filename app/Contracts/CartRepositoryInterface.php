<?php

namespace App\Contracts;

use App\Contracts\BaseInterface;
use App\Models\Cart;

interface CartRepositoryInterface extends BaseInterface
{
    // Query (with DB lock to prevent double checkout)
    public function getByUser(int $userId): Cart;
    public function lockByUser(int $userId): Cart;

    // Commands
    public function addOrIncreaseItem(int $userId, int $productId, int $qty, float $price): void;
    public function updateQuantity(int $userId, int $itemId, int $qty): void;
    public function removeItem(int $userId, int $itemId): void;
    public function clear(int $userId): void;
}