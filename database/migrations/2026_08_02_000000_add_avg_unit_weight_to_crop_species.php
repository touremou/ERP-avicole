<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POIDS MOYEN DE L'UNITÉ RÉCOLTÉE — le pont entre ce qu'on compte et ce qu'on
 * pèse.
 *
 * Le rendement est un poids, et c'est juste : le kilo porte le prix de vente,
 * donc la marge. Mais un producteur d'ananas plante des rejets, vend des fruits
 * et raisonne en fruits. « Rendement attendu : 50 000 kg » ne lui dit rien tant
 * qu'il ne sait pas que cela fait environ 33 000 fruits.
 *
 * Deux colonnes :
 *   avg_unit_weight_kg  poids moyen d'UNE unité récoltée ;
 *   harvest_unit_label  comment on la nomme (fruit, régime, tubercule, épi…).
 *
 * Le libellé compte autant que le nombre : « ≈ 1 200 régimes » se lit, « ≈ 1 200
 * unités » ne se lit pas. Et sans poids moyen, aucune équivalence n'est affichée
 * — on ne devine pas, on se tait.
 *
 * DEUXIÈME USAGE, celui qui rapporte le plus au terrain : quand le technicien
 * compte 500 fruits, l'application peut PROPOSER le poids net correspondant.
 * C'est exactement ce que T1 exige pour valoriser une récolte conservée, et
 * c'était jusqu'ici une pesée à faire ou un champ laissé vide.
 */
return new class extends Migration
{
    /**
     * Poids moyen usuel en Guinée, par NOM d'espèce : [kg par unité, libellé].
     *
     * Volontairement rondes : ce sont des ordres de grandeur pour convertir, pas
     * des calibres commerciaux. Le champ reste modifiable au catalogue.
     *
     * Les céréales et légumineuses sont ABSENTES : personne ne compte des grains
     * de riz. Sans libellé d'unité, aucune équivalence ne s'affiche — et c'est le
     * bon comportement.
     */
    private const BY_NAME = [
        // Fruits — comptés à la pièce, vendus à la pièce.
        'Ananas'          => [1.5, 'fruit'],
        'Papaye'          => [1.5, 'fruit'],
        'Mangue'          => [0.4, 'fruit'],
        'Avocat'          => [0.25, 'fruit'],
        'Avocatier'       => [0.25, 'fruit'],
        'Orange'          => [0.2, 'fruit'],
        'Oranger'         => [0.2, 'fruit'],
        'Citron'          => [0.1, 'fruit'],
        'Citronnier'      => [0.1, 'fruit'],
        'Pastèque'        => [5.0, 'fruit'],
        'Melon'           => [1.2, 'fruit'],
        'Noix de coco'    => [1.5, 'noix'],

        // Bananes : on compte des RÉGIMES, jamais des doigts.
        'Banane'          => [15.0, 'régime'],
        'Banane plantain' => [15.0, 'régime'],

        // Tubercules et racines — comptés au pied ou au tubercule.
        'Manioc'       => [2.0, 'tubercule'],
        'Igname'       => [3.0, 'tubercule'],
        'Patate douce' => [0.3, 'tubercule'],
        'Taro'         => [0.5, 'tubercule'],
        'Pomme de terre' => [0.12, 'tubercule'],

        // Maraîchers — comptés à la pièce sur les marchés.
        'Tomate'    => [0.12, 'fruit'],
        'Aubergine' => [0.25, 'fruit'],
        'Poivron'   => [0.15, 'fruit'],
        'Concombre' => [0.3, 'fruit'],
        'Courge'    => [3.0, 'fruit'],
        'Chou'      => [1.5, 'pied'],
        'Oignon'    => [0.12, 'bulbe'],
        'Gombo'     => [0.015, 'gousse'],

        // Maïs en épis : certains producteurs vendent l'épi frais.
        'Maïs' => [0.25, 'épi'],
    ];

    public function up(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            // 3 décimales : un gombo pèse 15 g, une pastèque 5 kg — la même
            // colonne doit porter les deux sans arrondir le petit à zéro.
            $table->decimal('avg_unit_weight_kg', 8, 3)->nullable()->after('planting_density');
            $table->string('harvest_unit_label', 30)->nullable()->after('avg_unit_weight_kg');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->dropColumn(['avg_unit_weight_kg', 'harvest_unit_label']);
        });
    }

    /**
     * Renseigne les espèces DÉJÀ au catalogue. Sans ce rattrapage, l'équivalence
     * n'existerait que pour les espèces créées après la mise à jour.
     *
     * Aucun repli par type ici, contrairement au matériel de plantation : le
     * poids d'un fruit ne se déduit pas de sa famille botanique. Une espèce hors
     * liste reste sans équivalence, et l'écran n'affiche rien — mieux vaut
     * silence qu'un chiffre inventé.
     */
    private function backfill(): void
    {
        foreach (DB::table('crop_species')->get(['id', 'name']) as $species) {
            $reference = self::BY_NAME[trim($species->name)] ?? null;

            if ($reference === null) {
                foreach (self::BY_NAME as $name => $candidate) {
                    if ($this->flatten($name) === $this->flatten($species->name)) {
                        $reference = $candidate;
                        break;
                    }
                }
            }

            if ($reference === null) {
                continue; // pas de poids connu : on ne devine pas
            }

            DB::table('crop_species')->where('id', $species->id)->update([
                'avg_unit_weight_kg' => $reference[0],
                'harvest_unit_label' => $reference[1],
            ]);
        }
    }

    private function flatten(?string $value): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $value));
    }
};
