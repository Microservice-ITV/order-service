<?php

namespace App\UseCases\Checkout;

use App\DTO\Checkout\StartCheckoutResult;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Services\ProductValidationAndPricingService;
use App\Services\RabbitMQ\Publisher;
use App\Contracts\CheckoutItemInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StartCheckout
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CheckoutItemInterface $checkoutItems,
        private readonly ProductValidationAndPricingService $pricingService
    ) {}

    public function handle(array $items, string $userId, string $rawIdempotencyKey): StartCheckoutResult
    {
        if ($items === []) {
            throw new BusinessException('Cart empty', 400);
        }

        $idempotencyHash = $this->checkoutService->hashIdempotencyKey($rawIdempotencyKey);

        $existing = $this->checkoutService->findByIdempotencyHash($idempotencyHash);
        if ($existing) {
            return new StartCheckoutResult($existing, true);
        }

        // Stock validation + pricing via Product service (server-trusted).
        $validationData = $this->pricingService->validateStockAndFetchPricing($items);
        if ($validationData['ok'] !== true) {
            throw new BusinessException('Unable to validate product stock', 502);
        }
        if ($validationData['stockValid'] !== true) {
            $invalidItems = array_values(array_filter(
                $validationData['items'] ?? [],
                static fn ($row) => !is_array($row) || !($row['stock_validation'] ?? false)
            ));
            throw new BusinessException('OUT_OF_STOCK', 400, ['items' => $invalidItems]);
        }

        try {
            $checkout = $this->createCheckoutSessionAndItems(
                items: $items,
                priceByProduct: $validationData['priceByProduct'],
                userId: $userId,
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

    private function createCheckoutSessionAndItems(array $items, array $priceByProduct, string $userId, string $idempotencyHash): CheckoutSession
    {
        return DB::transaction(function () use ($items, $priceByProduct, $idempotencyHash, $userId) {
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
        $payload = [
            'event'          => EventTopic::ORDER_CHECKOUT_REQUESTED->value,
            'version'        => 'v1',
            'timestamp'      => now()->utc()->toIso8601String(),
            'producer'       => 'order-service',
            'correlation_id' => $correlationId,
            'data' => [
                'checkout_id' => $checkout->id,
                'items'       => $items,
                'expires_at'   => $checkout->getExpireAtIso(),
            ],
        ];

        Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, $payload);

        logger()->info('ORDER_CHECKOUT_REQUESTED EVENT PUBLISHED', [
            'checkout_id' => $checkout->id,
            'payload' => $payload,
        ]);
    }
}

