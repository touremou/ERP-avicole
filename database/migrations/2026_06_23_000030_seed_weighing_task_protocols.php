<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documente le MODE OPÉRATOIRE de la pesée sous forme de tâches-modèles.
 *
 * 1. Enrichit le template animal « Pesée échantillon » (déjà semé) d'un
 *    déroulement détaillé : comment obtenir le POIDS MOYEN PAR SUJET saisi
 *    dans le pointage (avg_weight) — celui qui alimente le calcul du GMQ.
 *
 * 2. Ajoute un template végétal « Pesée récolte » : comment peser une récolte
 *    (tare des contenants, poids net) pour renseigner net_weight_kg et obtenir
 *    un rendement kg/ha fiable.
 *
 * Le déroulement vit dans `description` : il se propage à chaque tâche générée
 * (TaskSchedulerService recopie tpl->description) et reste donc sous les yeux
 * de l'opérateur sur le terrain.
 */
return new class extends Migration
{
    /** Déroulement de la pesée animale (poids moyen par sujet → GMQ). */
    private const ANIMAL_PROTOCOL = <<<'TXT'
        OBJECTIF : mesurer le POIDS MOYEN PAR SUJET (kg), pas le poids du lot. Cette moyenne, saisie dans le pointage, sert à calculer le GMQ (gain moyen quotidien).

        MATÉRIEL : balance suspendue ou plateau (précision ±1 g), panier/sac de contention, fiche de relevé.

        DÉROULEMENT :
        1. Tôt le matin, AVANT le premier repas (jabot/panse vides = mesure fiable).
        2. Prélever au hasard un échantillon de 3 à 5 % de l'effectif (minimum 30 sujets), répartis dans tout le bâtiment : 4 coins + centre.
        3. Tarer la balance avec le contenant vide.
        4. Peser chaque sujet un par un (ou par petits paquets, puis diviser par le nombre).
        5. Calculer : poids moyen = poids total pesé ÷ nombre de sujets pesés.
        6. Saisir cette moyenne (kg/tête) dans le champ « Poids moyen / sujet » du pointage. Le GMQ se calcule automatiquement par rapport à la pesée précédente.
        7. Comparer au poids-cible de la souche : signaler tout écart supérieur à 10 %.
        TXT;

    /** Déroulement de la pesée d'une récolte végétale (poids net → rendement). */
    private const CROP_PROTOCOL = <<<'TXT'
        OBJECTIF : peser le POIDS NET récolté (kg) pour renseigner la récolte et obtenir un rendement kg/ha fiable, même si la vente se fait en caisses ou sacs.

        MATÉRIEL : bascule ou peson taré (précision ±10 g), cagettes/sacs, fiche de relevé.

        DÉROULEMENT :
        1. Récolter et regrouper la production par contenant (cagette, sac, panier).
        2. Peser un contenant VIDE pour connaître la tare.
        3. Peser chaque contenant plein, puis déduire la tare → poids net du contenant.
        4. Cumuler les poids nets de tous les contenants = poids net total de la récolte.
        5. Mettre de côté et peser séparément les pertes / écarts de tri.
        6. Saisir le poids net total (kg) dans la récolte. Si la vente est en caisses/sacs, indiquer aussi le nombre de contenants comme unité commerciale.
        7. Le rendement kg/ha et l'écart vs rendement attendu se calculent automatiquement.
        TXT;

    public function up(): void
    {
        $animalProtocol = $this->clean(self::ANIMAL_PROTOCOL);
        $cropProtocol   = $this->clean(self::CROP_PROTOCOL);

        // 1. Enrichir le template animal existant (ne pas écraser une éventuelle
        //    description déjà personnalisée par l'utilisateur).
        DB::table('task_templates')
            ->where('name', 'Pesée échantillon')
            ->where('category', 'sante')
            ->whereNull('description')
            ->update([
                'description' => $animalProtocol,
                'updated_at'  => now(),
            ]);

        // 2. Template végétal « Pesée récolte » (idempotent).
        $exists = DB::table('task_templates')
            ->where('name', 'Pesée récolte')
            ->where('category', 'controle')
            ->exists();

        if (! $exists) {
            // SQLite conserve la contrainte CHECK d'origine sur target_type
            // (sans 'plot') : on la suspend le temps de l'insert, comme la
            // migration d'extension végétale. MySQL a déjà l'enum étendu.
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA ignore_check_constraints = ON');
            }

            DB::table('task_templates')->insert([
                'farm_id'          => null,
                'name'             => 'Pesée récolte',
                'category'         => 'controle',
                'description'      => $cropProtocol,
                'icon'             => 'fa-weight-scale',
                'color'            => 'emerald',
                'frequency'        => 'ponctuel',
                'days_of_week'     => null,
                'day_of_month'     => null,
                'scheduled_time'   => '06:30',
                'duration_minutes' => 60,
                'target_type'      => 'plot',
                'per_building'     => false,
                'batch_types'      => null,
                'plot_types'       => null,
                'priority'         => 'haute',
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA ignore_check_constraints = OFF');
            }
        }
    }

    public function down(): void
    {
        DB::table('task_templates')
            ->where('name', 'Pesée récolte')
            ->where('category', 'controle')
            ->delete();

        // Retire le déroulement ajouté au template animal — uniquement s'il
        // correspond encore au nôtre (ne pas écraser une personnalisation).
        DB::table('task_templates')
            ->where('name', 'Pesée échantillon')
            ->where('category', 'sante')
            ->where('description', $this->clean(self::ANIMAL_PROTOCOL))
            ->update(['description' => null, 'updated_at' => now()]);
    }

    /** Normalise l'indentation du heredoc en un texte propre, ligne à ligne. */
    private function clean(string $text): string
    {
        $lines = array_map('trim', explode("\n", $text));

        return trim(implode("\n", $lines));
    }
};
