<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MATÉRIEL DE PLANTATION — ce qu'on met en terre, et dans quelle unité.
 *
 * Le formulaire de cycle demandait « Quantité semence » en « kg » pour TOUTE
 * culture. Or on ne plante pas un ananas en kilos de semence : on plante des
 * REJETS, qui se comptent à l'unité. Idem pour le manioc (boutures), la banane
 * (rejets), la tomate (plants de pépinière). Le technicien devait donc soit
 * convertir mentalement, soit laisser le champ vide — et le coût de plantation
 * devenait incomparable d'un cycle à l'autre.
 *
 * Trois colonnes, toutes au niveau de l'ESPÈCE (une variété d'ananas se plante
 * comme un ananas) :
 *
 *   planting_material   ce qu'on met en terre (semence, rejet, bouture…) ;
 *   planting_unit       comment on le compte (kg, unité) ;
 *   planting_density     combien par hectare — sert à PROPOSER la quantité.
 *
 * La densité est une aide à la saisie, jamais une contrainte : l'écartement
 * réel varie avec le sol, la variété et la mécanisation. Le champ reste
 * modifiable, et une culture absente du catalogue garde le comportement
 * historique (semence / kg).
 */
return new class extends Migration
{
    /**
     * Référence par NOM d'espèce : [matériel, unité, densité/ha].
     *
     * Valeurs usuelles en Guinée (IRAG/FAO). Volontairement rondes : ce sont des
     * ordres de grandeur pour pré-remplir, pas des prescriptions.
     */
    private const BY_NAME = [
        // Multiplication VÉGÉTATIVE — se compte à l'unité.
        'Ananas'          => ['rejet', 'unité', 35000],
        'Manioc'          => ['bouture', 'unité', 10000],
        'Banane'          => ['rejet', 'unité', 1600],
        'Banane plantain' => ['rejet', 'unité', 1600],
        'Patate douce'    => ['bouture', 'unité', 33000],
        'Igname'          => ['fragment de tubercule', 'kg', 2000],
        'Pomme de terre'  => ['tubercule', 'kg', 2000],
        'Canne à sucre'   => ['bouture', 'unité', 30000],
        'Gingembre'       => ['rhizome', 'kg', 1500],
        'Taro'            => ['rejet', 'unité', 12000],

        // PLANTS de pépinière — repiqués, donc comptés à l'unité.
        'Tomate'     => ['plant', 'unité', 25000],
        'Piment'     => ['plant', 'unité', 30000],
        'Aubergine'  => ['plant', 'unité', 20000],
        'Oignon'     => ['plant', 'unité', 400000],
        'Chou'       => ['plant', 'unité', 30000],
        'Poivron'    => ['plant', 'unité', 25000],
        'Papaye'     => ['plant', 'unité', 1600],
        'Mangue'     => ['plant', 'unité', 100],
        'Anacardier' => ['plant', 'unité', 150],
        'Café'       => ['plant', 'unité', 1600],
        'Cacao'      => ['plant', 'unité', 1100],
        'Palmier à huile' => ['plant', 'unité', 143],
        'Hévéa'      => ['plant', 'unité', 500],
        'Citronnier' => ['plant', 'unité', 400],
        'Oranger'    => ['plant', 'unité', 400],
        'Avocatier'  => ['plant', 'unité', 200],

        // SEMENCES — se pèsent.
        'Riz'      => ['semence', 'kg', 60],
        'Maïs'     => ['semence', 'kg', 25],
        'Fonio'    => ['semence', 'kg', 40],
        'Sorgho'   => ['semence', 'kg', 12],
        'Mil'      => ['semence', 'kg', 10],
        'Arachide' => ['semence', 'kg', 100],
        'Niébé'    => ['semence', 'kg', 25],
        'Haricot'  => ['semence', 'kg', 60],
        'Soja'     => ['semence', 'kg', 70],
        'Sésame'   => ['semence', 'kg', 5],
        'Gombo'    => ['semence', 'kg', 8],
        'Concombre' => ['semence', 'kg', 3],
        'Pastèque' => ['semence', 'kg', 3],
        'Melon'    => ['semence', 'kg', 2],
        'Courge'   => ['semence', 'kg', 4],
        'Coton'    => ['semence', 'kg', 25],
    ];

    /**
     * Repli par TYPE, pour les espèces absentes de la liste ci-dessus. Sans lui,
     * une espèce ajoutée par l'utilisateur retomberait sur « semence / kg »
     * même s'il s'agit d'un fruitier — donc sur le défaut qu'on corrige.
     */
    private const BY_TYPE = [
        'fruitier'    => ['plant', 'unité', null],
        'tubercule'   => ['bouture', 'unité', null],
        'maraicher'   => ['plant', 'unité', null],
        'cereale'     => ['semence', 'kg', null],
        'legumineuse' => ['semence', 'kg', null],
        'oleagineux'  => ['semence', 'kg', null],
        'legume'      => ['semence', 'kg', null],
        'epice'       => ['plant', 'unité', null],
    ];

    public function up(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->string('planting_material', 40)->nullable()->after('avg_yield_tha');
            $table->string('planting_unit', 20)->nullable()->after('planting_material');
            // Densité : jusqu'à 400 000 plants/ha (oignon) — un entier suffit.
            $table->unsignedInteger('planting_density')->nullable()->after('planting_unit');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->dropColumn(['planting_material', 'planting_unit', 'planting_density']);
        });
    }

    /**
     * Renseigne les espèces DÉJÀ au catalogue. Sans ce rattrapage, la
     * fonctionnalité n'existerait que pour les espèces créées après la mise à
     * jour — et le technicien continuerait à voir « semence en kg » sur son
     * ananas.
     */
    private function backfill(): void
    {
        foreach (DB::table('crop_species')->get(['id', 'name', 'type']) as $species) {
            $reference = self::BY_NAME[trim($species->name)] ?? null;

            if ($reference === null) {
                // Comparaison désaccentuée et insensible à la casse : « MAIS »,
                // « Maïs » et « mais » désignent la même culture.
                foreach (self::BY_NAME as $name => $candidate) {
                    if ($this->flatten($name) === $this->flatten($species->name)) {
                        $reference = $candidate;
                        break;
                    }
                }
            }

            $reference = $reference ?? self::BY_TYPE[$species->type] ?? ['semence', 'kg', null];

            DB::table('crop_species')->where('id', $species->id)->update([
                'planting_material' => $reference[0],
                'planting_unit'     => $reference[1],
                'planting_density'  => $reference[2],
            ]);
        }
    }

    private function flatten(?string $value): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $value));
    }
};
