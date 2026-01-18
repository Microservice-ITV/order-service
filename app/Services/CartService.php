<?php

namespace App\Services;

use App\Clients\ProductClient;
use App\Exceptions\BusinessException;
use App\Contracts\CartRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(
        private CartRepositoryInterface $cartRepo,
        private ProductClient $productClient
    ) {}

    public function getCart(int $userId)
    {
        return $this->cartRepo->getByUser($userId);
    }

    public function addItem(int $userId, array $data): void
    {
        $product = $this->productClient->getProduct($data['product_id']);

        if (empty($product['price']) || $product['price'] <= 0) {
            throw new BusinessException('Invalid product price');
        }

        DB::transaction(
            fn() =>
            $this->cartRepo->addOrIncreaseItem(
                $userId,
                $data['product_id'],
                $data['quantity'],
                (float) $product['price']
            )
        );
    }

    public function updateItem(int $userId, int $itemId, int $qty): void
    {
        if ($qty < 1) {
            throw new BusinessException('Quantity must be at least 1');
        }

        $this->cartRepo->updateQuantity($userId, $itemId, $qty);
    }

    public function removeItem(int $userId, int $itemId): void
    {
        $this->cartRepo->removeItem($userId, $itemId);
    }

    public function clearCart(int $userId): void
    {
        $this->cartRepo->clear($userId);
    }
}
