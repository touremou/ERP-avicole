<?php

use Database\Seeders\ReferentialSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « NON ANALYSÉ » PARTOUT, MÊME SUR UNE FORMULE ENTIÈREMENT RENSEIGNÉE.
 *
 * Signalé par l'exploitation, capture à l'appui : la jauge « Équilibre
 * nutritionnel vs norme » d'une formule affichait « Non analysé » sur ses six
 * lignes. Une teneur à 0 valait « non analysé » : impossible, donc, de dire
 * qu'un ingrédient n'apporte RIEN. Or le sel, les coquilles, le phosphate, le
 * CMV n'apportent ni énergie ni protéine ni lysine — leur vrai 0 rendait
 * « non analysée » toute formule qui en contient, c'est-à-dire toutes les
 * formules d'aliment volaille.
 *
 * Désormais : VIDE (null) = non analysé ; 0 = analysé, n'en contient pas.
 *
 * ─── LES DONNÉES EXISTANTES ───
 *
 * Un 0 en base ne disait pas lequel des deux il était. On le lit comme il
 * s'affichait jusqu'ici — « non analysé » — SAUF pour les matières premières
 * livrées par l'application, dont la table (ReferentialSeeder::MATIERES) dit
 * quels zéros sont réels. Rien ne change donc à l'écran, sinon que ces zéros-là
 * cessent de bloquer l'analyse. Méthionine et phosphore, ajoutés sans valeur
 * (« 0 = non analysé », 2026_08_05), deviennent vides.
 */
return new class extends Migration
{
    private const COLONNES = [
        'energy_kcal'     => [8, 2],
        'protein_rate'    => [5, 2],
        'lysine_rate'     => [5, 2],
        'methionine_rate' => [5, 3],
        'calcium_rate'    => [5, 2],
        'phosphorus_rate' => [5, 3],
    ];

    /** Position de chaque teneur dans une ligne de ReferentialSeeder::MATIERES. */
    private const DANS_LA_TABLE = ['energy_kcal' => 2, 'protein_rate' => 3, 'lysine_rate' => 4, 'calcium_rate' => 5];

    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            foreach (self::COLONNES as $colonne => [$total, $decimales]) {
                $table->decimal($colonne, $total, $decimales)->nullable()->default(null)->change();
            }
        });

        // Les vrais zéros de la table livrée, par matière : on les garde.
        $vraisZeros = [];
        foreach (ReferentialSeeder::MATIERES as $ligne) {
            foreach (self::DANS_LA_TABLE as $colonne => $position) {
                if ((float) $ligne[$position] === 0.0) {
                    $vraisZeros[$colonne][] = $ligne[0];
                }
            }
        }

        foreach (array_keys(self::COLONNES) as $colonne) {
            DB::table('raw_materials')
                ->where($colonne, 0)
                ->when(! empty($vraisZeros[$colonne]), fn ($q) => $q->whereNotIn('name', $vraisZeros[$colonne]))
                ->update([$colonne => null]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::COLONNES) as $colonne) {
            DB::table('raw_materials')->whereNull($colonne)->update([$colonne => 0]);
        }

        Schema::table('raw_materials', function (Blueprint $table) {
            foreach (self::COLONNES as $colonne => [$total, $decimales]) {
                $table->decimal($colonne, $total, $decimales)->default(0)->nullable(false)->change();
            }
        });
    }
};
