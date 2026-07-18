<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class ProductStockReservationService
{
    /**
     * Reserve stock synchronously via the product service.
     *
     * @param  list<array{product_id: string, qty: int, variant_id?: string}>  $items
     * @return array{
     *   ok: bool,
     *   reservation_id?: string,
     *   reason?: string
     * }
     */
    public function reserve(
        string $checkoutId,
        array $items,
        string $expiresAtIso,
        string $correlationId
    ): array {
        $productServiceUrl = (string) config('services.product.url');
        $productApiKey = (string) config('services.product.api_key');

        $payloadItems = array_map(static function (array $item): array {
            $row = [
                'product_id' => (string) ($item['product_id'] ?? ''),
                'qty' => (int) ($item['qty'] ?? 0),
            ];
            if (!empty($item['variant_id'])) {
                $row['variant_id'] = (string) $item['variant_id'];
            }

            return $row;
        }, $items);

        try {
            $response = Http::timeout(5)
                ->retry(2, 200)
                ->withHeaders([
                    'Content-Type'  => 'application/json',
                    'X-SERVICE-KEY' => $productApiKey,
                ])
                ->post(rtrim($productServiceUrl, '/') . '/api/v1/products/reserve-stock', [
                    'checkout_id' => $checkoutId,
                    'items' => $payloadItems,
                    'expires_at' => $expiresAtIso,
                    'correlation_id' => $correlationId,
                ]);
        } catch (\Throwable $e) {
            logger()->error('PRODUCT_SERVICE_STOCK_RESERVATION_CONNECTION_FAILED', [
                'checkout_id' => $checkoutId,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'reason' => 'STOCK_RESERVATION_UNAVAILABLE',
            ];
        }

        if (!$response->ok() || !($response->json('success') ?? false)) {
            logger()->error('PRODUCT_SERVICE_STOCK_RESERVATION_API_FAILED', [
                'checkout_id' => $checkoutId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [
                'ok' => false,
                'reason' => (string) ($response->json('data.reason') ?? 'STOCK_RESERVATION_FAILED'),
            ];
        }

        $data = $response->json('data') ?? [];
        $status = (string) ($data['status'] ?? '');

        if ($status === 'reserved') {
            $reservationId = (string) ($data['reservation_id'] ?? '');
            if ($reservationId === '') {
                return [
                    'ok' => false,
                    'reason' => 'INVALID_RESERVATION_ID',
                ];
            }

            return [
                'ok' => true,
                'reservation_id' => $reservationId,
            ];
        }

        return [
            'ok' => false,
            'reason' => (string) ($data['reason'] ?? 'OUT_OF_STOCK'),
        ];
    }
}
