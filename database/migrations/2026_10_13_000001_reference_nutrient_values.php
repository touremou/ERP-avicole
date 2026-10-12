<?php

use Database\Seeders\ReferentialSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VALEURS DE RÉFÉRENCE POUR LES MATIÈRES PREMIÈRES LIVRÉES.
 *
 * Décision de l'exploitation : pré-remplir méthionine et phosphore (et toute
 * teneur restée vide) avec les valeurs des tables de composition, MARQUÉES
 * comme estimations, pour que l'équilibre nutritionnel d'une formule soit
 * confronté à sa norme dès maintenant — sans attendre une analyse par
 * ingrédient.
 *
 *   • `nutrition_source` : « reference » (table livrée) ou « saisie »
 *     (laboratoire). L'écran signale les formules calculées sur des
 *     références ;
 *   • on ne remplit que ce qui est VIDE, et seulement pour les matières de la
 *     table (par leur nom) : une valeur saisie n'est jamais remplacée ;
 *   • « reference » signifie « porte AU MOINS une valeur de la table » : dès
 *     qu'une teneur vient d'être remplie ici, la matière est signalée. Une
 *     matière déjà complète, corrigée à la main (teneurs différentes de la
 *     table), est marquée « saisie ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('raw_materials', 'nutrition_source')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->string('nutrition_source', 20)->nullable()->after('phosphorus_rate');
            });
        }

        $colonnes = ReferentialSeeder::COLONNES_DE_LA_TABLE;

        foreach (ReferentialSeeder::MATIERES as $ligne) {
            $matieres = DB::table('raw_materials')->where('name', $ligne[0])->get();

            foreach ($matieres as $matiere) {
                $remplir = [];
                $retouchee = false;

                foreach ($colonnes as $colonne => $position) {
                    $actuelle = $matiere->{$colonne};

                    if ($actuelle === null) {
                        $remplir[$colonne] = $ligne[$position];
                    } elseif (abs((float) $actuelle - (float) $ligne[$position]) > 0.0001) {
                        $retouchee = true;
                    }
                }

                $remplir['nutrition_source'] = count($remplir) > 0 || ! $retouchee ? 'reference' : 'saisie';

                DB::table('raw_materials')->where('id', $matiere->id)->update($remplir);
            }
        }

        // Toute autre matière portant des teneurs : saisie par la ferme.
        DB::table('raw_materials')->whereNull('nutrition_source')
            ->where(fn ($q) => $q->whereNotNull('energy_kcal')->orWhereNotNull('protein_rate'))
            ->update(['nutrition_source' => 'saisie']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('raw_materials', 'nutrition_source')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->dropColumn('nutrition_source');
            });
        }
    }
};
