<?php

namespace Tests\Unit;

use App\Support\BundleDiscountParts;
use PHPUnit\Framework\TestCase;

class BundleDiscountPartsTest extends TestCase
{
    public function test_bundle_savings_are_removed_from_the_promotion_discount(): void
    {
        $parts = BundleDiscountParts::fromSnapshot([
            'bundle_savings' => [
                ['bundle_id' => 'bundle-1', 'amount' => 70],
            ],
            'allocations' => [
                [
                    'promotion_id' => 'promo-1',
                    'shop_id' => 'shop-a',
                    'amount' => 10,
                    'source' => 'PROMOTION',
                ],
                [
                    'promotion_id' => 'bundle-1',
                    'shop_id' => 'shop-a',
                    'amount' => 70,
                    'source' => 'BUNDLE',
                ],
            ],
        ], 80);

        $this->assertSame(70.0, $parts['bundle_total']);
        $this->assertSame(10.0, $parts['promo_total']);
    }

    public function test_shop_and_line_discounts_keep_the_bundle_off_the_line(): void
    {
        $snapshot = [
            'applied_rewards' => [
                ['promotion_id' => 'bundle-1', 'amount' => 30, 'source' => 'BUNDLE'],
            ],
            'allocations' => [
                ['promotion_id' => 'promo-1', 'shop_id' => 'shop-a', 'amount' => 10, 'source' => 'PROMOTION'],
                ['promotion_id' => 'bundle-1', 'shop_id' => 'shop-a', 'amount' => 30, 'source' => 'BUNDLE'],
            ],
        ];
        $parts = BundleDiscountParts::fromSnapshot($snapshot, 40);
        $shops = BundleDiscountParts::perShop(
            $snapshot,
            $parts,
            ['shop-a'],
            ['shop-a' => 100],
        );
        $lines = BundleDiscountParts::promoAmountByLine([
            ['id' => 'line-1', 'shop_id' => 'shop-a', 'line_total' => 60],
            ['id' => 'line-2', 'shop_id' => 'shop-a', 'line_total' => 40],
        ], $snapshot, $parts);

        $this->assertSame(10.0, $shops['promo']['shop-a']);
        $this->assertSame(30.0, $shops['bundle']['shop-a']);
        $this->assertSame(6.0, $lines['line-1']);
        $this->assertSame(4.0, $lines['line-2']);
        $this->assertSame(10.0, round($lines['line-1'] + $lines['line-2'], 2));
    }

    public function test_orders_without_a_bundle_keep_the_full_discount_as_promotion(): void
    {
        $parts = BundleDiscountParts::fromSnapshot([
            'allocations' => [
                ['promotion_id' => 'promo-1', 'shop_id' => 'shop-a', 'amount' => 15, 'source' => 'PROMOTION'],
            ],
        ], 15);

        $this->assertSame(0.0, $parts['bundle_total']);
        $this->assertSame(15.0, $parts['promo_total']);
    }
}
