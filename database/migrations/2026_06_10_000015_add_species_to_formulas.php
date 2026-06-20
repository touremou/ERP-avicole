<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rend les formules d'aliment multiespèces.
 *
 * Jusqu'ici une formule ne portait que `target_type` (libellé volaille) et un
 * `poultry_type` figé (Chair|Ponte). On rattache l'espèce et le type de
 * production : on peut formuler un aliment chèvre laitière, tilapia
 * grossissement, etc. `target_type` reste la clé de référentiel nutritionnel
 * (FoodNorm) pour rétrocompatibilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->foreignId('species_id')->nullable()->after('target_type')->constrained()->nullOnDelete();
            $table->foreignId('production_type_id')->nullable()->after('species_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_type_id');
            $table->dropConstrainedForeignId('species_id');
        });
    }
};
