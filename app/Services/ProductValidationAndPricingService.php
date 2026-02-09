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
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(3)
            ->retry(2, 100)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => config('services.product.api_key'),
            ])
            ->post(rtrim(config('services.product.url'), '/') . '/api/v1/products/validate-stock', [
                'items' => $items,
            ]);

        if (!$response->ok() || !($response->json('success') ?? false)) {
            logger('PRODUCT STOCK VALIDATION API FAILED', [
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

