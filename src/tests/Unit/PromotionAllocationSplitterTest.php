<?php

namespace Tests\Unit;

use App\Support\PromotionAllocationSplitter;
use PHPUnit\Framework\TestCase;

class PromotionAllocationSplitterTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function allocations(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'promotion_id' => 'promo-1',
                'promotion_name' => 'Test Promo',
                'reward_id' => 'reward-1',
                'shop_id' => $row['shop_id'],
                'discount_type' => $row['discount_type'] ?? 'ITEM',
                'funded_by' => $row['funded_by'] ?? 'SELLER',
                'amount' => $row['amount'],
            ],
            $rows,
        );
    }

    public function test_exact_allocation_is_applied_per_shop(): void
    {
        $result = PromotionAllocationSplitter::splitPerShop(
            self::allocations([
                ['shop_id' => 'shop-a', 'amount' => 9.61],
            ]),
            ['shop-a', 'shop-b'],
            ['shop-a' => 96.07, 'shop-b' => 33.22],
            9.61,
        );

        $this->assertSame(
            ['shop-a' => 9.61, 'shop-b' => 0.0],
            $result,
            'Shop-scoped promo must not discount the other shop',
        );
    }

    public function test_allocations_sum_must_match_discount_total_or_fall_back(): void
    {
        // Allocation total (5.00) disagrees with the order discount (9.61)
        // beyond tolerance => proportional fallback.
        $result = PromotionAllocationSplitter::splitPerShop(
            self::allocations([
                ['shop_id' => 'shop-a', 'amount' => 5.00],
            ]),
            ['shop-a', 'shop-b'],
            ['shop-a' => 96.07, 'shop-b' => 33.22],
            9.61,
        );

        $this->assertSame(7.14, $result['shop-a']);
        $this->assertSame(2.47, $result['shop-b']);
        $this->assertSame(9.61, round(array_sum($result), 2));
    }

    public function test_unknown_shop_in_allocations_falls_back_to_proportional(): void
    {
        $result = PromotionAllocationSplitter::splitPerShop(
            self::allocations([
                ['shop_id' => 'shop-ghost', 'amount' => 9.61],
            ]),
            ['shop-a', 'shop-b'],
            ['shop-a' => 96.07, 'shop-b' => 33.22],
            9.61,
        );

        $this->assertSame(7.14, $result['shop-a']);
        $this->assertSame(9.61, round(array_sum($result), 2));
    }

    public function test_empty_allocations_use_proportional_split(): void
    {
        $result = PromotionAllocationSplitter::splitPerShop(
            [],
            ['shop-a', 'shop-b'],
            ['shop-a' => 96.07, 'shop-b' => 33.22],
            9.61,
        );

        $this->assertSame(7.14, $result['shop-a']);
        $this->assertSame(2.47, $result['shop-b']);
        $this->assertSame(9.61, round(array_sum($result), 2));
    }

    public function test_rounding_cent_is_absorbed_so_shops_sum_exactly(): void
    {
        // 0.05 across three equal-weight shops => 0.02 + 0.02 + 0.01.
        $result = PromotionAllocationSplitter::proportional(
            ['shop-a', 'shop-b', 'shop-c'],
            ['shop-a' => 10.0, 'shop-b' => 10.0, 'shop-c' => 10.0],
            0.05,
        );

        $this->assertSame(0.05, round(array_sum($result), 2));
    }

    public function test_multiple_allocations_for_one_shop_are_summed(): void
    {
        $result = PromotionAllocationSplitter::splitPerShop(
            self::allocations([
                ['shop_id' => 'shop-a', 'discount_type' => 'ITEM', 'amount' => 4.00],
                ['shop_id' => 'shop-a', 'discount_type' => 'SHIPPING', 'amount' => 6.00],
                ['shop_id' => 'shop-b', 'discount_type' => 'ITEM', 'amount' => 2.00],
            ]),
            ['shop-a', 'shop-b'],
            ['shop-a' => 40.0, 'shop-b' => 20.0],
            12.0,
        );

        $this->assertSame(
            ['shop-a' => 10.0, 'shop-b' => 2.0],
            $result,
        );
    }

    public function test_zero_discount_total_returns_zeroes(): void
    {
        $result = PromotionAllocationSplitter::splitPerShop(
            [],
            ['shop-a', 'shop-b'],
            ['shop-a' => 40.0, 'shop-b' => 20.0],
            0.0,
        );

        $this->assertSame(['shop-a' => 0.0, 'shop-b' => 0.0], $result);
    }

    public function test_equal_split_when_no_item_subtotals_exist(): void
    {
        $result = PromotionAllocationSplitter::proportional(
            ['shop-a', 'shop-b'],
            [],
            10.0,
        );

        $this->assertSame(['shop-a' => 5.0, 'shop-b' => 5.0], $result);
    }
}
