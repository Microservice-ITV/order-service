<?php

namespace App\Services;

use App\Models\CheckoutItem;
use App\Support\CoveredBundleSavings;
use Illuminate\Support\Facades\Http;

final class PromotionQuoteService
{
    /**
     * @param  list<array{shop_id: string, amount: float}>|null  $shippingByShop
     * @return array{
     *   checkout_id:string,
     *   promotion_ids:array<int,string>,
     *   discount_total:float,
     *   shipping_total:float,
     *   final_total:float,
     *   applied_rewards:array<int,array<string,mixed>>,
     *   allocations:array<int,array<string,mixed>>,
     *   bundle_savings:array<int,array<string,mixed>>,
     *   promo_code:?string,
     *   promo_code_status:?string,
     *   promo_code_message:?string
     * }
     */
    public function quote(
        string $checkoutId,
        string $userId,
        float $subtotal,
        float $shippingTotal,
        ?string $promoCode = null,
        ?array $shippingByShop = null,
    ): array {
        $items = CheckoutItem::query()
            ->where('checkout_id', $checkoutId)
            ->get()
            ->map(static fn (CheckoutItem $item): array => [
                'product_id' => (string) $item->product_id,
                'shop_id' => (string) $item->shop_id,
                'category_id' => $item->category_id ? (string) $item->category_id : null,
                'unit_price' => (float) $item->price,
                'quantity' => (int) $item->qty,
            ])
            ->values()
            ->all();

        $baseUrl = rtrim((string) config('services.promotion.url'), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('Promotion service URL is not configured');
        }

        $body = [
            'checkout_id' => $checkoutId,
            'user_id' => $userId,
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'shipping_total' => round($shippingTotal, 2),
        ];

        $normalizedShippingByShop = $this->normalizeShippingByShop($shippingByShop);
        if ($normalizedShippingByShop !== []) {
            $body['shipping_by_shop'] = $normalizedShippingByShop;
        }

        $normalizedCode = $promoCode !== null ? strtoupper(trim($promoCode)) : '';
        if ($normalizedCode !== '') {
            $body['promo_code'] = $normalizedCode;
        }

        $response = Http::timeout(5)
            ->retry(2, 200)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-SERVICE-KEY' => (string) config('services.promotion.api_key'),
            ])
            ->post($baseUrl . '/promotions/quote', $body);

        if (!$response->successful()) {
            logger()->error('PROMOTION_QUOTE_REQUEST_FAILED', [
                'checkout_id' => $checkoutId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Promotion quote failed');
        }

        $payload = $response->json();
        if (!is_array($payload) || !isset($payload['final_total'], $payload['discount_total'])) {
            throw new \RuntimeException('Invalid promotion quote response');
        }

        $quote = [
            'checkout_id' => $checkoutId,
            'promotion_ids' => array_values(array_map('strval', is_array($payload['promotion_ids'] ?? null) ? $payload['promotion_ids'] : [])),
            'discount_total' => round((float) $payload['discount_total'], 2),
            'shipping_total' => round((float) ($payload['shipping_total'] ?? $shippingTotal), 2),
            'final_total' => round((float) $payload['final_total'], 2),
            'applied_rewards' => is_array($payload['applied_rewards'] ?? null) ? $payload['applied_rewards'] : [],
            'allocations' => $this->normalizeAllocations(is_array($payload['allocations'] ?? null) ? $payload['allocations'] : []),
            'bundle_savings' => $this->normalizeBundleSavings(is_array($payload['bundle_savings'] ?? null) ? $payload['bundle_savings'] : []),
            'promo_code' => isset($payload['promo_code']) && is_string($payload['promo_code'])
                ? strtoupper($payload['promo_code'])
                : ($normalizedCode !== '' ? $normalizedCode : null),
            'promo_code_status' => isset($payload['promo_code_status']) && is_string($payload['promo_code_status'])
                ? $payload['promo_code_status']
                : null,
            'promo_code_message' => isset($payload['promo_code_message']) && is_string($payload['promo_code_message'])
                ? $payload['promo_code_message']
                : null,
        ];

        return CoveredBundleSavings::merge($quote, $this->matchBundles($items));
    }

    /**
     * @param  list<array{product_id: string, shop_id: string, category_id: ?string, unit_price: float, quantity: int}>  $items
     * @return array<string, mixed>
     */
    private function matchBundles(array $items): array
    {
        $baseUrl = rtrim((string) config('services.promotion.url'), '/');
        if ($baseUrl === '' || $items === []) {
            return [];
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-SERVICE-KEY' => (string) config('services.promotion.api_key'),
                ])
                ->post($baseUrl . '/promotions/bundles/match', [
                    'items' => array_map(static fn (array $item): array => [
                        'product_id' => $item['product_id'],
                        'shop_id' => $item['shop_id'],
                        'unit_price' => $item['unit_price'],
                        'quantity' => $item['quantity'],
                        'selected' => true,
                    ], $items),
                ]);
        } catch (\Throwable $e) {
            logger()->warning('BUNDLE_MATCH_REQUEST_FAILED', ['error' => $e->getMessage()]);

            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $payload = $response->json();
        $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return is_array($data) ? $data : [];
    }

    /**
     * Normalize the per-shop shipping breakdown for the quote request body.
     *
     * @param  list<array{shop_id: string, amount: float}>|null  $shippingByShop
     * @return list<array{shop_id: string, amount: float}>
     */
    private function normalizeShippingByShop(?array $shippingByShop): array
    {
        $byShop = [];
        foreach ($shippingByShop ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $shopId = isset($row['shop_id']) ? trim((string) $row['shop_id']) : '';
            if ($shopId === '') {
                continue;
            }
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $byShop[$shopId] = round(($byShop[$shopId] ?? 0) + $amount, 2);
        }

        return array_map(
            static fn (string $shopId, float $amount): array => ['shop_id' => $shopId, 'amount' => $amount],
            array_keys($byShop),
            array_values($byShop),
        );
    }

    /**
     * Validate/normalize per-shop discount allocations returned by the quote.
     *
     * @param  array<int, mixed>  $allocations
     * @return list<array{promotion_id: string, promotion_name: string, reward_id: string, shop_id: string, discount_type: string, funded_by: string, amount: float, source: string}>
     */
    private function normalizeAllocations(array $allocations): array
    {
        $result = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                continue;
            }
            $shopId = isset($allocation['shop_id']) ? trim((string) $allocation['shop_id']) : '';
            $amount = round((float) ($allocation['amount'] ?? 0), 2);
            if ($shopId === '' || $amount < 0) {
                continue;
            }
            $result[] = [
                'promotion_id' => (string) ($allocation['promotion_id'] ?? ''),
                'promotion_name' => (string) ($allocation['promotion_name'] ?? ''),
                'reward_id' => (string) ($allocation['reward_id'] ?? ''),
                'shop_id' => $shopId,
                'discount_type' => ((string) ($allocation['discount_type'] ?? 'ITEM')) === 'SHIPPING' ? 'SHIPPING' : 'ITEM',
                'funded_by' => ((string) ($allocation['funded_by'] ?? 'PLATFORM')) === 'SELLER' ? 'SELLER' : 'PLATFORM',
                'amount' => $amount,
                'source' => strtoupper((string) ($allocation['source'] ?? '')) === 'BUNDLE' ? 'BUNDLE' : 'PROMOTION',
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{bundle_id: string, title: string, kind: string, amount: float, sets: int}>
     */
    private function normalizeBundleSavings(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $result[] = [
                'bundle_id' => (string) ($row['bundle_id'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'kind' => (string) ($row['kind'] ?? ''),
                'amount' => $amount,
                'sets' => max(0, (int) ($row['sets'] ?? 0)),
            ];
        }

        return $result;
    }
}
