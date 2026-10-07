<?php

namespace App\Support;

/**
 * Separates bundle savings from promotion discounts.
 *
 * The quote's discount_total includes both. Orders keep the promotion share in
 * discount_total and the bundle share in bundle_discount. Line items never
 * receive the bundle share.
 */
final class BundleDiscountParts
{
    /**
     * @param  array<string, mixed>  $snapshot  checkout promotion_snapshot
     * @return array{bundle_total: float, promo_total: float, bundle_ids: array<string, true>}
     */
    public static function fromSnapshot(array $snapshot, float $combinedDiscount): array
    {
        $combinedDiscount = round(max(0.0, $combinedDiscount), 2);
        $bundleIds = [];
        $bundleTotal = 0.0;

        foreach (self::rows($snapshot['bundle_savings'] ?? null) as $row) {
            $id = trim((string) ($row['bundle_id'] ?? ''));
            if ($id !== '') {
                $bundleIds[$id] = true;
            }
            $bundleTotal = round($bundleTotal + max(0.0, (float) ($row['amount'] ?? 0)), 2);
        }

        if ($bundleTotal <= 0) {
            foreach (self::rows($snapshot['applied_rewards'] ?? null) as $row) {
                if (strtoupper((string) ($row['source'] ?? '')) !== 'BUNDLE') {
                    continue;
                }
                $id = trim((string) ($row['promotion_id'] ?? ''));
                if ($id !== '') {
                    $bundleIds[$id] = true;
                }
                $bundleTotal = round($bundleTotal + max(0.0, (float) ($row['amount'] ?? 0)), 2);
            }
        }

        $bundleTotal = round(min($bundleTotal, $combinedDiscount), 2);

        return [
            'bundle_total' => $bundleTotal,
            'promo_total' => round($combinedDiscount - $bundleTotal, 2),
            'bundle_ids' => $bundleIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{bundle_total: float, promo_total: float, bundle_ids: array<string, true>}  $parts
     * @param  list<string>  $shopIds
     * @param  array<string, float>  $shopSubtotals
     * @return array{promo: array<string, float>, bundle: array<string, float>}
     */
    public static function perShop(array $snapshot, array $parts, array $shopIds, array $shopSubtotals): array
    {
        $promoAllocations = [];
        $bundleAllocations = [];
        foreach (self::rows($snapshot['allocations'] ?? null) as $allocation) {
            if (self::isBundleAllocation($allocation, $parts['bundle_ids'])) {
                $bundleAllocations[] = $allocation;
                continue;
            }
            $promoAllocations[] = $allocation;
        }

        return [
            'promo' => PromotionAllocationSplitter::splitPerShop(
                $promoAllocations,
                $shopIds,
                $shopSubtotals,
                $parts['promo_total'],
            ),
            'bundle' => PromotionAllocationSplitter::splitPerShop(
                $bundleAllocations,
                $shopIds,
                $shopSubtotals,
                $parts['bundle_total'],
            ),
        ];
    }

    /**
     * Promotion-only discount for each checkout line. Bundle savings are omitted.
     *
     * @param  list<array{id: string, shop_id: string, line_total: float}>  $lines
     * @param  array<string, mixed>  $snapshot
     * @param  array{bundle_total: float, promo_total: float, bundle_ids: array<string, true>}  $parts
     * @return array<string, float>
     */
    public static function promoAmountByLine(array $lines, array $snapshot, array $parts): array
    {
        $shopIds = [];
        $shopSubtotals = [];
        $linesByShop = [];
        foreach ($lines as $line) {
            $shopId = trim((string) ($line['shop_id'] ?? ''));
            if ($shopId === '') {
                $shopId = '_unassigned';
            }
            $lineTotal = round(max(0.0, (float) ($line['line_total'] ?? 0)), 2);
            $shopIds[$shopId] = $shopId;
            $shopSubtotals[$shopId] = round(($shopSubtotals[$shopId] ?? 0) + $lineTotal, 2);
            $linesByShop[$shopId][] = [
                'id' => (string) $line['id'],
                'weight' => $lineTotal,
            ];
        }

        $perShop = self::perShop($snapshot, $parts, array_values($shopIds), $shopSubtotals);
        $byLine = [];
        foreach ($linesByShop as $shopId => $shopLines) {
            $ids = [];
            $weights = [];
            foreach ($shopLines as $line) {
                $ids[] = $line['id'];
                $weights[$line['id']] = $line['weight'];
            }
            $shares = PromotionAllocationSplitter::proportional(
                $ids,
                $weights,
                (float) ($perShop['promo'][$shopId] ?? 0),
            );
            foreach ($shares as $lineId => $amount) {
                $byLine[$lineId] = $amount;
            }
        }

        return $byLine;
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @param  array<string, true>  $bundleIds
     */
    private static function isBundleAllocation(array $allocation, array $bundleIds): bool
    {
        if (strtoupper((string) ($allocation['source'] ?? '')) === 'BUNDLE') {
            return true;
        }
        $id = trim((string) ($allocation['promotion_id'] ?? ''));

        return $id !== '' && isset($bundleIds[$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
