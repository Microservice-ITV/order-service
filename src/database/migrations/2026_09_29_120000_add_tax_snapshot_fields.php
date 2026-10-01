<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('checkout_items', 'tax_category_code')) {
                $table->string('tax_category_code', 32)->default('STANDARD')->after('category_id');
            }
        });

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (!Schema::hasColumn('checkout_sessions', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0)->after('promotion_discount_total');
            }
            if (!Schema::hasColumn('checkout_sessions', 'taxable_amount')) {
                $table->decimal('taxable_amount', 10, 2)->default(0)->after('tax_amount');
            }
            if (!Schema::hasColumn('checkout_sessions', 'tax_enabled')) {
                $table->boolean('tax_enabled')->default(true)->after('taxable_amount');
            }
            if (!Schema::hasColumn('checkout_sessions', 'tax_snapshot')) {
                $table->json('tax_snapshot')->nullable()->after('tax_enabled');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0)->after('discount_total');
            }
            if (!Schema::hasColumn('orders', 'taxable_amount')) {
                $table->decimal('taxable_amount', 10, 2)->default(0)->after('tax_amount');
            }
            if (!Schema::hasColumn('orders', 'tax_enabled')) {
                $table->boolean('tax_enabled')->default(true)->after('taxable_amount');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'tax_category_code')) {
                $table->string('tax_category_code', 32)->nullable()->after('category_id');
            }
            if (!Schema::hasColumn('order_items', 'tax_rule_id')) {
                $table->uuid('tax_rule_id')->nullable()->after('tax_category_code');
            }
            if (!Schema::hasColumn('order_items', 'tax_rate')) {
                $table->decimal('tax_rate', 7, 4)->nullable()->after('tax_rule_id');
            }
            if (!Schema::hasColumn('order_items', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_rate');
            }
        });

        Schema::table('shop_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('shop_orders', 'tax_total')) {
                $table->decimal('tax_total', 10, 2)->default(0)->after('discount_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_orders', 'tax_total')) {
                $table->dropColumn('tax_total');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            foreach (['tax_amount', 'tax_rate', 'tax_rule_id', 'tax_category_code'] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            foreach (['tax_enabled', 'taxable_amount', 'tax_amount'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            foreach (['tax_snapshot', 'tax_enabled', 'taxable_amount', 'tax_amount'] as $column) {
                if (Schema::hasColumn('checkout_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('checkout_items', function (Blueprint $table): void {
            if (Schema::hasColumn('checkout_items', 'tax_category_code')) {
                $table->dropColumn('tax_category_code');
            }
        });
    }
};
