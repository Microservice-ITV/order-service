<?php

namespace App\Services;

use App\Contracts\CheckoutItemInterface;
use App\Contracts\CheckoutSessionInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Logging\LogMessage;
use App\Services\EventPayloadBuilder;
use App\Models\CheckoutItem;
use App\Models\CheckoutShopShipping;
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
        private readonly ProductValidationAndPricingService $pricingService,
        private readonly PaymentService $paymentService,
        private readonly PromotionQuoteService $promotionQuoteService
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

    public function applyPromotionQuote(string $checkoutId, array $quote): bool
    {
        $updated = $this->checkoutSessions->updateWhere(
            [['id', '=', $checkoutId]],
            [
                'total' => (float) ($quote['final_total'] ?? 0),
                'shipping_total' => (float) ($quote['shipping_total'] ?? 0),
                'promotion_discount_total' => (float) ($quote['discount_total'] ?? 0),
                'promotion_snapshot' => $quote,
                'updated_at' => now(),
            ]
        );

        return $updated === 1;
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

    public function markPaymentPreparationFailed(string $checkoutId, ?string $reason = null): bool
    {
        return $this->transitionStatus(
            $checkoutId,
            OrderStatus::STOCK_CONFIRMED,
            [
                'status_id'      => OrderStatus::FAILED_PAYMENT->value,
                'failure_reason' => $reason,
                'updated_at'     => now(),
            ],
            LogMessage::CHECKOUT_PAYMENT_FAILED,
            ['reason' => $reason]
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
     * @return array{
     *   not_found: bool,
     *   invalid_state: bool,
     *   reason?: string,
     *   payment_update_failed?: bool,
     *   payment_error?: string,
    *   promotion_error?: string,
     *   status: ?string,
     *   address_updated_at: ?string,
     *   grand_total?: float,
     *   shipping_total?: float,
    *   promotion_discount_total?: float,
    *   promo_code?: ?string,
    *   promo_code_status?: ?string,
    *   promo_code_message?: ?string,
     *   client_secret?: string,
     *   shop_shippings?: list<array{shop_id: string, shipping_fee: float}>
     * }
     */
    public function updateDeliveryAddress(
        string $checkoutId,
        array $deliveryAddress,
        ?float $shippingTotal = null,
        ?array $shopShippings = null,
        ?string $promoCode = null,
    ): array {
        /** @var ?CheckoutSession $checkout */
        $checkout = $this->checkoutSessions->findById($checkoutId);
        if (!$checkout) {
            return [
                'not_found' => true,
                'invalid_state' => false,
                'status' => null,
                'address_updated_at' => null,
            ];
        }

        $blockedStatuses = [
            OrderStatus::PAID,
            OrderStatus::CANCELLED,
            OrderStatus::FULFILLED,
            OrderStatus::PARTIALLY_FULFILLED,
            OrderStatus::REFUNDED,
            OrderStatus::PARTIALLY_REFUNDED,
        ];
        if (in_array($checkout->status_id, $blockedStatuses, true)) {
            return [
                'not_found' => false,
                'invalid_state' => true,
                'status' => $checkout->status_id->name,
                'address_updated_at' => null,
            ];
        }

        $currency = null;

        $effectiveShipping = null;
        $normalizedShopShippings = null;
        if ($shopShippings !== null && $shopShippings !== []) {
            $normalizedShopShippings = $this->normalizeShopShippingsPayload($shopShippings);
            if ($normalizedShopShippings === []) {
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => 'shop_shippings_invalid',
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }
            $computedShipping = $this->sumShopShippingFees($normalizedShopShippings);
            if ($shippingTotal !== null && abs($computedShipping - $shippingTotal) > 0.02) {
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => 'shipping_total_mismatch',
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }
            $effectiveShipping = $computedShipping;

            $shopError = $this->validateShopShippingsAgainstItems($checkoutId, $normalizedShopShippings);
            if ($shopError !== null) {
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => $shopError['reason'],
                    'shop_validation' => $shopError,
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }
        } elseif ($shippingTotal !== null) {
            $effectiveShipping = $shippingTotal;
        }

        if ($effectiveShipping !== null) {
            if ($checkout->status_id !== OrderStatus::PENDING_PAYMENT) {
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => 'shipping_requires_pending_payment',
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }
            if ($checkout->payment_id === null || $checkout->payment_id === '') {
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => 'payment_not_ready',
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }

            $itemsSubtotal = $this->calculateCheckoutItemsSubtotal($checkoutId);
            $effectivePromoCode = $this->resolvePromoCode($checkout, $promoCode);
            try {
                $promotionQuote = $this->promotionQuoteService->quote(
                    checkoutId: $checkoutId,
                    userId: (string) $checkout->user_id,
                    subtotal: $itemsSubtotal,
                    shippingTotal: $effectiveShipping,
                    promoCode: $effectivePromoCode,
                );
            } catch (\Throwable $e) {
                logger()->error('CHECKOUT_PROMOTION_QUOTE_UPDATE_FAILED', [
                    'checkout_id' => $checkoutId,
                    'error' => $e->getMessage(),
                ]);
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => 'promotion_quote_failed',
                    'promotion_error' => $e->getMessage(),
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }

            if (
                $effectivePromoCode !== null
                && ($promotionQuote['promo_code_status'] ?? null) !== null
                && ($promotionQuote['promo_code_status'] ?? null) !== 'VALID'
            ) {
                return [
                    'not_found' => false,
                    'invalid_state' => true,
                    'reason' => 'promo_code_invalid',
                    'promo_code' => $promotionQuote['promo_code'] ?? $effectivePromoCode,
                    'promo_code_status' => $promotionQuote['promo_code_status'],
                    'promo_code_message' => $promotionQuote['promo_code_message'] ?? 'Promo code is not valid',
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }

            $grandTotal = $promotionQuote['final_total'];

            $firstItem = CheckoutItem::query()->where('checkout_id', $checkoutId)->first();
            $currency = $firstItem && $firstItem->currency ? (string) $firstItem->currency : 'USD';

            try {
                $paymentResult = $this->paymentService->updatePaymentIntentAmount(
                    $checkoutId,
                    $grandTotal,
                    $currency
                );
            } catch (\RuntimeException $e) {
                logger()->error('CHECKOUT_PAYMENT_INTENT_UPDATE_FAILED', [
                    'checkout_id' => $checkoutId,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'not_found' => false,
                    'invalid_state' => false,
                    'payment_update_failed' => true,
                    'payment_error' => $e->getMessage(),
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }

            $updatedAt = now();

            try {
                DB::transaction(function () use (
                    $checkoutId,
                    $deliveryAddress,
                    $updatedAt,
                    $grandTotal,
                    $effectiveShipping,
                    $normalizedShopShippings,
                    $paymentResult,
                    $promotionQuote
                ): void {
                    $updated = $this->checkoutSessions->updateWhere(
                        [['id', '=', $checkoutId]],
                        [
                            'delivery_address' => $deliveryAddress,
                            'address_updated_at' => $updatedAt,
                            'shipping_total' => $effectiveShipping,
                            'promotion_discount_total' => $promotionQuote['discount_total'],
                            'promotion_snapshot' => $promotionQuote,
                            'total' => $grandTotal,
                            'client_secret' => $paymentResult['client_secret'],
                            'updated_at' => $updatedAt,
                        ]
                    );

                    if ($updated !== 1) {
                        throw new \RuntimeException('checkout_session_update_failed');
                    }

                    if ($normalizedShopShippings !== null) {
                        $this->replaceCheckoutShopShippingRows($checkoutId, $normalizedShopShippings);
                    } else {
                        CheckoutShopShipping::query()->where('checkout_id', $checkoutId)->delete();
                    }
                });
            } catch (\Throwable $e) {
                logger()->error('CHECKOUT_PERSIST_AFTER_PAYMENT_INTENT_UPDATE', [
                    'checkout_id' => $checkoutId,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'not_found' => false,
                    'invalid_state' => false,
                    'payment_update_failed' => true,
                    'payment_error' => 'Payment amount was updated but saving checkout details failed; contact support with your checkout id.',
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => null,
                ];
            }

            logger()->info('CHECKOUT_DELIVERY_AND_SHIPPING_UPDATED', [
                'checkout_id' => $checkoutId,
                'status' => $checkout->status_id->name,
                'grand_total' => $grandTotal,
                'shipping_total' => $effectiveShipping,
                'promotion_discount_total' => $promotionQuote['discount_total'],
                'per_shop' => $normalizedShopShippings !== null,
            ]);

            $result = [
                'not_found' => false,
                'invalid_state' => false,
                'status' => $checkout->status_id->name,
                'address_updated_at' => $updatedAt->copy()->utc()->toIso8601String(),
                'grand_total' => $grandTotal,
                'shipping_total' => $effectiveShipping,
                'promotion_discount_total' => (float) $promotionQuote['discount_total'],
                'promo_code' => $promotionQuote['promo_code'] ?? null,
                'promo_code_status' => $promotionQuote['promo_code_status'] ?? null,
                'promo_code_message' => $promotionQuote['promo_code_message'] ?? null,
                'client_secret' => $paymentResult['client_secret'],
                'shop_shippings' => $normalizedShopShippings,
            ];

            if (!empty($paymentResult['already_succeeded'])) {
                $result['already_succeeded'] = true;
            }

            try {
                $this->authorizeCodPaymentIfNeeded($checkout);
            } catch (\RuntimeException $e) {
                logger()->error('CHECKOUT_COD_AUTHORIZE_FAILED', [
                    'checkout_id' => $checkoutId,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'not_found' => false,
                    'invalid_state' => false,
                    'payment_update_failed' => true,
                    'payment_error' => $e->getMessage(),
                    'status' => $checkout->status_id->name,
                    'address_updated_at' => $result['address_updated_at'],
                ];
            }

            return $result;
        }

        $updatedAt = now();
        $updated = $this->checkoutSessions->updateWhere(
            [['id', '=', $checkoutId]],
            [
                'delivery_address' => $deliveryAddress,
                'address_updated_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ]
        );

        if ($updated !== 1) {
            return [
                'not_found' => false,
                'invalid_state' => true,
                'status' => $checkout->status_id->name,
                'address_updated_at' => null,
            ];
        }

        logger()->info('CHECKOUT_DELIVERY_ADDRESS_UPDATED', [
            'checkout_id' => $checkoutId,
            'status' => $checkout->status_id->name,
        ]);

        try {
            $this->authorizeCodPaymentIfNeeded($checkout);
        } catch (\RuntimeException $e) {
            logger()->error('CHECKOUT_COD_AUTHORIZE_FAILED', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);

            return [
                'not_found' => false,
                'invalid_state' => false,
                'payment_update_failed' => true,
                'payment_error' => $e->getMessage(),
                'status' => $checkout->status_id->name,
                'address_updated_at' => $updatedAt->copy()->utc()->toIso8601String(),
            ];
        }

        return [
            'not_found' => false,
            'invalid_state' => false,
            'status' => $checkout->status_id->name,
            'address_updated_at' => $updatedAt->copy()->utc()->toIso8601String(),
        ];
    }

    /**
     * For COD checkouts, publish payment.completed only after address/shipping
     * are applied so the saga does not create the order with a stale total.
     */
    private function authorizeCodPaymentIfNeeded(CheckoutSession $checkout): void
    {
        if (($checkout->payment_method ?? 'prepaid') !== 'cod') {
            return;
        }

        $paymentId = $checkout->payment_id;
        if ($paymentId === null || $paymentId === '') {
            throw new \RuntimeException('COD payment is not ready for authorisation');
        }

        $this->paymentService->authorizePayment($paymentId);

        logger()->info('CHECKOUT_COD_AUTHORISED', [
            'checkout_id' => $checkout->id,
            'payment_id' => $paymentId,
        ]);
    }

    private function resolvePromoCode(CheckoutSession $checkout, ?string $promoCode): ?string
    {
        if (is_string($promoCode)) {
            $trimmed = strtoupper(trim($promoCode));
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        $snapshot = is_array($checkout->promotion_snapshot) ? $checkout->promotion_snapshot : [];
        $fromSnapshot = $snapshot['promo_code'] ?? null;
        if (is_string($fromSnapshot)) {
            $trimmed = strtoupper(trim($fromSnapshot));
            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function calculateCheckoutItemsSubtotal(string $checkoutId): float
    {
        return (float) CheckoutItem::query()
            ->where('checkout_id', $checkoutId)
            ->get()
            ->sum(static function (CheckoutItem $item): float {
                if ($item->line_total !== null) {
                    return (float) $item->line_total;
                }

                return (float) $item->price * (int) $item->qty;
            });
    }

    /**
     * @param  list<array{shop_id: string, shipping_fee: float}>  $rows
     * @return list<array{shop_id: string, shipping_fee: float}>
     */
    private function normalizeShopShippingsPayload(array $rows): array
    {
        $byShop = [];
        foreach ($rows as $row) {
            $sid = isset($row['shop_id']) ? trim((string) $row['shop_id']) : '';
            if ($sid === '') {
                continue;
            }
            $fee = round((float) ($row['shipping_fee'] ?? 0), 2);
            if (!isset($byShop[$sid])) {
                $byShop[$sid] = 0.0;
            }
            $byShop[$sid] += $fee;
        }

        $out = [];
        foreach ($byShop as $shopId => $sum) {
            $out[] = ['shop_id' => $shopId, 'shipping_fee' => round($sum, 2)];
        }

        return $out;
    }

    /**
     * @param  list<array{shop_id: string, shipping_fee: float}>  $rows
     */
    private function sumShopShippingFees(array $rows): float
    {
        return round(array_sum(array_column($rows, 'shipping_fee')), 2);
    }

    /**
     * @param  list<array{shop_id: string, shipping_fee: float}>  $normalizedRows
     * @return array{
     *   reason: string,
     *   checkout_shop_ids: list<string>,
     *   provided_shop_ids: list<string>,
     *   missing_shop_ids?: list<string>,
     *   unknown_shop_ids?: list<string>
     * }|null
     */
    private function validateShopShippingsAgainstItems(string $checkoutId, array $normalizedRows): ?array
    {
        $cartShops = CheckoutItem::query()
            ->where('checkout_id', $checkoutId)
            ->get()
            ->pluck('shop_id')
            ->filter(static fn ($s) => $s !== null && $s !== '')
            ->map(static fn ($s) => (string) $s)
            ->unique()
            ->values()
            ->all();

        if ($cartShops === []) {
            return [
                'reason' => 'checkout_items_missing_shop_id',
                'checkout_shop_ids' => [],
                'provided_shop_ids' => array_column($normalizedRows, 'shop_id'),
            ];
        }

        $feeShops = array_column($normalizedRows, 'shop_id');
        $missingShopIds = array_values(array_filter(
            $cartShops,
            static fn (string $sid): bool => !in_array($sid, $feeShops, true)
        ));
        if ($missingShopIds !== []) {
            return [
                'reason' => 'shipping_missing_for_shop',
                'checkout_shop_ids' => $cartShops,
                'provided_shop_ids' => $feeShops,
                'missing_shop_ids' => $missingShopIds,
            ];
        }

        $unknownShopIds = array_values(array_filter(
            $feeShops,
            static fn (string $sid): bool => !in_array($sid, $cartShops, true)
        ));
        if ($unknownShopIds !== []) {
            return [
                'reason' => 'unknown_shop_in_shipping',
                'checkout_shop_ids' => $cartShops,
                'provided_shop_ids' => $feeShops,
                'unknown_shop_ids' => $unknownShopIds,
            ];
        }

        return null;
    }

    /**
     * @param  list<array{shop_id: string, shipping_fee: float}>  $normalizedRows
     */
    private function replaceCheckoutShopShippingRows(string $checkoutId, array $normalizedRows): void
    {
        CheckoutShopShipping::query()->where('checkout_id', $checkoutId)->delete();
        $now = now();
        foreach ($normalizedRows as $row) {
            CheckoutShopShipping::query()->insert([
                'id' => (string) Str::uuid(),
                'checkout_id' => $checkoutId,
                'shop_id' => $row['shop_id'],
                'shipping_fee' => $row['shipping_fee'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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
    public function startCheckout(array $items, string $userId, string $rawIdempotencyKey, ?array $deliveryAddress = null, string $paymentMethod = 'prepaid'): array
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
                idempotencyHash: $idempotencyHash,
                deliveryAddress: $deliveryAddress,
                paymentMethod: $paymentMethod
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

    private function createCheckoutSessionAndItems(
        array $items,
        array $validatedItems,
        string $userId,
        string $idempotencyHash,
        ?array $deliveryAddress = null,
        string $paymentMethod = 'prepaid'
    ): CheckoutSession
    {
        return DB::transaction(function () use ($items, $validatedItems, $idempotencyHash, $userId, $deliveryAddress, $paymentMethod) {
            $now = now();

            if (count($items) !== count($validatedItems)) {
                throw new BusinessException('Validation result mismatch', 500);
            }

            $checkoutSession = $this->create([
                'idempotency_key' => $idempotencyHash,
                'status_id'       => OrderStatus::PENDING_STOCK->value,
                'user_id'         => $userId,
                'reservation_id'  => null,
                'payment_id'      => null,
                'payment_method'  => $paymentMethod,
                'correlation_id'  => $idempotencyHash,
                'expire_at'       => $this->calculateExpireAt(),
                'delivery_address' => $deliveryAddress,
                'address_updated_at' => $deliveryAddress !== null ? now() : null,
            ]);

            $rows = [];
            foreach ($items as $i => $item) {
                $productId = (string) ($item['product_id'] ?? '');
                $validatedRow = is_array($validatedItems[$i] ?? null) ? $validatedItems[$i] : [];
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

                $variantId = isset($item['variant_id']) && $item['variant_id'] !== ''
                    ? (string) $item['variant_id']
                    : null;

                $rows[] = [
                    'checkout_id' => $checkoutSession->id,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
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
                'variant_id' => $item->variant_id ?? null,
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

        $shippingTotal = round((float) ($checkout->shipping_total ?? 0), 2);
        $discountTotal = round((float) ($checkout->promotion_discount_total ?? 0), 2);
        $grandTotal = round((float) ($checkout->total ?? max(0, $subtotal + $shippingTotal - $discountTotal)), 2);

        $payload = EventPayloadBuilder::build(EventTopic::ORDER_CHECKOUT_REQUESTED, $correlationId, [
            'order_id' => null,
            'checkout_id' => $checkout->id,
            'user_id' => (string) $checkout->user_id,
            'status' => 'CREATED',
            'items' => $eventItems,
            'subtotal' => round($subtotal, 2),
            'shipping_total' => $shippingTotal,
            'promotion_discount_total' => $discountTotal,
            'total' => $grandTotal,
            'currency' => $currency,
            'delivery_address' => $checkout->delivery_address,
            'placedAt' => optional($checkout->created_at)?->copy()->utc()->toIso8601String(),
            'updatedAt' => optional($checkout->updated_at)?->copy()->utc()->toIso8601String(),
            'expires_at' => $checkout->getExpireAtIso(),
        ]);

        try {
            Publisher::publishStatic(EventTopic::ORDER_CHECKOUT_REQUESTED, $payload);

            logger()->info('ORDER_CREATED EVENT PUBLISHED', [
                'checkout_id' => $checkout->id,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            logger()->warning('ORDER_CREATED_EVENT_PUBLISH_FAILED_WILL_RETRY_VIA_OUTBOX', [
                'checkout_id' => $checkout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
