<?php

namespace App\Services;

use App\Models\CheckoutItem;
use Illuminate\Support\Facades\Http;

final class PromotionQuoteService
{
    /**
     * @return array{
     *   checkout_id:string,
     *   promotion_ids:array<int,string>,
     *   discount_total:float,
     *   shipping_total:float,
     *   final_total:float,
     *   applied_rewards:array<int,array<string,mixed>>,
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

        return [
            'checkout_id' => $checkoutId,
            'promotion_ids' => array_values(array_map('strval', is_array($payload['promotion_ids'] ?? null) ? $payload['promotion_ids'] : [])),
            'discount_total' => round((float) $payload['discount_total'], 2),
            'shipping_total' => round((float) ($payload['shipping_total'] ?? $shippingTotal), 2),
            'final_total' => round((float) $payload['final_total'], 2),
            'applied_rewards' => is_array($payload['applied_rewards'] ?? null) ? $payload['applied_rewards'] : [],
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
    }
}
