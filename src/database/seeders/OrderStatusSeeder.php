<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (OrderStatus::cases() as $status) {
            DB::table('statuses')->updateOrInsert(
                ['id' => $status->value],
                ['name' => $status->name]
            );
        }
    }
}
