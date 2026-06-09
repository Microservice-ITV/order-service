<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_shop_shipping', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('checkout_id');
            $table->string('shop_id', 64);
            $table->decimal('shipping_fee', 10, 2);
            $table->timestamps();

            $table->unique(['checkout_id', 'shop_id']);
            $table->index('checkout_id');

            $table->foreign('checkout_id')
                ->references('id')
                ->on('checkout_sessions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_shop_shipping');
    }
};
