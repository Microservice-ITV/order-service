<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutItemSeeder extends Seeder
{
    public function run(): void
    {
        $checkoutIds = [...array_values(OrderSeedData::CHECKOUTS), '20000000-0000-0000-0000-000000000004'];
        DB::table('checkout_items')->whereIn('checkout_id', $checkoutIds)->delete();
        $products = OrderSeedData::products();
        $base = OrderSeedData::baseTime();

        $this->insertItem(OrderSeedData::CHECKOUTS['success'], $products[0], 2, $base);
        $this->insertItem(OrderSeedData::CHECKOUTS['success'], $products[1], 1, $base);
        $this->insertItem(OrderSeedData::CHECKOUTS['pending_payment'], $products[2], 1, $base->addMinutes(10));
        $this->insertItem(OrderSeedData::CHECKOUTS['failed_payment'], $products[2], 1, $base->addMinutes(20));
    }

    private function insertItem(string $checkoutId, array $product, int $qty, $createdAt): void
    {
        DB::table('checkout_items')->insert([
            'id' => (string) Str::uuid(),
            'checkout_id' => $checkoutId,
            'product_id' => $product['id'],
            'variant_id' => $product['variant_id'],
            'qty' => $qty,
            'price' => $product['price'],
            'line_total' => round($product['price'] * $qty, 2),
            'available_stock' => $product['stock'],
            'product_slug' => $product['slug'],
            'product_title' => $product['title'],
            'product_description' => 'Seeded product snapshot for lifecycle testing.',
            'product_sku' => $product['sku'],
            'category_id' => $product['category_id'],
            'shop_id' => $product['shop_id'],
            'image_url' => 'https://example.test/images/' . $product['slug'] . '.jpg',
            'currency' => 'USD',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
