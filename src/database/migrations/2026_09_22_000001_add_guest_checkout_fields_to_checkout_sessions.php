<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('checkout_sessions', 'customer_type')) {
                $table->string('customer_type', 16)->default('USER')->after('user_id');
            }
            if (! Schema::hasColumn('checkout_sessions', 'guest_id')) {
                $table->string('guest_id', 64)->nullable()->index()->after('customer_type');
            }
            if (! Schema::hasColumn('checkout_sessions', 'guest_phone')) {
                $table->string('guest_phone', 20)->nullable()->after('guest_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('checkout_sessions', 'guest_phone')) {
                $table->dropColumn('guest_phone');
            }
            if (Schema::hasColumn('checkout_sessions', 'guest_id')) {
                $table->dropIndex(['guest_id']);
                $table->dropColumn('guest_id');
            }
            if (Schema::hasColumn('checkout_sessions', 'customer_type')) {
                $table->dropColumn('customer_type');
            }
        });
    }
};

