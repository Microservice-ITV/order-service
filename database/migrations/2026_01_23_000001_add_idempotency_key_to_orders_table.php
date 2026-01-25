<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Store only a fixed-length hash (not the raw client key) for security + index efficiency.
            // Nullable so existing orders don't need backfilling; MySQL unique index allows multiple NULLs.
            $table->char('idempotency_key', 64)->nullable()->after('failure_reason');
            $table->unique('idempotency_key', 'orders_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

