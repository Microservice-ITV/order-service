<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->json('delivery_address')->nullable()->after('user_id');
            $table->timestamp('address_updated_at')->nullable()->after('delivery_address');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn(['delivery_address', 'address_updated_at']);
        });
    }
};
