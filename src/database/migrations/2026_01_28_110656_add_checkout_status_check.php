<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE checkout_sessions
            ADD CONSTRAINT chk_checkout_status
            CHECK (status_id IN (1,2,3,4,5,6,7))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE checkout_sessions
            DROP CONSTRAINT chk_checkout_status
        ");
    }
};
