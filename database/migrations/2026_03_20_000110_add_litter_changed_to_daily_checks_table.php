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
    Schema::table('daily_checks', function (Blueprint $table) {
        // On l'ajoute en tant que boolean (vrai/faux)
        if (!Schema::hasColumn('daily_checks', 'litter_changed')) {
            $table->boolean('litter_changed')->default(false)->after('qty_sorted_out');
        }
    });
}

public function down(): void
{
    Schema::table('daily_checks', function (Blueprint $table) {
        $table->dropColumn('litter_changed');
    });
}
};
