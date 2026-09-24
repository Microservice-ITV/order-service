<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class PaymentService
{
    /** @return array<int, array<string, mixed>> */
    public function getPaymentsForOrders(array $orderIds, array $shopIds = []): array
    {
        $ids = array_values(array_filter($orderIds, static fn ($id) => is_string($id) && $id !== ''));
        if ($ids === []) return [];

        $query = ['order_ids' => implode(',', $ids)];
        // When seller-scoped, payment-service aggregates the per-shop
        // allocations down to these shops and returns them as `seller` totals.
        $shopIds = array_values(array_filter($shopIds, static fn ($id) => is_string($id) && $id !== ''));
        if ($shopIds !== []) {
            $query['shop_ids'] = implode(',', $shopIds);
        }

        $baseUrl = rtrim((string) config('services.payment.url'), '/');
        $response = Http::timeout(15)->withHeaders([
            'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
        ])->get($baseUrl . '/api/v1/payments/records', $query);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to load payment records');
        }

        return (array) ($response->json('data') ?? []);
    }

    /** @return array<string, mixed> */
    public function getPaymentDetail(string $paymentId): array
    {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');
        $response = Http::timeout(15)->withHeaders([
            'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
        ])->get($baseUrl . '/api/v1/payments/' . rawurlencode($paymentId));

        if (!$response->successful()) {
            if ($response->status() === 404) {
                throw new \RuntimeException('payment_not_found');
            }
            throw new \RuntimeException('Failed to load payment detail');
        }

        return (array) ($response->json('data') ?? []);
    }

    /**
     * Fetch per-shop payment allocations for a payment, optionally restricted
     * to the given shops (seller-scoped order views).
     *
     * @param  string[]  $shopIds
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentAllocations(string $paymentId, array $shopIds = []): array
    {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');

        $query = ['payment_id' => $paymentId];
        $shopIds = array_values(array_filter(
            $shopIds,
            static fn ($id): bool => is_string($id) && $id !== '',
        ));
        if ($shopIds !== []) {
            $query['shop_ids'] = implode(',', $shopIds);
        }

        $response = Http::timeout(15)->withHeaders([
            'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
        ])->get($baseUrl . '/api/v1/payment-allocations', $query);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to load payment allocations');
        }

        return is_array($response->json('data')) ? $response->json('data') : [];
    }
    /**
     * Create a payment intent in the payment service.
     *
     * Expected response shape (example):
     * {
     *   "payment_id": "pay_123",
     *   "client_secret": "pi_client_secret_xxx",
     *   "expires_at": "2026-02-02T12:30:00Z"
     * }
     */
    public function createPaymentIntent(
        string $checkoutId,
        string $reservationId,
        float $amount,
        string $correlationId,
        ?string $expireAtIso,
        ?string $paymentMethod = null
    ): array {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');

        $payload = [
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
            'amount'         => $amount,
            'correlation_id' => $correlationId,
            'expire_at'      => $expireAtIso,
        ];

        if ($paymentMethod !== null && $paymentMethod !== '') {
            $payload['payment_method'] = $paymentMethod;
        }

        logger()->info('PAYMENT_INTENT_REQUEST', [
            'url' => $baseUrl . '/api/v1/payments/intents',
            'payload' => $payload,
        ]);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(5)
            ->retry(2, 200)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
            ])
            ->post($baseUrl . '/api/v1/payments/intents', $payload);

        if (!$response->successful()) {
            logger()->error('PAYMENT_INTENT_REQUEST_FAILED', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to create payment intent');
        }

        $responseData = $response->json();
        $payload = is_array($responseData) ? ($responseData['data'] ?? null) : null;

        if (!is_array($payload) || empty($payload['paymentId']) || empty($payload['clientSecret'])) {
            logger()->error('PAYMENT_INTENT_INVALID_RESPONSE', [
                'body' => $responseData,
            ]);
            throw new \RuntimeException('Invalid payment intent response');
        }

        return [
            'payment_id' => $payload['paymentId'],
            'client_secret' => $payload['clientSecret'],
        ];
    }

    /**
     * Update an existing pending payment intent amount (e.g. after shipping is finalized).
     *
     * PATCH /api/v1/payments/intents/{checkout_id}
     */
    public function updatePaymentIntentAmount(
        string $checkoutId,
        float $amount,
        ?string $currency = null
    ): array {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');

        $payload = ['amount' => $amount];
        if ($currency !== null) {
            $payload['currency'] = $currency;
        }

        logger()->info('PAYMENT_INTENT_UPDATE_REQUEST', [
            'url' => $baseUrl . '/api/v1/payments/intents/' . $checkoutId,
            'payload' => $payload,
        ]);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
            ])
            ->patch($baseUrl . '/api/v1/payments/intents/' . rawurlencode($checkoutId), $payload);

        if (!$response->successful()) {
            $body = $response->json();
            $err = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : $response->body();

            logger()->warning('PAYMENT_INTENT_UPDATE_FAILED', [
                'status' => $response->status(),
                'error' => $err,
            ]);

            if (str_contains($err, 'status of succeed') || str_contains($err, 'succeeded')) {
                logger()->info('PAYMENT_INTENT_ALREADY_SUCCEEDED', [
                    'checkout_id' => $checkoutId,
                ]);
                return [
                    'payment_id' => null,
                    'client_secret' => null,
                    'amount' => $amount,
                    'expires_at' => null,
                    'already_succeeded' => true,
                ];
            }

            throw new \RuntimeException('Failed to update payment intent: ' . $err);
        }

        $responseData = $response->json();
        $data = is_array($responseData) ? ($responseData['data'] ?? null) : null;

        if (!is_array($data) || empty($data['paymentId']) || empty($data['clientSecret'])) {
            logger()->error('PAYMENT_INTENT_UPDATE_INVALID_RESPONSE', [
                'body' => $responseData,
            ]);
            throw new \RuntimeException('Invalid payment intent update response');
        }

        return [
            'payment_id' => $data['paymentId'],
            'client_secret' => $data['clientSecret'],
            'amount' => (float) ($data['amount'] ?? $amount),
            'expires_at' => isset($data['expiresAt']) ? (string) $data['expiresAt'] : null,
        ];
    }

    /**
     * Authorise a COD payment after address/shipping are finalised.
     * Publishes payment.completed so the saga can create the order.
     *
     * POST /api/v1/payments/{payment_id}/authorize
     */
    public function authorizePayment(string $paymentId): array
    {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');

        logger()->info('PAYMENT_COD_AUTHORIZE_REQUEST', [
            'url' => $baseUrl . '/api/v1/payments/' . $paymentId . '/authorize',
        ]);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
            ])
            ->post($baseUrl . '/api/v1/payments/' . rawurlencode($paymentId) . '/authorize');

        if (!$response->successful()) {
            $body = $response->json();
            $err = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : $response->body();

            logger()->warning('PAYMENT_COD_AUTHORIZE_FAILED', [
                'status' => $response->status(),
                'payment_id' => $paymentId,
                'error' => $err,
            ]);

            throw new \RuntimeException('Failed to authorise COD payment: ' . $err);
        }

        $responseData = $response->json();
        $data = is_array($responseData) ? ($responseData['data'] ?? null) : null;

        if (!is_array($data) || empty($data['paymentId'])) {
            logger()->error('PAYMENT_COD_AUTHORIZE_INVALID_RESPONSE', [
                'body' => $responseData,
            ]);
            throw new \RuntimeException('Invalid COD authorize response');
        }

        return [
            'payment_id' => $data['paymentId'],
            'status' => $data['status'] ?? null,
            'previously_authorized' => (bool) ($data['previouslyAuthorized'] ?? false),
        ];
    }

    /** Bind the payment created for a checkout to its newly created order. */
    public function attachOrder(string $paymentId, string $orderId): array
    {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');
        $response = Http::timeout(15)->retry(2, 200)->withHeaders([
            'Content-Type' => 'application/json',
            'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
        ])->patch($baseUrl . '/api/v1/payments/' . rawurlencode($paymentId) . '/order', [
            'orderId' => $orderId,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to attach order to payment: ' . $response->body());
        }

        return (array) ($response->json('data') ?? []);
    }

    /**
     * Create a payment allocation for a shop_order.
     */
    public function createPaymentAllocation(array $data): array
    {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');
        $response = Http::timeout(15)->retry(2, 200)->withHeaders([
            'Content-Type' => 'application/json',
            'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
        ])->post($baseUrl . '/api/v1/payment-allocations', $data);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to create payment allocation: ' . $response->body());
        }

        return (array) ($response->json('data') ?? []);
    }

    /**
     * Confirm physical collection of a COD payment after delivery handover.
     *
     * POST /api/v1/payments/{payment_id}/collect
     */
    public function collectPayment(string $paymentId): array
    {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');

        logger()->info('PAYMENT_COD_COLLECT_REQUEST', [
            'url' => $baseUrl . '/api/v1/payments/' . $paymentId . '/collect',
        ]);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
            ])
            ->post($baseUrl . '/api/v1/payments/' . rawurlencode($paymentId) . '/collect');

        if (!$response->successful()) {
            $body = $response->json();
            $err = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : $response->body();

            logger()->warning('PAYMENT_COD_COLLECT_FAILED', [
                'status' => $response->status(),
                'payment_id' => $paymentId,
                'error' => $err,
            ]);

            throw new \RuntimeException('Failed to collect COD payment: ' . $err);
        }

        $responseData = $response->json();
        $data = is_array($responseData) ? ($responseData['data'] ?? null) : null;

        if (!is_array($data) || empty($data['paymentId'])) {
            logger()->error('PAYMENT_COD_COLLECT_INVALID_RESPONSE', [
                'body' => $responseData,
            ]);
            throw new \RuntimeException('Invalid COD collect response');
        }

        return [
            'payment_id' => $data['paymentId'],
            'status' => $data['status'] ?? null,
            'previously_collected' => (bool) ($data['previouslyCollected'] ?? false),
        ];
    }
}
