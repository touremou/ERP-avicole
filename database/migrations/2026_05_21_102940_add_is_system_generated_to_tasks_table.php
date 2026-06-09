<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('batch_tasks', 'is_system_generated')) {
            Schema::table('batch_tasks', function (Blueprint $table) {
                $table->boolean('is_system_generated')->default(false)->after('is_completed');
            });
        }
    }

    public function down(): void
    {
        Schema::table('batch_tasks', function (Blueprint $table) {
            $table->dropColumn('is_system_generated');
        });
    }
};