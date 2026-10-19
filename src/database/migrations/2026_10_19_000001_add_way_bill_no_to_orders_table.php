<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'way_bill_no')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('way_bill_no', 128)->nullable()->after('tracking_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'way_bill_no')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('way_bill_no');
            });
        }
    }
};
