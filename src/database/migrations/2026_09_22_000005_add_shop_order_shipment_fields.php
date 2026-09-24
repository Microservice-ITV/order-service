<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-shipment tracking fields to shop_orders so each shop
     * can be shipped independently with its own courier/tracking.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('shop_orders', 'tracking_number')) {
            Schema::table('shop_orders', function (Blueprint $table) {
                $table->string('tracking_number', 128)->nullable()->after('order_status');
                $table->string('courier_code', 64)->nullable()->after('tracking_number');
                $table->string('courier_name', 255)->nullable()->after('courier_code');
                $table->string('courier_phone', 50)->nullable()->after('courier_name');
                $table->string('tracking_url', 512)->nullable()->after('courier_phone');
                $table->timestamp('shipment_created_at')->nullable()->after('tracking_url');
            });
        }
    }

    /**
     * Reverse the changes.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn([
                'tracking_number',
                'courier_code',
                'courier_name',
                'courier_phone',
                'tracking_url',
                'shipment_created_at',
            ]);
        });
    }
};
