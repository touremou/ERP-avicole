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
    if (!Schema::hasTable('permission_role')) {
        return;
    }

    if (!Schema::hasColumn('permission_role', 'created_at')) {
        Schema::table('permission_role', function (Blueprint $table) {
            $table->timestamps(); // Ajoute created_at et updated_at
        });
    }
}

public function down(): void
{
    if (!Schema::hasTable('permission_role')) {
        return;
    }

    Schema::table('permission_role', function (Blueprint $table) {
        $table->dropTimestamps();
    });
}
};
