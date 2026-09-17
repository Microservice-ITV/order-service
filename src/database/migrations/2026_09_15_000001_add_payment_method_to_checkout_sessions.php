<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('checkout_sessions', 'payment_method')) {
                $table->string('payment_method', 32)->nullable()->default('card')->after('payment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};