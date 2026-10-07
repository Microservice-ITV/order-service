<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'bundle_discount')) {
                $table->decimal('bundle_discount', 10, 2)->default(0)->after('discount_total');
            }
        });

        Schema::table('shop_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('shop_orders', 'bundle_discount')) {
                $table->decimal('bundle_discount', 10, 2)->default(0)->after('discount_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_orders', 'bundle_discount')) {
                $table->dropColumn('bundle_discount');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'bundle_discount')) {
                $table->dropColumn('bundle_discount');
            }
        });
    }
};
