<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Drop FK using ORIGINAL constraint name
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_checkout_id_foreign');
        });

        // 2. Rename table
        Schema::rename('order_items', 'checkout_items');

        // 3. Re-create FK with new reference
        Schema::table('checkout_items', function (Blueprint $table) {
            $table->foreign('checkout_id')
                ->references('id')
                ->on('checkout_sessions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // 1. Drop FK created in up()
        Schema::table('checkout_items', function (Blueprint $table) {
            $table->dropForeign('checkout_items_order_id_foreign');
        });

        // 2. Rename back
        Schema::rename('checkout_items', 'order_items');

        // 3. Restore original FK
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('checkout_id')
                ->references('id')
                ->on('checkout_sessions')
                ->cascadeOnDelete();
        });
    }
};

