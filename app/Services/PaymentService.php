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

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(5)
            ->retry(2, 200)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => (string) config('services.payment.api_key'),
            ])
            ->post($baseUrl . '/api/v1/payments/intents', [
                'checkout_id'    => $checkoutId,
                'reservation_id' => $reservationId,
                'amount'         => $amount,
                'correlation_id' => $correlationId,
                'expire_at'      => $expireAtIso,
            ]);

        if (!$response->ok()) {
            throw new \RuntimeException('Failed to create payment intent');
        }

        $data = $response->json();

        if (!is_array($data) || empty($data['payment_id']) || empty($data['client_secret'])) {
            throw new \RuntimeException('Invalid payment intent response');
        }

        return $data;
    }
}

