<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'status_id')) {
                $table->unsignedTinyInteger('status_id')->nullable()->after('id');
            }
        });

        if (Schema::hasColumn('orders', 'status')) {
            DB::statement("
                UPDATE orders
                SET status_id = CASE status
                    WHEN 'pending_stock' THEN 1
                    WHEN 'stock_confirmed' THEN 2
                    WHEN 'failed_stock' THEN 3
                    WHEN 'paid' THEN 4
                    WHEN 'failed_payment' THEN 5
                    WHEN 'created' THEN 1
                    WHEN 'rejected' THEN 3
                    WHEN 'pending_payment' THEN 2
                    WHEN 'confirmed' THEN 4
                    WHEN 'failed' THEN 5
                    ELSE 1
                END
            ");

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('status_id')->nullable(false)->change();
            $table->foreign('status_id')->references('id')->on('order_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status')->nullable();
        });

        DB::statement("
            UPDATE orders
            SET status = CASE status_id
                WHEN 1 THEN 'pending_stock'
                WHEN 2 THEN 'stock_confirmed'
                WHEN 3 THEN 'failed_stock'
                WHEN 4 THEN 'paid'
                WHEN 5 THEN 'failed_payment'
                ELSE 'pending_stock'
            END
        ");

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropColumn('status_id');
            $table->string('status')->nullable(false)->change();
        });
    }
};

