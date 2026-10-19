<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'return_deadline_at')) {
                $table->timestamp('return_deadline_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'return_reason')) {
                $table->text('return_reason')->nullable();
            }
            if (!Schema::hasColumn('orders', 'returned_at')) {
                $table->timestamp('returned_at')->nullable();
            }
        });

        DB::table('statuses')->updateOrInsert(
            ['id' => 22],
            ['name' => 'RETURNED'],
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'order_status')
            && !DB::table('orders')->where('order_status', 22)->exists()) {
            DB::table('statuses')->where('id', 22)->delete();
        }

        $columns = array_values(array_filter(
            ['cancellation_reason', 'cancelled_at', 'return_deadline_at', 'return_reason', 'returned_at'],
            static fn (string $column): bool => Schema::hasColumn('orders', $column),
        ));
        if ($columns !== []) {
            Schema::table('orders', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
