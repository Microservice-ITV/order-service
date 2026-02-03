<?php

namespace App\UseCases\Checkout;

use App\DTO\Checkout\StartCheckoutResult;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Services\ProductPricingService;
use App\Services\RabbitMQ\Publisher;
use App\Contracts\CheckoutItemInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StartCheckout
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CheckoutItemInterface $checkoutItems,
        private readonly ProductPricingService $pricingService
    ) {}

    public function handle(array $items, string $rawIdempotencyKey): StartCheckoutResult
    {
        if ($items === []) {
            throw new BusinessException('Cart empty', 400);
        }

        $idempotencyHash = $this->checkoutService->hashIdempotencyKey($rawIdempotencyKey);

        $existing = $this->checkoutService->findByIdempotencyHash($idempotencyHash);
        if ($existing) {
            return new StartCheckoutResult($existing, true);
        }

        // Pricing via Product service (recommended – server-trusted prices).
        $pricingData = $this->pricingService->fetchPricingData($items);
        if ($pricingData['ok'] !== true) {
            throw new BusinessException('Unable to fetch product prices', 502);
        }
        if (($pricingData['missing'] ?? []) !== []) {
            throw new BusinessException('Some products are unavailable', 400);
        }

        try {
            $checkout = $this->createCheckoutSessionAndItems(
                items: $items,
                priceByProduct: $pricingData['priceByProduct'],
                idempotencyHash: $idempotencyHash
            );
        } catch (QueryException $e) {
            // MySQL duplicate key error code.
            $errorCode = (int) ($e->errorInfo[1] ?? 0);
            if ($errorCode === 1062) {
                $existing = $this->checkoutService->findByIdempotencyHash($idempotencyHash);
                if ($existing) {
                    return new StartCheckoutResult($existing, true);
                }
            }
            throw $e;
        }

        $this->publishOrderCheckoutEvent($checkout, $items, $idempotencyHash);

        return new StartCheckoutResult($checkout, false);
    }

    private function createCheckoutSessionAndItems(array $items, array $priceByProduct, string $idempotencyHash): CheckoutSession
    {
        return DB::transaction(function () use ($items, $priceByProduct, $idempotencyHash) {
            $userIds = array_unique(array_column($items, 'user_id'));
            if (count($userIds) !== 1) {
                throw new BusinessException('Multiple user IDs in checkout', 400);
            }

            $userId = (string) $userIds[0];
            $now = now();

            $checkoutSession = $this->checkoutService->create([
                'idempotency_key' => $idempotencyHash,
                'status_id'       => OrderStatus::PENDING_STOCK->value,
                'user_id'         => $userId,
                'reservation_id'  => null,
                'payment_id'      => null,
                'correlation_id'  => $idempotencyHash,
                'expire_at'       => $this->checkoutService->calculateExpireAt(),
            ]);

            $rows = [];
            foreach ($items as $item) {
                $productId = (string) ($item['product_id'] ?? '');
                if ($productId === '' || !isset($priceByProduct[$productId]['unit_price'])) {
                    throw new BusinessException("Missing price for product {$productId}", 400);
                }

                $rows[] = [
                    'checkout_id' => $checkoutSession->id,
                    'product_id'  => $productId,
                    'qty'         => (int) ($item['qty'] ?? 0),
                    'price'       => $priceByProduct[$productId]['unit_price'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            $this->checkoutItems->insertMany($rows);

            return $checkoutSession;
        });
    }

    private function publishOrderCheckoutEvent(CheckoutSession $checkout, array $items, string $correlationId): void
    {
        Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, [
            'event'          => EventTopic::ORDER_CHECKOUT_REQUESTED->value,
            'version'        => 'v1',
            'timestamp'      => now()->utc()->toIso8601String(),
            'producer'       => 'order-service',
            'correlation_id' => $correlationId,
            'data' => [
                'checkout_id' => $checkout->id,
                'items'       => $items,
                'expire_at'   => $checkout->getExpireAtIso(),
            ],
        ]);
    }
}

