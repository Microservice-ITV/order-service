<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_shop_shipping', function (Blueprint $table) {
            $table->uuid('provider_id')->nullable()->index();
        });

        Schema::table('shop_orders', function (Blueprint $table) {
            $table->uuid('provider_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropIndex(['provider_id']);
            $table->dropColumn('provider_id');
        });

        Schema::table('checkout_shop_shipping', function (Blueprint $table) {
            $table->dropIndex(['provider_id']);
            $table->dropColumn('provider_id');
        });
    }
};
