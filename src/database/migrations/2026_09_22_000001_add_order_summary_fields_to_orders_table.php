<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add order summary fields and rename status_id to order_status.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Rename status_id to order_status
            if (Schema::hasColumn('orders', 'status_id')) {
                $table->renameColumn('status_id', 'order_status');
            }

            // Add order summary fields
            $table->uuid('user_id')->nullable()->after('order_id');
            $table->string('currency', 10)->default('MMK')->after('user_id');
            $table->decimal('subtotal', 10, 2)->default(0)->after('currency');
            $table->decimal('shipping_total', 10, 2)->default(0)->after('subtotal');
            $table->decimal('discount_total', 10, 2)->default(0)->after('shipping_total');
            $table->decimal('grand_total', 10, 2)->default(0)->after('discount_total');
            $table->string('payment_status', 50)->default('PENDING')->after('grand_total');

            // Add index for user_id (was indexed in original orders table)
            if (Schema::hasColumn('orders', 'user_id')) {
                $table->index('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Rename order_status back to status_id
            if (Schema::hasColumn('orders', 'order_status')) {
                $table->renameColumn('order_status', 'status_id');
            }

            // Drop added columns
            $table->dropColumn([
                'user_id',
                'currency',
                'subtotal',
                'shipping_total',
                'discount_total',
                'grand_total',
                'payment_status',
            ]);
        });
    }
};
