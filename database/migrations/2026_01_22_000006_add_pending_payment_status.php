<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!DB::table('order_statuses')->where('id', 6)->exists()) {
            DB::table('order_statuses')->insert([
                'id' => 6,
                'name' => 'pending_payment',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('order_statuses')->where('id', 6)->delete();
    }
};

