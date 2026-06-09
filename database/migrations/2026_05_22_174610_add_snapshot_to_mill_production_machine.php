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
        if (!Schema::hasColumn('mill_production_machine', 'snapshot_capacity_per_hour')) {
            Schema::table('mill_production_machine', function (Blueprint $table) {
                $table->decimal('snapshot_capacity_per_hour', 10, 2)->default(0)->after('mill_machine_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mill_production_machine', function (Blueprint $table) {
            //
        });
    }
};
