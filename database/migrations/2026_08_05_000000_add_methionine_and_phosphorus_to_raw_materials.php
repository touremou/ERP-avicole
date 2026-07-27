<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MÉTHIONINE ET PHOSPHORE AU CATALOGUE DES MATIÈRES PREMIÈRES.
 *
 * Le référentiel des normes (`food_norms`) fixe SEPT cibles depuis l'origine :
 * énergie, protéine, lysine, méthionine, calcium, phosphore et prix. Le
 * catalogue des ingrédients n'en portait que quatre. Deux cibles étaient donc
 * inconfrontables : la fiche d'une formule affichait une méthionine à 0,50 %
 * « attendue » sans qu'aucune donnée ne puisse jamais lui répondre.
 *
 * Ce sont pourtant les deux paramètres qui commandent le plus les corrections
 * d'une formule en Guinée : la méthionine parce que le tourteau local en
 * manque, le phosphore parce qu'il conditionne le dosage du phosphate.
 *
 * PAS DE VALEURS INVENTÉES. Les teneurs restent à 0 — « non analysé » — jusqu'à
 * saisie au laboratoire. Formula::nutrientCoverage() refuse alors de comparer ce
 * nutriment plutôt que d'afficher une carence qui n'existe que dans la saisie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->decimal('methionine_rate', 5, 3)->default(0)->after('lysine_rate')
                ->comment('Méthionine % — 0 = non analysé');
            $table->decimal('phosphorus_rate', 5, 3)->default(0)->after('calcium_rate')
                ->comment('Phosphore % — 0 = non analysé');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn(['methionine_rate', 'phosphorus_rate']);
        });
    }
};
