<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename the `order_statuses` lookup table to `statuses`.
     *
     * MySQL does not automatically repoint foreign keys when the referenced
     * table is renamed, so simply calling Schema::rename() would leave dangling
     * constraints on every child table (orders, checkout_sessions, shop_orders).
     * To keep this production-safe and self-contained we:
     *   1. discover every FK that references the old table name,
     *   2. drop those FKs,
     *   3. rename the table,
     *   4. recreate the FKs against the new table name,
     *   5. seed the newly introduced statuses (ids 16+).
     */
    public function up(): void
    {
        $from = 'order_statuses';
        $to = 'statuses';

        if (!Schema::hasTable($from)) {
            return;
        }

        $fks = $this->foreignKeysReferencing($from);

        foreach ($fks as $fk) {
            DB::statement(
                sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $fk['table'], $fk['constraint'])
            );
        }

        Schema::rename($from, $to);

        foreach ($fks as $fk) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s ON UPDATE %s',
                $fk['table'],
                $fk['constraint'],
                $fk['column'],
                $to,
                $fk['delete_rule'],
                $fk['update_rule']
            ));
        }

        $this->seedNewStatuses($to);
    }

    /**
     * Reverse the rename and remove the statuses introduced above.
     */
    public function down(): void
    {
        $from = 'statuses';
        $to = 'order_statuses';

        if (!Schema::hasTable($from)) {
            return;
        }

        $fks = $this->foreignKeysReferencing($from);

        foreach ($fks as $fk) {
            DB::statement(
                sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $fk['table'], $fk['constraint'])
            );
        }

        // The statuses added in up() are unused by any order, so removing them
        // before the rename cannot violate the (now dropped) foreign keys.
        DB::table($from)->whereIn('id', [16, 17, 18, 20, 21])->delete();

        Schema::rename($from, $to);

        foreach ($fks as $fk) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s ON UPDATE %s',
                $fk['table'],
                $fk['constraint'],
                $fk['column'],
                $to,
                $fk['delete_rule'],
                $fk['update_rule']
            ));
        }
    }

    /**
     * Insert the new status rows. CANCELLED (id 8) already exists, so it is
     * intentionally omitted; ids 16, 17, 18, 20, 21 are assigned per spec.
     */
    private function seedNewStatuses(string $table): void
    {
        $statuses = [
            ['id' => 16, 'name' => 'SCHEDULED'],
            ['id' => 17, 'name' => 'ENDED'],
            ['id' => 18, 'name' => 'DRAFT'],
            ['id' => 20, 'name' => 'PAUSED'],
            ['id' => 21, 'name' => 'ACTIVE'],
        ];

        foreach ($statuses as $status) {
            DB::table($table)->updateOrInsert(
                ['id' => $status['id']],
                ['name' => $status['name']]
            );
        }
    }

    /**
     * Return every foreign key that references the given table.
     *
     * @return array<int, array{table:string, constraint:string, column:string, delete_rule:string, update_rule:string}>
     */
    private function foreignKeysReferencing(string $table): array
    {
        $schema = DB::connection()->getDatabaseName();

        $rows = DB::select(<<<SQL
            SELECT
                kcu.CONSTRAINT_NAME      AS constraint_name,
                kcu.TABLE_NAME           AS table_name,
                kcu.COLUMN_NAME          AS column_name,
                rc.DELETE_RULE           AS delete_rule,
                rc.UPDATE_RULE           AS update_rule
            FROM information_schema.KEY_COLUMN_USAGE kcu
            INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
               AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            WHERE kcu.REFERENCED_TABLE_NAME = ?
              AND kcu.TABLE_SCHEMA = ?
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        SQL, [$table, $schema]);

        return array_map(fn ($row) => [
            'table' => $row->table_name,
            'constraint' => $row->constraint_name,
            'column' => $row->column_name,
            'delete_rule' => $row->delete_rule,
            'update_rule' => $row->update_rule,
        ], $rows);
    }
};
