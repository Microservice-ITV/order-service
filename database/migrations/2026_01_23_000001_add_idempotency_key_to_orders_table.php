<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Note: This migration runs before the rename, so it still references 'orders'
        // The table will be renamed to 'checkout_sessions' in a later migration
        Schema::table('orders', function (Blueprint $table) {
            // Store only a fixed-length hash (not the raw client key) for security + index efficiency.
            // Nullable so existing orders don't need backfilling; MySQL unique index allows multiple NULLs.
            $table->string('idempotency_key', 255)->nullable()->index()->after('failure_reason');
            $table->unique('idempotency_key', 'checkout_sesssions_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('checkout_sesssions_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

