<?php

namespace App\Clients;

use Illuminate\Support\Facades\Http;
use App\Exceptions\BusinessException;

class ProductClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.product.url');
    }

    public function getProduct(int $productId): array
    {
        try {
            return Http::timeout(3)
                ->acceptJson()
                ->get("{$this->baseUrl}/api/v1/products/{$productId}")
                ->throw()
                ->json('data');
        } catch (\Throwable $e) {
            throw new BusinessException('Unable to fetch product', 0, $e);
        }
    }
}
