<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('order_statuses')->updateOrInsert(['id' => 15], ['name' => 'DELIVERED']);
    }

    public function down(): void
    {
        DB::table('order_statuses')->where('id', 15)->delete();
    }
};
