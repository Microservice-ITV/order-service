<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class ProductValidationAndPricingService
{
    /**
     * Validate stock and fetch pricing data from product service.
     *
     * @return array{
     *   ok:bool,
     *   stockValid:bool,
     *   items:array<int, array<string, mixed>>,
     *   priceByProduct:array<string, array<string, mixed>>
     * }
     */
    public function validateStockAndFetchPricing(array $items): array
    {
        $productServiceUrl = (string) config('services.product.url');
        $productApiKey = (string) config('services.product.api_key');

        /** @var \Illuminate\Http\Client\Response $response */
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
                ->retry(3, 300)
                ->withHeaders([
                    'Content-Type'  => 'application/json',
                    'X-SERVICE-KEY' => $productApiKey,
                ])
                ->post(rtrim($productServiceUrl, '/') . '/api/v1/products/validate-stock', [
                    'items' => $payloadItems,
                ]);
        } catch (\Throwable $e) {
            logger('PRODUCT SERVICE STOCK VALIDATION CONNECTION FAILED', [
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'stockValid' => false,
                'items' => [],
                'priceByProduct' => [],
            ];
        }

        if (!$response->ok() || !($response->json('success') ?? false)) {
            logger('PRODUCT SERVICE STOCK VALIDATION API FAILED', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return [
                'ok' => false,
                'stockValid' => false,
                'items' => [],
                'priceByProduct' => [],
            ];
        }

        $data = $response->json('data') ?? [];
        $itemsData = is_array($data['items'] ?? null) ? $data['items'] : [];
        $stockValid = (bool) ($data['stock_validation'] ?? false);

        $priceByProduct = [];
        foreach ($itemsData as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (string) ($row['product_id'] ?? '');
            if ($productId === '' || !isset($row['unit_price'])) {
                continue;
            }
            $priceByProduct[$productId] = $row;
        }

        return [
            'ok' => true,
            'stockValid' => $stockValid,
            'items' => $itemsData,
            'priceByProduct' => $priceByProduct,
        ];
    }
}
