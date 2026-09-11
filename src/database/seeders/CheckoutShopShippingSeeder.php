<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CheckoutShopShippingSeeder extends Seeder
{
    public function run(): void
    {
        $checkoutId = OrderSeedData::CHECKOUTS['success'];
        DB::table('checkout_shop_shipping')->whereIn('checkout_id', [$checkoutId, '20000000-0000-0000-0000-000000000004'])->delete();
        $createdAt = OrderSeedData::baseTime();
        DB::table('checkout_shop_shipping')->insert([
            ['id' => '60000000-0000-0000-0000-000000000001', 'checkout_id' => $checkoutId, 'shop_id' => 'shop-audio', 'shipping_fee' => 8.99, 'created_at' => $createdAt, 'updated_at' => $createdAt],
            ['id' => '60000000-0000-0000-0000-000000000002', 'checkout_id' => $checkoutId, 'shop_id' => 'shop-home', 'shipping_fee' => 9.50, 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ]);
    }
}
