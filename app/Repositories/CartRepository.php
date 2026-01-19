<?php

namespace App\Repositories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Contracts\CartInterface;
use App\Repositories\BaseRepository;

class CartRepository extends BaseRepository implements CartInterface
{
    public function getByUser(int $userId): Cart
    {
        return Cart::with('items')->firstOrCreate(['user_id' => $userId]);
    }

    public function lockByUser(int $userId): Cart
    {
        return Cart::where('user_id', $userId)
            ->lockForUpdate()
            ->with('items')
            ->firstOrCreate(['user_id' => $userId]);
    }

    public function addOrIncreaseItem(int $userId, int $productId, int $qty, float $price): void
    {
        $cart = $this->lockByUser($userId);

        $item = $cart->items()->where('product_id', $productId)->first();

        $item
            ? $item->increment('quantity', $qty)
            : $cart->items()->create([
                'product_id' => $productId,
                'quantity' => $qty,
                'price_snapshot' => $price,
            ]);
    }

    public function updateQuantity(int $userId, int $itemId, int $qty): void
    {
        CartItem::where('id', $itemId)
            ->whereHas('cart', fn($q) => $q->where('user_id', $userId))
            ->update(['quantity' => $qty]);
    }

    public function removeItem(int $userId, int $itemId): void
    {
        CartItem::where('id', $itemId)
            ->whereHas('cart', fn($q) => $q->where('user_id', $userId))
            ->delete();
    }

    public function clear(int $userId): void
    {
        Cart::where('user_id', $userId)
            ->firstOrFail()
            ->items()
            ->delete();
    }
}
