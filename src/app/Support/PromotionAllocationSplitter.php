<?php

namespace App\Support;

/**
 * Maps promotion-quote allocations to per-shop discount amounts used when
 * splitting an order into shop-level orders.
 *
 * The order-level discount must be attributed to shops exactly (sum of shares
 * == discount total), so Σ shop grand totals equals the charged order total.
 */
final class PromotionAllocationSplitter
{
    /**
     * Resolve each shop's discount share.
     *
     * Uses the quote's per-shop allocations when they cover the discount
     * total within tolerance; otherwise falls back to subtotal-proportional
     * splitting (legacy checkouts quoted before allocations existed).
     *
     * @param  array<int, mixed>  $allocations  promotion_snapshot.allocations
     * @param  list<string>  $shopIds  shop ids present in the order, stable order
     * @param  array<string, float>  $shopSubtotals  per-shop item subtotals (fallback weights)
     * @param  float  $discountTotal  order-level discount that must be fully attributed
     * @return array<string, float> shop id => discount, summing exactly to $discountTotal
     */
    public static function splitPerShop(array $allocations, array $shopIds, array $shopSubtotals, float $discountTotal): array
    {
        $discountTotal = round(max(0.0, $discountTotal), 2);

        if ($discountTotal === 0.0 || $shopIds === []) {
            return array_fill_keys($shopIds, 0.0);
        }

        $byShop = self::fromAllocations($allocations, $shopIds);

        if ($byShop === null || abs(array_sum($byShop) - $discountTotal) > 0.02) {
            return self::proportional($shopIds, $shopSubtotals, $discountTotal);
        }

        $result = [];
        foreach ($shopIds as $shopId) {
            $result[$shopId] = round($byShop[$shopId] ?? 0.0, 2);
        }

        // Absorb any residual rounding cent into the largest allocated shop
        // so the per-shop discounts add up to the order-level discount exactly.
        $delta = round($discountTotal - array_sum($result), 2);
        if (abs($delta) >= 0.01) {
            $target = null;
            foreach ($result as $shopId => $amount) {
                if ($amount > 0 && ($target === null || $amount > $result[$target])) {
                    $target = $shopId;
                }
            }
            $target ??= $shopIds[count($shopIds) - 1];
            $result[$target] = round($result[$target] + $delta, 2);
        }

        return $result;
    }

    /**
     * Split a discount proportionally by shop subtotals; the last shop absorbs
     * rounding so the shares sum exactly to $discountTotal.
     *
     * @param  list<string>  $shopIds
     * @param  array<string, float>  $shopSubtotals
     * @return array<string, float>
     */
    public static function proportional(array $shopIds, array $shopSubtotals, float $discountTotal): array
    {
        $discountTotal = round(max(0.0, $discountTotal), 2);
        if ($shopIds === []) {
            return [];
        }

        $totalWeight = array_sum(array_map(
            static fn (string $shopId): float => max(0.0, (float) ($shopSubtotals[$shopId] ?? 0.0)),
            $shopIds,
        ));

        $result = [];
        $assigned = 0.0;
        $lastIndex = count($shopIds) - 1;

        foreach ($shopIds as $index => $shopId) {
            if ($index === $lastIndex) {
                $result[$shopId] = round($discountTotal - $assigned, 2);
                continue;
            }
            $share = $totalWeight > 0
                ? round($discountTotal * (max(0.0, (float) ($shopSubtotals[$shopId] ?? 0.0)) / $totalWeight), 2)
                : round($discountTotal / count($shopIds), 2);
            $assigned = round($assigned + $share, 2);
            $result[$shopId] = $share;
        }

        return $result;
    }

    /**
     * Sum allocation amounts per shop, or null when the allocations are not
     * usable (empty, malformed, or referencing shops outside the order).
     *
     * @param  array<int, mixed>  $allocations
     * @param  list<string>  $shopIds
     * @return array<string, float>|null
     */
    private static function fromAllocations(array $allocations, array $shopIds): ?array
    {
        if ($allocations === []) {
            return null;
        }

        $allowed = array_flip($shopIds);
        $byShop = array_fill_keys($shopIds, 0.0);

        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                return null;
            }
            $shopId = isset($allocation['shop_id']) ? trim((string) $allocation['shop_id']) : '';
            if ($shopId === '' || !isset($allowed[$shopId])) {
                return null;
            }
            $amount = round((float) ($allocation['amount'] ?? 0), 2);
            if ($amount < 0) {
                return null;
            }
            $byShop[$shopId] = round($byShop[$shopId] + $amount, 2);
        }

        return $byShop;
    }
}
