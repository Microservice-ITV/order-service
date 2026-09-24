<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Fix shop_orders: remove fulfillment_status, change order_status to unsignedTinyInteger with FK.
     */
    public function up(): void
    {
        // Remove fulfillment_status column
        if (Schema::hasColumn('shop_orders', 'fulfillment_status')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->dropColumn('fulfillment_status');
            });
        }

        // Change order_status from string(50) to unsignedTinyInteger (matches orders.order_status)
        if (Schema::hasColumn('shop_orders', 'order_status')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->unsignedTinyInteger('order_status')->default(7)->change();
            });

            // Backfill: sync existing rows with their parent order's current status
            DB::statement('
                UPDATE shop_orders so
                INNER JOIN orders o ON o.order_id = so.order_id
                SET so.order_status = o.order_status
                WHERE so.order_status <> o.order_status
            ');

            // Add foreign key constraint to order_statuses
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->foreign('order_status', 'fk_shop_orders_order_status')
                    ->references('id')
                    ->on('order_statuses')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the changes.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'order_status')) {
                $table->dropForeign('fk_shop_orders_order_status');
                $table->string('order_status', 50)->default('PENDING')->change();
            }

            if (!Schema::hasColumn('shop_orders', 'fulfillment_status')) {
                $table->string('fulfillment_status', 50)->default('PENDING');
            }
        });
    }
};
