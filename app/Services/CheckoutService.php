<?php

namespace App\Services;

use App\Contracts\CheckoutItemInterface;
use App\Contracts\CheckoutSessionInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Logging\LogMessage;
use App\Services\EventPayloadBuilder;
use App\Models\CheckoutSession;
use App\Services\ProductValidationAndPricingService;
use App\Services\RabbitMQ\Publisher;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(
        private readonly CheckoutSessionInterface $checkoutSessions,
        private readonly CheckoutItemInterface $checkoutItems,
        private readonly ProductValidationAndPricingService $pricingService
    ) {}

    /**
     * Calculate the expiration datetime for a checkout session.
     * Uses the configured expiration minutes from config.
     */
    public function calculateExpireAt(): Carbon
    {
        return now()
            ->utc()
            ->addMinutes((int) config('checkout.expire_after_minutes', 15));
    }

    /**
     * Hash an idempotency key for secure storage.
     * Truncates keys longer than 255 characters and hashes using app key.
     */
    public function hashIdempotencyKey(string $rawKey): string
    {
        if (\strlen($rawKey) > 255) {
            $rawKey = substr($rawKey, 0, 255);
        }

        return hash_hmac('sha256', $rawKey, (string) config('app.key'));
    }

    /**
     * Find a checkout session by its idempotency key hash.
     * Returns the checkout session if found, null otherwise.
     */
    public function findByIdempotencyHash(string $hash): ?CheckoutSession
    {
        return $this->checkoutSessions->findByIdempotencyHash($hash);
    }

    public function findById(string $checkoutId): ?CheckoutSession
    {
        return $this->checkoutSessions->findById($checkoutId);
    }

    /**
     * Validate that a checkout session exists and is not expired.
     * Returns the checkout session if valid, null otherwise.
     */
    public function validateCheckout(string $checkoutId): ?CheckoutSession
    {
        /** @var ?CheckoutSession $checkout */
        $checkout = $this->checkoutSessions->findById($checkoutId);

        if (!$checkout) {
            return null;
        }

        if ($checkout->isExpired()) {
            logger()->warning(LogMessage::CHECKOUT_EXPIRED, [
                'checkout_id' => $checkoutId,
                'expire_at'   => $checkout->getExpireAtIso(),
            ]);
            return null;
        }

        return $checkout;
    }

    public function create(array $attributes): CheckoutSession
    {
        /** @var CheckoutSession $checkout */
        $checkout = $this->checkoutSessions->create([
            'id'        => (string) Str::uuid(),
            'status_id' => OrderStatus::PENDING_STOCK,
            ...$attributes,
        ]);

        logger()->info(LogMessage::CHECKOUT_CREATED, [
            'checkout_id'    => $checkout->id,
            'correlation_id' => $checkout->correlation_id,
        ]);

        return $checkout;
    }

    public function reject(string $checkoutId, ?string $reason = null): bool
    {
        return $this->transitionStatus(
            $checkoutId,
            OrderStatus::PENDING_STOCK,
            [
                'status_id'      => OrderStatus::FAILED_STOCK->value,
                'failure_reason' => $reason,
                'updated_at'     => now(),
            ],
            LogMessage::CHECKOUT_REJECTED_STOCK_FAILED,
            ['reason' => $reason]
        );
    }

    public function markStockConfirmed(string $checkoutId, float $total, string $reservationId): bool
    {
        return $this->transitionStatus(
            $checkoutId,
            OrderStatus::PENDING_STOCK,
            [
                'status_id'     => OrderStatus::STOCK_CONFIRMED->value,
                'total'         => $total,
                'reservation_id' => $reservationId,
                'updated_at'    => now(),
            ],
            LogMessage::CHECKOUT_STOCK_CONFIRMED,
            ['total' => $total, 'reservation_id' => $reservationId],
            LogMessage::CHECKOUT_STATE_STOCK_IGNORED,
            ['from' => OrderStatus::PENDING_STOCK->name, 'to' => OrderStatus::STOCK_CONFIRMED->name],
            'debug'
        );
    }

    public function markPendingPayment(string $checkoutId): bool
    {
        return $this->transitionStatus(
            $checkoutId,
            OrderStatus::STOCK_CONFIRMED,
            [
                'status_id'  => OrderStatus::PENDING_PAYMENT->value,
                'updated_at' => now(),
            ],
            LogMessage::CHECKOUT_MOVED_TO_PENDING_PAYMENT
        );
    }

    public function confirm(string $checkoutId): bool
    {
        return $this->transitionStatus(
            $checkoutId,
            OrderStatus::PENDING_PAYMENT,
            [
                'status_id'  => OrderStatus::PAID->value,
                'updated_at' => now(),
            ],
            LogMessage::CHECKOUT_PAYMENT_CONFIRMED
        );
    }

    /**
     * Attach payment intent metadata (payment_id + client_secret) to checkout session.
     */
    public function updatePaymentIntent(string $checkoutId, string $paymentId, string $clientSecret): bool
    {
        $updated = $this->checkoutSessions->updateWhere(
            [['id', '=', $checkoutId]],
            [
                'payment_id'    => $paymentId,
                'client_secret' => $clientSecret,
                'updated_at'    => now(),
            ]
        );

        return $updated === 1;
    }

    public function fail(string $checkoutId, ?string $reason = null): bool
    {
        return $this->transitionStatus(
            $checkoutId,
            OrderStatus::PENDING_PAYMENT,
            [
                'status_id'      => OrderStatus::FAILED_PAYMENT->value,
                'failure_reason' => $reason,
                'updated_at'     => now(),
            ],
            LogMessage::CHECKOUT_PAYMENT_FAILED,
            ['reason' => $reason]
        );
    }

    public function cancelByUser(string $checkoutId, ?string $reason = null): array
    {
        /** @var ?CheckoutSession $checkout */
        $checkout = $this->checkoutSessions->findById($checkoutId);
        if (!$checkout) {
            return [
                'not_found' => true,
                'already_cancelled' => false,
                'invalid_state' => false,
                'status' => null,
            ];
        }

        if ($checkout->status_id === OrderStatus::CANCELLED) {
            return [
                'not_found' => false,
                'already_cancelled' => true,
                'invalid_state' => false,
                'status' => OrderStatus::CANCELLED->name,
            ];
        }

        $allowedFrom = [
            OrderStatus::PENDING_STOCK,
            OrderStatus::STOCK_CONFIRMED,
            OrderStatus::PENDING_PAYMENT,
        ];

        if (!in_array($checkout->status_id, $allowedFrom, true)) {
            return [
                'not_found' => false,
                'already_cancelled' => false,
                'invalid_state' => true,
                'status' => $checkout->status_id->name,
            ];
        }

        $updated = $this->checkoutSessions->updateWhere(
            [
                ['id', '=', $checkoutId],
                ['status_id', '=', $checkout->status_id->value],
            ],
            [
                'status_id' => OrderStatus::CANCELLED->value,
                'failure_reason' => $reason ?? 'USER_CANCELLED',
                'updated_at' => now(),
            ]
        );

        if ($updated !== 1) {
            return [
                'not_found' => false,
                'already_cancelled' => false,
                'invalid_state' => true,
                'status' => $checkout->status_id->name,
            ];
        }

        $latest = $this->checkoutSessions->findById($checkoutId);
        $correlationId = (string) ($latest?->correlation_id ?? '');
        if ($correlationId !== '') {
            $payload = EventPayloadBuilder::build(EventTopic::ORDER_CANCELLED, $correlationId, [
                'order_id' => $latest?->order?->order_id,
                'checkout_id' => $checkoutId,
                'user_id' => (string) ($latest?->user_id ?? ''),
                'status' => 'CANCELLED',
                'updatedAt' => now()->utc()->toIso8601String(),
                'reason' => $reason ?? 'USER_CANCELLED',
            ]);
            Publisher::publishStatic(EventTopic::ORDER_CANCELLED, $payload);
        }

        logger()->info('CHECKOUT_CANCELLED_BY_USER', [
            'checkout_id' => $checkoutId,
            'reason' => $reason ?? 'USER_CANCELLED',
        ]);

        return [
            'not_found' => false,
            'already_cancelled' => false,
            'invalid_state' => false,
            'status' => OrderStatus::CANCELLED->name,
        ];
    }

    /**
     * Atomically transition checkout status. Returns true only if exactly one row was updated.
     */
    private function transitionStatus(
        string $checkoutId,
        OrderStatus $fromStatus,
        array $toData,
        string $successLogMessage,
        array $successLogContext = [],
        string $ignoredLogMessage = LogMessage::CHECKOUT_REJECT_IGNORED_INVALID_STATE,
        array $ignoredLogContext = [],
        string $ignoredLogLevel = 'warning'
    ): bool {
        $updated = $this->checkoutSessions->updateWhere(
            [
                ['id', '=', $checkoutId],
                ['status_id', '=', $fromStatus->value],
            ],
            $toData
        );

        if ($updated === 1) {
            logger()->info($successLogMessage, array_merge(['checkout_id' => $checkoutId], $successLogContext));
            return true;
        }

        $context = array_merge(['checkout_id' => $checkoutId], $ignoredLogContext);
        match ($ignoredLogLevel) {
            'debug' => logger()->debug($ignoredLogMessage, $context),
            default => logger()->warning($ignoredLogMessage, $context),
        };

        return false;
    }

    /**
     * Start checkout: validate stock, create session + items, publish event.
     * Returns ['checkout' => CheckoutSession, 'existing' => bool].
     *
     * @throws BusinessException
     */
    public function startCheckout(array $items, string $userId, string $rawIdempotencyKey): array
    {
        if ($items === []) {
            throw new BusinessException('Cart empty', 400);
        }

        $idempotencyHash = $this->hashIdempotencyKey($rawIdempotencyKey);

        $existing = $this->findByIdempotencyHash($idempotencyHash);
        if ($existing) {
            return ['checkout' => $existing, 'existing' => true];
        }

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
                validatedItems: $validationData['items'],
                userId: $userId,
                idempotencyHash: $idempotencyHash
            );
        } catch (QueryException $e) {
            $errorCode = (int) ($e->errorInfo[1] ?? 0);
            if ($errorCode === 1062) {
                $existing = $this->findByIdempotencyHash($idempotencyHash);
                if ($existing) {
                    return ['checkout' => $existing, 'existing' => true];
                }
            }
            throw $e;
        }

        $this->publishOrderCheckoutEvent($checkout, $idempotencyHash);

        return ['checkout' => $checkout, 'existing' => false];
    }

    private function createCheckoutSessionAndItems(array $items, array $validatedItems, string $userId, string $idempotencyHash): CheckoutSession
    {
        return DB::transaction(function () use ($items, $validatedItems, $idempotencyHash, $userId) {
            $now = now();
            $validationByProduct = [];
            foreach ($validatedItems as $validatedItem) {
                if (!is_array($validatedItem)) {
                    continue;
                }

                $productId = (string) ($validatedItem['product_id'] ?? '');
                if ($productId === '') {
                    continue;
                }

                $validationByProduct[$productId] = $validatedItem;
            }

            $checkoutSession = $this->create([
                'idempotency_key' => $idempotencyHash,
                'status_id'       => OrderStatus::PENDING_STOCK->value,
                'user_id'         => $userId,
                'reservation_id'  => null,
                'payment_id'      => null,
                'correlation_id'  => $idempotencyHash,
                'expire_at'       => $this->calculateExpireAt(),
            ]);

            $rows = [];
            foreach ($items as $item) {
                $productId = (string) ($item['product_id'] ?? '');
                $validatedRow = $validationByProduct[$productId] ?? [];
                $unitPrice = $validatedRow['unit_price'] ?? null;
                if ($productId === '' || !is_numeric($unitPrice)) {
                    throw new BusinessException("Missing price for product {$productId}", 400);
                }

                $product = is_array($validatedRow['product'] ?? null) ? $validatedRow['product'] : [];
                $productSlug = isset($product['slug']) ? (string) $product['slug'] : null;
                $productTitle = isset($product['title']) ? (string) $product['title'] : null;
                $productDescription = isset($product['description']) ? (string) $product['description'] : null;
                $productSku = isset($product['sku']) ? (string) $product['sku'] : null;
                $categoryId = isset($product['category_id']) ? (string) $product['category_id'] : null;
                $shopId = isset($product['shop_id']) ? (string) $product['shop_id'] : null;
                $imageUrl = isset($product['image_url']) ? (string) $product['image_url'] : null;
                $currency = isset($product['currency']) ? (string) $product['currency'] : 'USD';
                $availableStock = $validatedRow['available_stock'] ?? $product['available_stock'] ?? null;
                $lineTotal = $validatedRow['line_total'] ?? ((float) $unitPrice * (int) ($item['qty'] ?? 0));

                $rows[] = [
                    'checkout_id' => $checkoutSession->id,
                    'product_id' => $productId,
                    'qty' => (int) ($item['qty'] ?? 0),
                    'price' => (float) $unitPrice,
                    'line_total' => (float) $lineTotal,
                    'available_stock' => is_numeric($availableStock) ? (int) $availableStock : null,
                    'product_slug' => $productSlug,
                    'product_title' => $productTitle,
                    'product_description' => $productDescription,
                    'product_sku' => $productSku,
                    'category_id' => $categoryId,
                    'shop_id' => $shopId,
                    'image_url' => $imageUrl,
                    'currency' => $currency,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->checkoutItems->insertMany($rows);

            return $checkoutSession;
        });
    }

    private function publishOrderCheckoutEvent(CheckoutSession $checkout, string $correlationId): void
    {
        $checkout->loadMissing('items');
        $eventItems = $checkout->items->map(static function ($item): array {
            return [
                'product_id' => $item->product_id,
                'sku' => $item->product_sku,
                'slug' => $item->product_slug,
                'name' => $item->product_title,
                'description' => $item->product_description,
                'image_url' => $item->image_url,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->price,
                'line_total' => isset($item->line_total) ? (float) $item->line_total : ((float) $item->price * (int) $item->qty),
                'currency' => $item->currency ?? 'USD',
                'shop_id' => $item->shop_id,
                'category_id' => $item->category_id,
                'available_stock' => isset($item->available_stock) ? (int) $item->available_stock : null,
            ];
        })->values()->all();

        $currency = 'USD';
        if ($eventItems !== []) {
            $currency = (string) ($eventItems[0]['currency'] ?? 'USD');
        }

        $subtotal = (float) array_reduce(
            $eventItems,
            static fn (float $carry, array $item): float => $carry + (float) ($item['line_total'] ?? ((float) $item['unit_price'] * (int) $item['qty'])),
            0.0
        );

        $payload = EventPayloadBuilder::build(EventTopic::ORDER_CHECKOUT_REQUESTED, $correlationId, [
            'order_id' => null,
            'checkout_id' => $checkout->id,
            'user_id' => (string) $checkout->user_id,
            'status' => 'CREATED',
            'items' => $eventItems,
            'subtotal' => $subtotal,
            'total' => (float) ($checkout->total ?? $subtotal),
            'currency' => $currency,
            'placedAt' => optional($checkout->created_at)?->copy()->utc()->toIso8601String(),
            'updatedAt' => optional($checkout->updated_at)?->copy()->utc()->toIso8601String(),
            'expires_at' => $checkout->getExpireAtIso(),
        ]);

        Publisher::publishStatic(EventTopic::ORDER_CHECKOUT_REQUESTED, $payload);

        logger()->info('ORDER_CREATED EVENT PUBLISHED', [
            'checkout_id' => $checkout->id,
            'payload' => $payload,
        ]);
    }
}
