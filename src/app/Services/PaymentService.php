<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class PaymentService
{
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
        ?string $expireAtIso
    ): array {
        $baseUrl = rtrim((string) config('services.payment.url'), '/');

        $payload = [
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
            'amount'         => $amount,
            'correlation_id' => $correlationId,
            'expire_at'      => $expireAtIso,
        ];

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
}

