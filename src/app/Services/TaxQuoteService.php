<?php

namespace App\Services;

use App\Models\CategoryTaxCharge;
use App\Models\CheckoutItem;
use App\Models\TaxChargeSetting;
use App\Support\PromotionAllocationSplitter;

final class TaxQuoteService
{
    /**
     * Quote tax from the category rates last published by tax-service.
     * Shipping is not taxed. Each line uses its product category.
     *
     * @param  array<int, mixed>  $allocations
     * @return array{
     *   tax_enabled: bool,
     *   tax_amount: float,
     *   taxable_amount: float,
     *   reason: ?string,
     *   lines: list<array<string, mixed>>
     * }
     */
    public function quote(string $checkoutId, float $discountTotal, array $allocations = []): array
    {
        $items = CheckoutItem::query()
            ->where('checkout_id', $checkoutId)
            ->orderBy('created_at')
            ->get();

        if ($items->isEmpty()) {
            return $this->emptyQuote();
        }

        $settings = TaxChargeSetting::query()->find('default');
        $taxEnabled = $settings === null || (bool) $settings->is_enabled;
        $categoryIds = $items
            ->map(fn (CheckoutItem $item): string => trim((string) ($item->category_id ?? '')))
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
        $charges = $categoryIds === []
            ? collect()
            : CategoryTaxCharge::query()->whereIn('category_id', $categoryIds)->get()->keyBy('category_id');

        $lineDiscounts = $this->allocateLineDiscounts($items->all(), $discountTotal, $allocations);
        $lines = [];
        $taxCents = 0;
        $taxableCents = 0;

        foreach ($items as $item) {
            $lineId = (string) $item->id;
            $amount = round((float) ($item->line_total ?? ((float) $item->price * (int) $item->qty)), 2);
            $discount = round((float) ($lineDiscounts[$lineId] ?? 0), 2);
            $baseCents = max(0, $this->toCents($amount) - $this->toCents($discount));
            $categoryId = trim((string) ($item->category_id ?? ''));
            $charge = $categoryId !== '' ? $charges->get($categoryId) : null;
            $reason = null;
            $rate = null;
            $lineTaxCents = 0;

            if (!$taxEnabled) {
                $reason = 'TAX_DISABLED';
            } elseif (!$charge || !(bool) $charge->is_active) {
                $reason = 'NO_TAX_FOR_CATEGORY';
            } else {
                $rate = round((float) $charge->rate, 4);
                $lineTaxCents = $this->taxCents($baseCents, $rate);
                $taxableCents += $baseCents;
            }

            $taxCents += $lineTaxCents;
            $lines[] = [
                'line_id' => $lineId,
                'product_id' => (string) $item->product_id,
                'variant_id' => $item->variant_id !== null && (string) $item->variant_id !== ''
                    ? (string) $item->variant_id
                    : null,
                'category_id' => $categoryId !== '' ? $categoryId : null,
                'tax_id' => $charge ? (string) $charge->tax_id : null,
                'tax_name' => $charge ? (string) $charge->tax_name : null,
                'tax_rule_id' => $charge ? (string) $charge->tax_id : null,
                'tax_rate' => $rate,
                'discount_allocated' => $discount,
                'tax_amount' => $this->fromCents($lineTaxCents),
                'reason' => $reason,
            ];
        }

        return [
            'tax_enabled' => $taxEnabled,
            'tax_amount' => $this->fromCents($taxCents),
            'taxable_amount' => $this->fromCents($taxableCents),
            'reason' => $taxEnabled ? null : 'TAX_DISABLED',
            'lines' => $lines,
        ];
    }

    /**
     * Quote tax from category ids already known on the client.
     * Used when checkout opens, before shipping is combined.
     *
     * @param  list<array{line_id?: string, category_id?: string, amount?: float|int|string}>  $lines
     * @return array{tax_enabled: bool, tax_amount: float, taxable_amount: float, reason: ?string, lines: list<array<string, mixed>>}
     */
    public function quoteCategoryLines(array $lines): array
    {
        if ($lines === []) {
            return $this->emptyQuote();
        }

        $settings = TaxChargeSetting::query()->find('default');
        $taxEnabled = $settings === null || (bool) $settings->is_enabled;
        $categoryIds = [];
        foreach ($lines as $line) {
            $categoryId = trim((string) ($line['category_id'] ?? ''));
            if ($categoryId !== '') {
                $categoryIds[$categoryId] = $categoryId;
            }
        }
        $charges = $categoryIds === []
            ? collect()
            : CategoryTaxCharge::query()->whereIn('category_id', array_values($categoryIds))->get()->keyBy('category_id');

        $quoted = [];
        $taxCents = 0;
        $taxableCents = 0;
        foreach ($lines as $index => $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            $baseCents = max(0, $this->toCents($amount));
            $categoryId = trim((string) ($line['category_id'] ?? ''));
            $charge = $categoryId !== '' ? $charges->get($categoryId) : null;
            $reason = null;
            $rate = null;
            $lineTaxCents = 0;

            if (!$taxEnabled) {
                $reason = 'TAX_DISABLED';
            } elseif (!$charge || !(bool) $charge->is_active) {
                $reason = 'NO_TAX_FOR_CATEGORY';
            } else {
                $rate = round((float) $charge->rate, 4);
                $lineTaxCents = $this->taxCents($baseCents, $rate);
                $taxableCents += $baseCents;
            }

            $taxCents += $lineTaxCents;
            $quoted[] = [
                'line_id' => (string) ($line['line_id'] ?? $index),
                'product_id' => (string) ($line['product_id'] ?? $line['line_id'] ?? $index),
                'variant_id' => isset($line['variant_id']) && (string) $line['variant_id'] !== ''
                    ? (string) $line['variant_id']
                    : null,
                'category_id' => $categoryId !== '' ? $categoryId : null,
                'tax_id' => $charge ? (string) $charge->tax_id : null,
                'tax_name' => $charge ? (string) $charge->tax_name : null,
                'tax_rate' => $rate,
                'tax_amount' => $this->fromCents($lineTaxCents),
                'reason' => $reason,
            ];
        }

        return [
            'tax_enabled' => $taxEnabled,
            'tax_amount' => $this->fromCents($taxCents),
            'taxable_amount' => $this->fromCents($taxableCents),
            'reason' => $taxEnabled ? null : 'TAX_DISABLED',
            'lines' => $quoted,
        ];
    }

    /**
     * @param  list<CheckoutItem>  $items
     * @param  array<int, mixed>  $allocations
     * @return array<string, float>
     */
    private function allocateLineDiscounts(array $items, float $discountTotal, array $allocations): array
    {
        $shopIds = [];
        $shopSubtotals = [];
        $linesByShop = [];
        foreach ($items as $item) {
            $shopId = (string) ($item->shop_id ?? '');
            if ($shopId === '') {
                $shopId = '_unassigned';
            }
            $lineTotal = round((float) ($item->line_total ?? ((float) $item->price * (int) $item->qty)), 2);
            $shopIds[$shopId] = $shopId;
            $shopSubtotals[$shopId] = round(($shopSubtotals[$shopId] ?? 0) + $lineTotal, 2);
            $linesByShop[$shopId][] = $item;
        }

        $discountByShop = PromotionAllocationSplitter::splitPerShop(
            $allocations,
            array_values($shopIds),
            $shopSubtotals,
            $discountTotal,
        );

        $byLine = [];
        foreach ($linesByShop as $shopId => $shopItems) {
            $lineIds = [];
            $weights = [];
            foreach ($shopItems as $item) {
                $lineId = (string) $item->id;
                $lineIds[] = $lineId;
                $weights[$lineId] = round((float) ($item->line_total ?? ((float) $item->price * (int) $item->qty)), 2);
            }
            $shares = PromotionAllocationSplitter::proportional(
                $lineIds,
                $weights,
                (float) ($discountByShop[$shopId] ?? 0),
            );
            foreach ($shares as $lineId => $amount) {
                $byLine[$lineId] = $amount;
            }
        }

        return $byLine;
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function taxCents(int $baseCents, float $ratePercent): int
    {
        if ($baseCents <= 0 || $ratePercent <= 0) {
            return 0;
        }

        return (int) round(($baseCents * $ratePercent) / 100);
    }

    /**
     * @return array{tax_enabled: bool, tax_amount: float, taxable_amount: float, reason: ?string, lines: list<array<string, mixed>>}
     */
    private function emptyQuote(): array
    {
        return [
            'tax_enabled' => true,
            'tax_amount' => 0.0,
            'taxable_amount' => 0.0,
            'reason' => null,
            'lines' => [],
        ];
    }
}
