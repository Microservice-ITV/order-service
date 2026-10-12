<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['checkout_shop_shipping', 'shop_orders'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'provider_id')) {
                continue;
            }

            if (\DB::table($tableName)->whereNotNull('provider_id')->exists()) {
                throw new RuntimeException("Cannot remove {$tableName}.provider_id while provider references remain.");
            }

            $this->dropForeignKeys($tableName);
            $this->dropIndexes($tableName);
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('provider_id'));
        }
    }

    private function dropForeignKeys(string $tableName): void
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('provider_id', $foreignKey['columns'] ?? [], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['columns']));
            }
        }
    }

    private function dropIndexes(string $tableName): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['primary'] ?? false) || !in_array('provider_id', $index['columns'] ?? [], true)) {
                continue;
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index['name']));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('DeliveryProvider cleanup is irreversible; restore from a verified backup instead.');
    }
};
