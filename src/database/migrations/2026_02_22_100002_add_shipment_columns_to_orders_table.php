<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_code', 64)->nullable()->after('status_id');
            $table->string('courier_name', 255)->nullable()->after('courier_code');
            $table->string('courier_phone', 50)->nullable()->after('courier_name');
            $table->string('tracking_number', 128)->nullable()->after('courier_phone');
            $table->string('tracking_url', 512)->nullable()->after('tracking_number');
            $table->timestamp('shipment_created_at')->nullable()->after('tracking_url');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'courier_code',
                'courier_name',
                'courier_phone',
                'tracking_number',
                'tracking_url',
                'shipment_created_at',
            ]);
        });
    }
};
