<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->string('event_type', 120)->nullable()->index();
            $table->string('consumer', 160)->index();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('RECEIVED')->index();
            $table->unsignedInteger('receive_count')->default(1);
            $table->timestamp('first_received_at')->nullable();
            $table->timestamp('last_received_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'consumer'], 'inbox_events_event_consumer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
    }
};
