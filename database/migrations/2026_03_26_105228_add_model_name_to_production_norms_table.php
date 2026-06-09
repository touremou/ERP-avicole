<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_norms', function (Blueprint $table) {
            // On ne crée la colonne QUE si elle n'existe pas déjà
            if (!Schema::hasColumn('production_norms', 'model_name')) {
                $table->string('model_name', 100)->nullable()->after('phase_name');
            }
            
            // On s'assure que batch_type est à la bonne taille pour l'index
            $table->string('batch_type', 50)->change();

            // On ajoute l'index unique (ce qui nous manquait au départ)
            // Note: On utilise un try/catch ou on vérifie si l'index existe déjà
            $table->unique(['batch_type', 'week_number', 'model_name'], 'unique_norm_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_norms', function (Blueprint $table) {
            $table->dropUnique('unique_norm_index');
            $table->dropColumn('model_name');
        });
    }
};