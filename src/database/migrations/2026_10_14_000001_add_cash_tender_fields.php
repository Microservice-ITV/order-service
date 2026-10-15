<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (!Schema::hasColumn('checkout_sessions', 'cash_paid_amount')) {
                $table->decimal('cash_paid_amount', 12, 2)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('checkout_sessions', 'cash_refund_amount')) {
                $table->decimal('cash_refund_amount', 12, 2)->nullable()->after('cash_paid_amount');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'cash_paid_amount')) {
                $table->decimal('cash_paid_amount', 12, 2)->nullable()->after('grand_total');
            }
            if (!Schema::hasColumn('orders', 'cash_refund_amount')) {
                $table->decimal('cash_refund_amount', 12, 2)->nullable()->after('cash_paid_amount');
            }
        });

        Schema::table('shop_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('shop_orders', 'cash_paid_amount')) {
                $table->decimal('cash_paid_amount', 12, 2)->nullable()->after('grand_total');
            }
            if (!Schema::hasColumn('shop_orders', 'cash_refund_amount')) {
                $table->decimal('cash_refund_amount', 12, 2)->nullable()->after('cash_paid_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('checkout_sessions', 'cash_refund_amount')) {
                $table->dropColumn('cash_refund_amount');
            }
            if (Schema::hasColumn('checkout_sessions', 'cash_paid_amount')) {
                $table->dropColumn('cash_paid_amount');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'cash_refund_amount')) {
                $table->dropColumn('cash_refund_amount');
            }
            if (Schema::hasColumn('orders', 'cash_paid_amount')) {
                $table->dropColumn('cash_paid_amount');
            }
        });

        Schema::table('shop_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_orders', 'cash_refund_amount')) {
                $table->dropColumn('cash_refund_amount');
            }
            if (Schema::hasColumn('shop_orders', 'cash_paid_amount')) {
                $table->dropColumn('cash_paid_amount');
            }
        });
    }
};
