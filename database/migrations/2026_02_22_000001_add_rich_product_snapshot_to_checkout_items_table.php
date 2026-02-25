<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_items', function (Blueprint $table) {
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
        });
    }

    public function down(): void
    {
        Schema::table('checkout_items', function (Blueprint $table) {
            $table->dropColumn([
                'line_total',
                'available_stock',
                'product_slug',
                'product_title',
                'product_description',
                'product_sku',
                'category_id',
                'shop_id',
                'image_url',
                'currency',
            ]);
        });
    }
};
