<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (!Schema::hasColumn('checkout_sessions', 'promotion_discount_total')) {
                $table->decimal('promotion_discount_total', 10, 2)->default(0)->after('shipping_total');
            }
            if (!Schema::hasColumn('checkout_sessions', 'promotion_snapshot')) {
                $table->json('promotion_snapshot')->nullable()->after('promotion_discount_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('checkout_sessions', 'promotion_snapshot')) {
                $table->dropColumn('promotion_snapshot');
            }
            if (Schema::hasColumn('checkout_sessions', 'promotion_discount_total')) {
                $table->dropColumn('promotion_discount_total');
            }
        });
    }
};
