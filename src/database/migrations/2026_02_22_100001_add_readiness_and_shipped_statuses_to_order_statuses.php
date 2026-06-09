<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $statuses = [
            ['id' => 13, 'name' => 'READY_FOR_FULFILLMENT'],
            ['id' => 14, 'name' => 'SHIPPED'],
        ];

        foreach ($statuses as $status) {
            DB::table('order_statuses')->updateOrInsert(
                ['id' => $status['id']],
                ['name' => $status['name']]
            );
        }
    }

    public function down(): void
    {
        DB::table('order_statuses')
            ->whereIn('id', [13, 14])
            ->delete();
    }
};
