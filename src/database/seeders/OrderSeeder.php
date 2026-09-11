<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $orderId = OrderSeedData::ORDERS['success'];
        DB::table('orders')->whereIn('order_id', [$orderId, '50000000-0000-0000-0000-000000000002'])->delete();
        $createdAt = OrderSeedData::baseTime()->addMinutes(45);
        DB::table('orders')->insert([
            'order_id' => $orderId,
            'checkout_id' => OrderSeedData::CHECKOUTS['success'],
            'reservation_id' => OrderSeedData::RESERVATIONS['success'],
            'payment_id' => OrderSeedData::PAYMENTS['success'],
            'status_id' => OrderStatus::CONFIRMED->value,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
