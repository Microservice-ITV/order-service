<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('name')->unique();
        });

        DB::table('order_statuses')->insert([
            ['id' => 1, 'name' => 'pending_stock'],
            ['id' => 2, 'name' => 'stock_confirmed'],
            ['id' => 3, 'name' => 'failed_stock'],
            ['id' => 4, 'name' => 'paid'],
            ['id' => 5, 'name' => 'failed_payment'],
            ['id' => 6, 'name' => 'pending_payment'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_statuses');
    }
};

