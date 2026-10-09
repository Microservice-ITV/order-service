<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cod_collection_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('selection_hash', 64)->unique();
            $table->string('shop_id', 64);
            $table->string('delivery_company_id', 64);
            $table->timestamp('effective_at');
            $table->string('status', 16)->default('OPEN');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cod_collection_batch_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->uuid('order_id');
            $table->string('shop_id', 64);
            $table->timestamps();

            $table->unique(['order_id', 'shop_id']);
            $table->index('batch_id');
            $table->foreign('batch_id')->references('id')->on('cod_collection_batches')->cascadeOnDelete();
            $table->foreign('order_id')->references('order_id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Collect COD batch migrations are forward-only because batch membership is accounting evidence.');
    }
};