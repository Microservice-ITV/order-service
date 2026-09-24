<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Rename line_total to subtotal and add shop_order_id and discount_amount to order_items.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Rename line_total to subtotal
            if (Schema::hasColumn('order_items', 'line_total')) {
                $table->renameColumn('line_total', 'subtotal');
            }

            // Add shop_order_id (foreign key to shop_orders)
            if (!Schema::hasColumn('order_items', 'shop_order_id')) {
                $table->uuid('shop_order_id')->nullable()->after('order_id');
                $table->foreign('shop_order_id', 'fk_order_items_shop_orders')
                    ->references('id')
                    ->on('shop_orders')
                    ->nullOnDelete();
            }

            // Add discount_amount
            if (!Schema::hasColumn('order_items', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('subtotal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Drop added columns
            $table->dropColumn([
                'shop_order_id',
                'discount_amount',
            ]);

            // Rename subtotal back to line_total
            if (Schema::hasColumn('order_items', 'subtotal')) {
                $table->renameColumn('subtotal', 'line_total');
            }
        });
    }
};
