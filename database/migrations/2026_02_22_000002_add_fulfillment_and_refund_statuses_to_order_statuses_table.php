<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $statuses = [
            ['id' => 9, 'name' => 'PARTIALLY_FULFILLED'],
            ['id' => 10, 'name' => 'FULFILLED'],
            ['id' => 11, 'name' => 'PARTIALLY_REFUNDED'],
            ['id' => 12, 'name' => 'REFUNDED'],
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
            ->whereIn('id', [9, 10, 11, 12])
            ->delete();
    }
};
