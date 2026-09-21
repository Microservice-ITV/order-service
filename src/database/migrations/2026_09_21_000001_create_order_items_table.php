<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Order line-item snapshot for confirmed orders.
     *
     * Populated from checkout_items at the moment an order is confirmed
     * (see OrderService::snapshotCheckoutItems), so every order has its own
     * durable per-product records independent of the checkout session.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('variant_id')->nullable();
            $table->integer('qty');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('line_total', 10, 2)->nullable();
            $table->integer('available_stock')->nullable();
            $table->string('product_slug')->nullable();
            $table->string('product_title')->nullable();
            $table->text('product_description')->nullable();
            $table->string('product_sku')->nullable();
            $table->uuid('category_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->string('image_url')->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->foreign('order_id', 'fk_order_items_orders')
                ->references('order_id')
                ->on('orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};