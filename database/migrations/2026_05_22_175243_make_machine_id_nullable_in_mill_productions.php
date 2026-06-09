<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ->change() is not supported on SQLite; skip gracefully on unsupported drivers
        try {
            Schema::table('mill_productions', function (Blueprint $table) {
                // Rend la colonne optionnelle (permet de garder l'historique des vieilles données)
                $table->unsignedBigInteger('machine_id')->nullable()->change();

                // Note : Si tu n'as pas de vieilles données à conserver, tu pourrais même la supprimer :
                // $table->dropColumn('machine_id');
            });
        } catch (\Throwable $e) {
            // SQLite does not support column modification — skip silently
        }
    }

    public function down(): void
    {
        try {
            Schema::table('mill_productions', function (Blueprint $table) {
                $table->unsignedBigInteger('machine_id')->nullable(false)->change();
            });
        } catch (\Throwable $e) {
            // SQLite does not support column modification — skip silently
        }
    }
};