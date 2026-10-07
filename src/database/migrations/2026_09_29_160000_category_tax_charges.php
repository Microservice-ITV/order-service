<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_charge_settings', function ($table) {
            $table->string('id', 32)->primary();
            $table->boolean('is_enabled')->default(true);
            $table->string('currency', 8)->default('MMK');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('category_tax_charges', function ($table) {
            $table->string('category_id', 64)->primary();
            $table->uuid('tax_id');
            $table->string('tax_name', 120);
            $table->decimal('rate', 7, 4);
            $table->boolean('is_active')->default(true);
            $table->string('category_name', 255)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE checkout_items MODIFY tax_category_code VARCHAR(32) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('category_tax_charges');
        Schema::dropIfExists('tax_charge_settings');
        DB::statement("ALTER TABLE checkout_items MODIFY tax_category_code VARCHAR(32) NOT NULL DEFAULT 'STANDARD'");
    }
};
