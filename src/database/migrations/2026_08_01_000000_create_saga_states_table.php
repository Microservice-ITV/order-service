<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saga_states', function (Blueprint $table) {
            $table->id();
            $table->string('saga_id')->unique();
            $table->string('type');
            $table->string('status');
            $table->string('order_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->json('data')->nullable();
            $table->integer('step_order')->default(1);
            $table->integer('total_steps')->default(3);
            $table->integer('current_step')->nullable();
            $table->json('completed_steps')->nullable();
            $table->json('failed_steps')->nullable();
            $table->boolean('compensation_required')->default(false);
            $table->string('compensation_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('saga_id');
            $table->index('order_id');
            $table->index('correlation_id');
            $table->index('status');
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saga_states');
    }
};