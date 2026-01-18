<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CartItem;
use App\Models\Order;
use App\Contracts\CartRepositoryInterface;
use App\Repositories\Interfaces\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private CartRepositoryInterface $cartRepo,
        private OrderRepositoryInterface $orderRepo
    ) {}

    public function checkout(int $userId): Order
    {
        return DB::transaction(function () use ($userId) {

            $cart = $this->cartRepo->lockByUser($userId);

            if ($cart->items->isEmpty()) {
                throw new BusinessException('Cart is empty');
            }

            [$items, $total] = $this->buildOrderItems($cart->items);

            $order = $this->orderRepo->createOrder($userId, $items, $total);

            $this->cartRepo->clear($userId);

            return $order->load('items');
        });
    }

    public function getOrders(int $userId): LengthAwarePaginator
    {
        return $this->orderRepo->paginateByUser($userId);
    }

    public function getOrderDetail(int $userId, int $orderId): Order
    {
        return $this->orderRepo->getByUserAndId($userId, $orderId);
    }

    private function buildOrderItems(iterable $cartItems): array
    {
        $items = [];
        $total = 0.0;

        foreach ($cartItems as $cartItem) {
            $lineTotal = $this->calculateLineTotal($cartItem);

            $items[] = [
                'product_id'   => $cartItem->product_id,
                'product_name' => $cartItem->product_name,
                'quantity'     => $cartItem->quantity,
                'unit_price'   => $cartItem->unit_price,
                'total_price'  => $lineTotal,
            ];

            $total += $lineTotal;
        }

        return [$items, round($total, 2)];
    }

    private function calculateLineTotal(CartItem $item): float
    {
        return round($item->unit_price * $item->quantity, 2);
    }
}
