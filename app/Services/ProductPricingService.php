<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class ProductPricingService
{
    /**
     * Fetch product pricing data from product service.
     *
     * @return array{ok:bool, missing:array<int, mixed>, priceByProduct:array<string, array<string, mixed>>}
     */
    public function fetchPricingData(array $items): array
    {
        /** @var \Illuminate\Http\Client\Response $pricingResponse */
        $pricingResponse = Http::timeout(3)
            ->retry(2, 100)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'X-SERVICE-KEY' => config('services.product.api_key'),
            ])
            ->post(rtrim(config('services.product.url'), '/') . '/api/v1/products/prices', [
                'items' => $items,
            ]);

        if (!$pricingResponse->ok() || !($pricingResponse->json('success') ?? false)) {
            logger('PRODUCT PRICE API FAILED', [
                'status' => $pricingResponse->status(),
                'body'   => $pricingResponse->json(),
            ]);

            return ['ok' => false, 'missing' => [], 'priceByProduct' => []];
        }

        $pricingData = $pricingResponse->json();
        $missing = $pricingData['missing'] ?? [];

        $priceByProduct = [];
        foreach (($pricingData['data'] ?? []) as $row) {
            if (!is_array($row) || empty($row['product_id']) || !isset($row['unit_price'])) {
                continue;
            }
            $priceByProduct[(string) $row['product_id']] = $row;
        }

        return [
            'ok'           => true,
            'missing'      => $missing,
            'priceByProduct' => $priceByProduct,
        ];
    }
}

