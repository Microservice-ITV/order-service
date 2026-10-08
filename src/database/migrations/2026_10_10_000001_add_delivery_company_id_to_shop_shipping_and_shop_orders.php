<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_shop_shipping', function (Blueprint $table): void {
            $table->string('delivery_company_id', 64)->nullable()->index();
        });

        Schema::table('shop_orders', function (Blueprint $table): void {
            $table->string('delivery_company_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table): void {
            $table->dropIndex(['delivery_company_id']);
            $table->dropColumn('delivery_company_id');
        });

        Schema::table('checkout_shop_shipping', function (Blueprint $table): void {
            $table->dropIndex(['delivery_company_id']);
            $table->dropColumn('delivery_company_id');
        });
    }
};
