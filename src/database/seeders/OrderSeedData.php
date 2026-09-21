<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;

final class OrderSeedData
{
    public const USERS = [
        '00000000-0000-0000-0000-000000000001',
        '00000000-0000-0000-0000-000000000002',
        '00000000-0000-0000-0000-000000000003',
    ];

    public const CHECKOUTS = [
        'success' => '20000000-0000-0000-0000-000000000001',
        'pending_payment' => '20000000-0000-0000-0000-000000000002',
        'failed_payment' => '20000000-0000-0000-0000-000000000003',
    ];

    public const ORDERS = [
        'success' => '50000000-0000-0000-0000-000000000001',
    ];

    public const RESERVATIONS = [
        'success' => '30000000-0000-0000-0000-000000000001',
    ];

    public const PAYMENTS = [
        'success' => '40000000-0000-0000-0000-000000000001',
        'failed' => '40000000-0000-0000-0000-000000000003',
    ];

    public static function baseTime(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-11 08:00:00', 'UTC');
    }

    public static function correlation(string $checkoutId): string
    {
        return 'corr-' . substr($checkoutId, -12);
    }

    public static function eventId(int $number): string
    {
        return sprintf('80000000-0000-0000-0000-%012d', $number);
    }

    public static function products(): array
    {
        return [
            ['id' => '10000000-0000-0000-0000-000000000001', 'variant_id' => '11000000-0000-0000-0000-000000000001', 'sku' => 'AUDIO-HEADPHONES-BLK', 'slug' => 'wireless-headphones', 'title' => 'Wireless Headphones', 'price' => 89.99, 'stock' => 24, 'shop_id' => 'shop-audio', 'category_id' => 'category-electronics'],
            ['id' => '10000000-0000-0000-0000-000000000002', 'variant_id' => null, 'sku' => 'HOME-MUG-WHT', 'slug' => 'ceramic-mug', 'title' => 'Ceramic Mug', 'price' => 18.50, 'stock' => 80, 'shop_id' => 'shop-home', 'category_id' => 'category-home'],
            ['id' => '10000000-0000-0000-0000-000000000003', 'variant_id' => null, 'sku' => 'BOOK-DESIGN-001', 'slug' => 'design-handbook', 'title' => 'The Design Handbook', 'price' => 32.00, 'stock' => 15, 'shop_id' => 'shop-books', 'category_id' => 'category-books'],
        ];
    }
}
