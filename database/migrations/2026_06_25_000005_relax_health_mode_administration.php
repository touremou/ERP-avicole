<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `health_checks.mode_administration` : ENUM rigide → VARCHAR souple.
 *
 * L'ENUM ne listait que 4 modes (Eau de boisson, Injection, Nébulisation,
 * Aliment) alors que les formulaires et protocoles en proposent d'autres
 * (Spray, Oculaire…) → « Data truncated » à l'enregistrement. La validation
 * traite déjà ce champ comme une chaîne libre (string|max:100). On aligne donc
 * la base sur cette taxonomie extensible (cohérent avec le reste de l'ERP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->string('mode_administration', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->enum('mode_administration', ['Eau de boisson', 'Injection', 'Nébulisation', 'Aliment'])->change();
        });
    }
};
