<?php

namespace App\Support;

/**
 * Adds catalog savings for every fixed bundle whose products are already in the cart.
 * The promotion quote can omit one of those bundles when a cart variant is cheaper than the list price.
 */
final class CoveredBundleSavings
{
    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    public static function merge(array $quote, array $match): array
    {
        $savings = is_array($quote['bundle_savings'] ?? null) ? $quote['bundle_savings'] : [];
        $known = [];
        foreach ($savings as $row) {
            if (is_array($row)) {
                $known[trim((string) ($row['bundle_id'] ?? ''))] = true;
            }
        }

        $offers = is_array($match['offers'] ?? null) ? $match['offers'] : [];
        $allocations = is_array($quote['allocations'] ?? null) ? $quote['allocations'] : [];
        $rewards = is_array($quote['applied_rewards'] ?? null) ? $quote['applied_rewards'] : [];
        $ids = is_array($quote['promotion_ids'] ?? null) ? $quote['promotion_ids'] : [];
        $room = round((float) ($quote['final_total'] ?? 0), 2);
        $added = 0.0;

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $id = trim((string) ($offer['bundle_id'] ?? ''));
            $items = is_array($offer['items'] ?? null) ? $offer['items'] : [];
            if ($id === '' || isset($known[$id]) || $items === [] || !self::covered($items)) {
                continue;
            }
            $amount = round(min($room, max(0, (float) ($offer['savings'] ?? 0))), 2);
            if ($amount <= 0) {
                continue;
            }

            $known[$id] = true;
            $room = round($room - $amount, 2);
            $added = round($added + $amount, 2);
            $title = (string) ($offer['title'] ?? '');
            $shopId = (string) ($offer['shop_id'] ?? '');
            $savings[] = [
                'bundle_id' => $id,
                'title' => $title,
                'kind' => 'FIXED',
                'amount' => $amount,
                'sets' => 1,
            ];
            $allocations[] = [
                'promotion_id' => $id,
                'promotion_name' => $title,
                'reward_id' => $id,
                'shop_id' => $shopId,
                'discount_type' => 'ITEM',
                'funded_by' => 'SELLER',
                'amount' => $amount,
                'source' => 'BUNDLE',
            ];
            $rewards[] = [
                'reward_id' => $id,
                'promotion_id' => $id,
                'promotion_name' => $title,
                'amount' => $amount,
                'source' => 'BUNDLE',
                'bundle_kind' => 'FIXED',
            ];
            $ids[] = $id;
        }

        if ($added <= 0) {
            return $quote;
        }

        $quote['bundle_savings'] = array_values($savings);
        $quote['allocations'] = array_values($allocations);
        $quote['applied_rewards'] = array_values($rewards);
        $quote['promotion_ids'] = array_values(array_unique(array_map('strval', $ids)));
        $quote['discount_total'] = round((float) ($quote['discount_total'] ?? 0) + $added, 2);
        $quote['final_total'] = $room;

        return $quote;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private static function covered(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['in_cart'])) {
                return false;
            }
        }

        return true;
    }
}
