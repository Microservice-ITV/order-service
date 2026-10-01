<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('checkout_items', 'tax_rate')) {
                $table->decimal('tax_rate', 7, 4)->nullable()->after('category_id');
            }
            if (!Schema::hasColumn('checkout_items', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_rate');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'tax_category_code')) {
                $table->dropColumn('tax_category_code');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'tax_rule_id')) {
                $table->dropColumn('tax_rule_id');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'tax_rate')) {
                $table->dropColumn('tax_rate');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'tax_category_code')) {
                $table->string('tax_category_code', 32)->nullable()->after('category_id');
            }
            if (!Schema::hasColumn('order_items', 'tax_rule_id')) {
                $table->uuid('tax_rule_id')->nullable();
            }
            if (!Schema::hasColumn('order_items', 'tax_rate')) {
                $table->decimal('tax_rate', 7, 4)->nullable();
            }
        });

        Schema::table('checkout_items', function (Blueprint $table): void {
            foreach (['tax_amount', 'tax_rate'] as $column) {
                if (Schema::hasColumn('checkout_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
