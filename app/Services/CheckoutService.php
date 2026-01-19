<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Contracts\CartInterface;
use App\Contracts\OrderInterface;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(
        private CartInterface $cartRepo,
        private OrderInterface $orderRepo
    ) {}

    public function placeOrder(int $userId)
    {
        return DB::transaction(function () use ($userId) {

            $cart = $this->cartRepo->lockByUser($userId);

            if ($cart->items->isEmpty()) {
                throw new BusinessException('Cart is empty');
            }

            [$items, $total] = $this->buildOrderItems($cart->items);

            $order = $this->orderRepo->createPendingOrder($userId, $items, $total);

            $this->cartRepo->clear($userId);

            return $order->load('items');
        }, 3); // retry on deadlock
    }

    private function buildOrderItems(iterable $cartItems): array
    {
        $items = [];
        $total = 0;

        foreach ($cartItems as $item) {
            $lineTotal = round($item->price_snapshot * $item->quantity, 2);

            $items[] = [
                'product_id'   => $item->product_id,
                'product_name' => $item->product_name,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->price_snapshot,
                'total_price'  => $lineTotal,
            ];

            $total += $lineTotal;
        }

        return [$items, round($total, 2)];
    }
}