<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('order_id')->primary();
            $table->uuid('checkout_id')->unique();
            $table->uuid('reservation_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->unsignedTinyInteger('status_id')->default(7);
            $table->timestamps();

            $table->foreign('checkout_id', 'fk_orders_checkout')
                ->references('id')
                ->on('checkout_sessions')
                ->cascadeOnDelete();

            $table->foreign('status_id', 'fk_orders_status')
                ->references('id')
                ->on('order_statuses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

