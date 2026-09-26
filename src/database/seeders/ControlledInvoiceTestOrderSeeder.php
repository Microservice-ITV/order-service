<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ControlledInvoiceTestOrderSeeder extends Seeder
{
    private const USER_ID = '184b86b3-57d2-45ae-97b3-2d9f6f6b6dc4';
    private const CHECKOUT_ID = '72000000-0000-0000-0000-000000000002';
    private const ORDER_ID = '71000000-0000-0000-0000-000000000002';
    private const RESERVATION_ID = '73000000-0000-0000-0000-000000000002';
    private const PAYMENT_ID = '74000000-0000-0000-0000-000000000002';
    private const CORRELATION_ID = 'controlled-invoice-test-0002';

    public function run(): void
    {
        $timestamp = now();

        DB::transaction(function () use ($timestamp): void {
            DB::table('orders')
                ->where('order_id', self::ORDER_ID)
                ->delete();
            DB::table('checkout_items')
                ->where('checkout_id', self::CHECKOUT_ID)
                ->delete();
            DB::table('checkout_sessions')
                ->where('id', self::CHECKOUT_ID)
                ->delete();

            DB::table('checkout_sessions')->insert([
                'id' => self::CHECKOUT_ID,
                'user_id' => self::USER_ID,
                'status_id' => OrderStatus::CONFIRMED->value,
                'correlation_id' => self::CORRELATION_ID,
                'reservation_id' => self::RESERVATION_ID,
                'payment_id' => self::PAYMENT_ID,
                'total' => 100000.00,
                'shipping_total' => 0.00,
                'promotion_discount_total' => 0.00,
                'payment_method' => 'MANUAL_TEST',
                'idempotency_key' => 'controlled-invoice-test-checkout-0002',
                'expire_at' => $timestamp->copy()->addHours(2),
                'client_secret' => 'controlled-invoice-test-client-secret',
                'delivery_address' => json_encode([
                    'full_name' => 'Test User',
                    'email' => 'test.example@gmail.com',
                    'line1' => 'Controlled Invoice Test Address',
                    'city' => 'Test City',
                    'country' => 'MM',
                ], JSON_THROW_ON_ERROR),
                'address_updated_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            DB::table('checkout_items')->insert([
                [
                    'checkout_id' => self::CHECKOUT_ID,
                    'product_id' => '76000000-0000-0000-0000-000000000001',
                    'variant_id' => null,
                    'qty' => 1,
                    'price' => 40000.00,
                    'line_total' => 40000.00,
                    'available_stock' => 10,
                    'product_slug' => 'controlled-shop-a-product',
                    'product_title' => 'Controlled Shop A Product',
                    'product_description' => 'Dedicated automatic invoice test item.',
                    'product_sku' => 'CONTROLLED-SHOP-A-001',
                    'category_id' => '77000000-0000-0000-0000-000000000001',
                    'shop_id' => '00468bc2-856e-4852-9e38-2609acb7e6cd',
                    'image_url' => 'https://example.test/controlled/shop-a.jpg',
                    'currency' => 'MMK',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'checkout_id' => self::CHECKOUT_ID,
                    'product_id' => '76000000-0000-0000-0000-000000000002',
                    'variant_id' => null,
                    'qty' => 1,
                    'price' => 35000.00,
                    'line_total' => 35000.00,
                    'available_stock' => 10,
                    'product_slug' => 'controlled-shop-b-product',
                    'product_title' => 'Controlled Shop B Product',
                    'product_description' => 'Dedicated automatic invoice test item.',
                    'product_sku' => 'CONTROLLED-SHOP-B-001',
                    'category_id' => '77000000-0000-0000-0000-000000000002',
                    'shop_id' => '67bffdd5-171b-49b2-b661-1d24a1811488',
                    'image_url' => 'https://example.test/controlled/shop-b.jpg',
                    'currency' => 'MMK',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'checkout_id' => self::CHECKOUT_ID,
                    'product_id' => '76000000-0000-0000-0000-000000000003',
                    'variant_id' => null,
                    'qty' => 1,
                    'price' => 25000.00,
                    'line_total' => 25000.00,
                    'available_stock' => 10,
                    'product_slug' => 'controlled-shop-c-product',
                    'product_title' => 'Controlled Shop C Product',
                    'product_description' => 'Dedicated automatic invoice test item.',
                    'product_sku' => 'CONTROLLED-SHOP-C-001',
                    'category_id' => '77000000-0000-0000-0000-000000000003',
                    'shop_id' => '8abb022d-1599-4f7c-b89f-47b25588823c',
                    'image_url' => 'https://example.test/controlled/shop-c.jpg',
                    'currency' => 'MMK',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ]);

            DB::table('orders')->insert([
                'order_id' => self::ORDER_ID,
                'checkout_id' => self::CHECKOUT_ID,
                'reservation_id' => self::RESERVATION_ID,
                'payment_id' => self::PAYMENT_ID,
                'status_id' => OrderStatus::CONFIRMED->value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });

        $this->command?->info('Created controlled invoice test order:');
        $this->command?->line('  order_id: ' . self::ORDER_ID);
        $this->command?->line('  checkout_id: ' . self::CHECKOUT_ID);
        $this->command?->line('  payment_id: ' . self::PAYMENT_ID);
        $this->command?->line('  total: 100000.00 MMK');
        $this->command?->line('  shops: 3');
    }
}
