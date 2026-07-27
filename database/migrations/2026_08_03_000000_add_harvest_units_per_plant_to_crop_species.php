<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNITÉS RÉCOLTÉES PAR PIED — ce qui manque pour dériver le rendement du nombre
 * de plants.
 *
 * « Que le rendement se recalcule quand on modifie le nombre de rejets. » Le
 * calcul direct est nombre × poids moyen… mais il suppose UN fruit par pied.
 * C'est vrai d'un ananas, faux d'un manioc (plusieurs tubercules) et absurde
 * d'un manguier (des centaines de fruits). Sans cette colonne, la formule
 * sous-estimerait un manioc de 4 fois et surestimerait rien du tout sur un
 * arbre — silencieusement.
 *
 * BACKFILL VOLONTAIREMENT CONSERVATEUR. On ne renseigne que les cultures où le
 * rapport est UNIVOQUE et vérifiable :
 *   ananas        1 fruit par pied ;
 *   bananier      1 régime par pied et par cycle ;
 *   chou, oignon  1 tête, 1 bulbe ;
 *   pastèque      1 fruit (les suivants sont marginaux en conduite paysanne).
 *
 * Tout le reste reste NULL, et le formulaire garde alors sa base agronomique
 * (surface × rendement de référence). Inventer « 4 tubercules par pied de
 * manioc » produirait un chiffre faux avec l'autorité d'un chiffre calculé.
 *
 * LES DEUX BASES NE S'ACCORDENT PAS, et c'est le point important : 55 000 rejets
 * × 1,5 kg = 82 500 kg, alors que 1,57 ha × 32 t/ha = 50 200 kg. L'écart ne
 * révèle pas un bug mais une INCOHÉRENCE DU RÉFÉRENTIEL — densité et rendement
 * de référence ne décrivent pas la même conduite. Le formulaire l'affiche au lieu
 * de trancher en silence.
 */
return new class extends Migration
{
    /** Cultures où « une unité récoltée par pied » est vérifiable. */
    private const BY_NAME = [
        'Ananas'          => 1,
        'Banane'          => 1,
        'Banane plantain' => 1,
        'Chou'            => 1,
        'Oignon'          => 1,
        'Pastèque'        => 1,
    ];

    public function up(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->unsignedSmallInteger('harvest_units_per_plant')->nullable()->after('harvest_unit_label');
        });

        foreach (DB::table('crop_species')->get(['id', 'name']) as $species) {
            $value = self::BY_NAME[trim($species->name)] ?? null;

            if ($value === null) {
                foreach (self::BY_NAME as $name => $candidate) {
                    if ($this->flatten($name) === $this->flatten($species->name)) {
                        $value = $candidate;
                        break;
                    }
                }
            }

            if ($value !== null) {
                DB::table('crop_species')->where('id', $species->id)
                    ->update(['harvest_units_per_plant' => $value]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->dropColumn('harvest_units_per_plant');
        });
    }

    private function flatten(?string $value): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $value));
    }
};
