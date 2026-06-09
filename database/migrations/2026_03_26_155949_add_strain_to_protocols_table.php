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
    Schema::table('protocols', function (Blueprint $table) {
        // On vérifie si la colonne n'existe pas déjà (bonne pratique)
        if (!Schema::hasColumn('protocols', 'strain')) {
            $table->string('strain', 100)->nullable()->after('type');
        }
    });
}

public function down(): void
{
    Schema::table('protocols', function (Blueprint $table) {
        $table->dropColumn('strain');
    });
}
};
