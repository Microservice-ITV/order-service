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
            ['id' => 1, 'name' => 'PENDING_STOCK'],
            ['id' => 2, 'name' => 'STOCK_CONFIRMED'],
            ['id' => 3, 'name' => 'FAILED_STOCK'],
            ['id' => 4, 'name' => 'PAID'],
            ['id' => 5, 'name' => 'FAILED_PAYMENT'],
            ['id' => 6, 'name' => 'PENDING_PAYMENT'],
            ['id' => 7, 'name' => 'CANCELLED'],
        ]);        
        
    }

    public function down(): void
    {
        Schema::dropIfExists('order_statuses');
    }
};

