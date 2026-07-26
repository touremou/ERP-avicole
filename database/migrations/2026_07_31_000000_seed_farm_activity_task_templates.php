<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CATALOGUE D'ACTIVITÉS DE FERME — élargir les templates de tâches au-delà de
 * la volaille.
 *
 * Le catalogue livré couvrait l'aviculture (alimentation, collecte d'œufs,
 * litière, mortalité) et sept gestes de cultures. Tout le reste de ce qui se
 * fait réellement sur un site — traite, curage, pisciculture, couvoir,
 * biosécurité, moulin, magasin, ressources, caisse — n'existait dans aucun
 * modèle : donc dans aucun calendrier, donc dans aucun taux de complétion. Un
 * technicien pouvait afficher 100 % sans qu'aucune de ces activités ne soit
 * jamais tracée.
 *
 * DEUX PARTIS PRIS, tous deux importants.
 *
 * 1. LES NOUVEAUX MODÈLES ARRIVENT INACTIFS. Les activer d'office
 *    ajouterait une trentaine de tâches quotidiennes à toutes les fermes, y
 *    compris pour des ateliers qu'elles n'ont pas — et surtout cela ferait
 *    exploser le DÉNOMINATEUR du taux de complétion (S2), rendant l'indicateur
 *    inatteignable et donc inutile. On livre une bibliothèque : le responsable
 *    active ce qui correspond à son site (Planning › Modèles de tâches).
 *
 * 2. CIBLAGE AUTO-SILENCIEUX quand c'est possible. Un modèle « Traite du
 *    matin » porte batch_types=['laitiere'] : même activé, il ne génère rien
 *    tant qu'aucun lot laitier n'existe. Idem pour la pisciculture et le
 *    couvoir. Les modèles à l'échelle de la ferme (caisse, moulin, magasin)
 *    n'ont pas ce filtre : c'est pourquoi l'activation manuelle compte.
 *
 * TRAÇABILITÉ : chaque modèle qui produit une MESURE porte proof_type=valeur
 * avec son unité, et chaque geste dont la preuve est visuelle porte
 * proof_type=photo. C'est la différence entre « j'ai coché » et « voici le
 * relevé » — la demande explicite était « pertinent ET traçable ».
 *
 * L'abattoir est délibérément ABSENT : ses registres HACCP (températures, CCP,
 * nettoyage, sous-produits) ont déjà leurs tournées dédiées. Doubler avec des
 * tâches créerait deux registres pour le même geste, et l'un des deux serait
 * faux.
 */
return new class extends Migration
{
    public function up(): void
    {
        // target_type = 'plot' : l'ENUM n'a été étendu que sous MySQL ; sous
        // SQLite la contrainte CHECK d'origine refuserait la valeur. Même
        // contournement que la migration de production végétale.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }

        $this->seed();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
    }

    public function down(): void
    {
        DB::table('task_templates')
            ->whereNull('farm_id')
            ->whereIn('name', array_column($this->templates(), 'name'))
            ->delete();
    }

    private function seed(): void
    {
        $now = now();
        $hasMonths = Schema::hasColumn('task_templates', 'months');
        $hasPlotTypes = Schema::hasColumn('task_templates', 'plot_types');
        $hasProof = Schema::hasColumn('task_templates', 'proof_type');
        $hasPool = Schema::hasColumn('task_templates', 'is_pool');

        foreach ($this->templates() as $template) {
            // Idempotent par NOM : rejouer la migration ne duplique rien, et un
            // modèle que le responsable a supprimé ne revient pas par surprise
            // à la migration suivante… mais reviendrait ici. On accepte : c'est
            // le prix d'un catalogue livré, et il est inactif.
            $exists = DB::table('task_templates')
                ->whereNull('farm_id')
                ->where('name', $template['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $row = array_merge([
                'farm_id'          => null,
                'description'      => null,
                'color'            => 'slate',
                'days_of_week'     => null,
                'day_of_month'     => null,
                'scheduled_time'   => null,
                'duration_minutes' => 30,
                'target_type'      => 'farm',
                'per_building'     => false,
                'batch_types'      => null,
                'priority'         => 'normale',
                // INACTIF : bibliothèque à activer, cf. en-tête.
                'is_active'        => false,
                'created_at'       => $now,
                'updated_at'       => $now,
            ], $template);

            if ($hasMonths && ! array_key_exists('months', $row)) {
                $row['months'] = null;
            }
            if ($hasPlotTypes && ! array_key_exists('plot_types', $row)) {
                $row['plot_types'] = null;
            }
            if ($hasPool && ! array_key_exists('is_pool', $row)) {
                $row['is_pool'] = false;
            }
            if ($hasProof) {
                $row['proof_type'] = $row['proof_type'] ?? 'aucune';
                $row['proof_label'] = $row['proof_label'] ?? null;
                $row['proof_unit'] = $row['proof_unit'] ?? null;
            } else {
                unset($row['proof_type'], $row['proof_label'], $row['proof_unit']);
            }

            if (! $hasMonths) {
                unset($row['months']);
            }
            if (! $hasPlotTypes) {
                unset($row['plot_types']);
            }
            if (! $hasPool) {
                unset($row['is_pool']);
            }

            DB::table('task_templates')->insert($row);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        $weekdays = json_encode([1, 2, 3, 4, 5, 6]);
        $everyday = json_encode([1, 2, 3, 4, 5, 6, 7]);

        return [
            // ─────────── ÉLEVAGE LAITIER (batch_types: laitiere) ───────────
            // Auto-silencieux : rien ne se génère sans lot laitier actif.
            ['name' => 'Traite du matin', 'category' => 'collecte', 'icon' => 'fa-cow', 'color' => 'sky',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '05:30',
             'duration_minutes' => 90, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['laitiere']), 'priority' => 'critique',
             'proof_type' => 'valeur', 'proof_label' => 'Lait collecté', 'proof_unit' => 'L',
             'description' => "Traire, filtrer et refroidir. Noter le volume : c'est lui qui alimente la production laitière."],

            ['name' => 'Traite du soir', 'category' => 'collecte', 'icon' => 'fa-cow', 'color' => 'indigo',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '17:30',
             'duration_minutes' => 90, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['laitiere']), 'priority' => 'critique',
             'proof_type' => 'valeur', 'proof_label' => 'Lait collecté', 'proof_unit' => 'L'],

            ['name' => 'Curage et raclage de l’étable', 'category' => 'nettoyage', 'icon' => 'fa-broom', 'color' => 'purple',
             'frequency' => 'quotidien', 'days_of_week' => $weekdays, 'scheduled_time' => '08:00',
             'duration_minutes' => 60, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['laitiere', 'engraissement']), 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Étable après curage'],

            ['name' => 'Contrôle des mamelles et propreté des trayons', 'category' => 'sante', 'icon' => 'fa-stethoscope', 'color' => 'rose',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([1]), 'scheduled_time' => '06:00',
             'duration_minutes' => 45, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['laitiere']), 'priority' => 'haute',
             'description' => "Dépistage de mammite : rougeur, gonflement, lait anormal. Toute anomalie se déclare en incident sanitaire."],

            // ─────────── ENGRAISSEMENT ───────────
            ['name' => 'Pesée de contrôle du lot', 'category' => 'sante', 'icon' => 'fa-weight-scale', 'color' => 'amber',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([3]), 'scheduled_time' => '09:00',
             'duration_minutes' => 60, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['engraissement']), 'priority' => 'normale',
             'proof_type' => 'valeur', 'proof_label' => 'Poids moyen', 'proof_unit' => 'kg',
             'description' => "Peser un échantillon représentatif : c'est le poids moyen qui pilote l'indice de consommation."],

            ['name' => 'Contrôle abreuvement et fourrage', 'category' => 'controle', 'icon' => 'fa-wheat-awn', 'color' => 'green',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '07:00',
             'duration_minutes' => 20, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['laitiere', 'engraissement']), 'priority' => 'haute'],

            // ─────────── PISCICULTURE (alevinage, grossissement) ───────────
            ['name' => 'Relevé oxygène dissous', 'category' => 'controle', 'icon' => 'fa-water', 'color' => 'cyan',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '06:30',
             'duration_minutes' => 15, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['alevinage', 'grossissement']), 'priority' => 'critique',
             'proof_type' => 'valeur', 'proof_label' => 'Oxygène dissous', 'proof_unit' => 'mg/L',
             'description' => "Sous 3 mg/L, la mortalité s'emballe en quelques heures. Relever avant le nourrissage."],

            ['name' => 'Relevé pH et température du bassin', 'category' => 'controle', 'icon' => 'fa-temperature-half', 'color' => 'sky',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '07:00',
             'duration_minutes' => 15, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['alevinage', 'grossissement']), 'priority' => 'haute',
             'proof_type' => 'valeur', 'proof_label' => 'pH', 'proof_unit' => 'pH'],

            ['name' => 'Nourrissage des poissons', 'category' => 'alimentation', 'icon' => 'fa-fish', 'color' => 'blue',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '08:00',
             'duration_minutes' => 30, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['alevinage', 'grossissement']), 'priority' => 'haute',
             'proof_type' => 'valeur', 'proof_label' => 'Aliment distribué', 'proof_unit' => 'kg'],

            ['name' => 'Contrôle des filets et de la digue', 'category' => 'maintenance', 'icon' => 'fa-shield', 'color' => 'slate',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([6]), 'scheduled_time' => '10:00',
             'duration_minutes' => 45, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['alevinage', 'grossissement']), 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Digue et filets'],

            // ─────────── COUVOIR (poussiniere, reproducteur) ───────────
            ['name' => 'Retournement des œufs en incubation', 'category' => 'controle', 'icon' => 'fa-rotate', 'color' => 'orange',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '09:00',
             'duration_minutes' => 20, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['poussiniere', 'reproducteur']), 'priority' => 'critique'],

            ['name' => 'Contrôle température et hygrométrie incubateur', 'category' => 'controle', 'icon' => 'fa-droplet', 'color' => 'cyan',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '07:30',
             'duration_minutes' => 15, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['poussiniere', 'reproducteur']), 'priority' => 'critique',
             'proof_type' => 'valeur', 'proof_label' => 'Température', 'proof_unit' => '°C'],

            ['name' => 'Désinfection de l’incubateur', 'category' => 'nettoyage', 'icon' => 'fa-spray-can', 'color' => 'blue',
             'frequency' => 'mensuel', 'day_of_month' => 1, 'scheduled_time' => '14:00',
             'duration_minutes' => 120, 'target_type' => 'building', 'per_building' => true,
             'batch_types' => json_encode(['poussiniere', 'reproducteur']), 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Incubateur désinfecté'],

            // ─────────── BIOSÉCURITÉ (ferme entière) ───────────
            ['name' => 'Recharge du pédiluve', 'category' => 'nettoyage', 'icon' => 'fa-shoe-prints', 'color' => 'teal',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '06:00',
             'duration_minutes' => 10, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Pédiluve rechargé',
             'description' => "Un pédiluve sec ou sale ne protège de rien : c'est la première barrière sanitaire du site."],

            ['name' => 'Contrôle de la clôture périmétrique', 'category' => 'maintenance', 'icon' => 'fa-border-all', 'color' => 'slate',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([6]), 'scheduled_time' => '16:00',
             'duration_minutes' => 45, 'priority' => 'normale',
             'proof_type' => 'photo', 'proof_label' => 'Points de clôture vérifiés'],

            ['name' => 'Registre des visiteurs à jour', 'category' => 'controle', 'icon' => 'fa-clipboard-user', 'color' => 'indigo',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([5]), 'scheduled_time' => '17:00',
             'duration_minutes' => 15, 'priority' => 'basse'],

            // ─────────── PROVENDERIE ───────────
            ['name' => 'Nettoyage du moulin et du broyeur', 'category' => 'nettoyage', 'icon' => 'fa-industry', 'color' => 'purple',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([6]), 'scheduled_time' => '15:00',
             'duration_minutes' => 90, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Moulin nettoyé',
             'description' => "Résidus d'aliment = moisissures et contamination croisée entre formules."],

            ['name' => 'Contrôle et étalonnage de la bascule', 'category' => 'maintenance', 'icon' => 'fa-scale-balanced', 'color' => 'amber',
             'frequency' => 'mensuel', 'day_of_month' => 1, 'scheduled_time' => '09:00',
             'duration_minutes' => 45, 'priority' => 'haute',
             'proof_type' => 'valeur', 'proof_label' => 'Écart mesuré', 'proof_unit' => 'kg',
             'description' => "Une bascule qui dérive fausse tout : rations, stocks, indice de consommation et coût de revient."],

            ['name' => 'Inventaire des matières premières', 'category' => 'controle', 'icon' => 'fa-warehouse', 'color' => 'slate',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([5]), 'scheduled_time' => '16:00',
             'duration_minutes' => 60, 'priority' => 'normale'],

            // ─────────── LOGISTIQUE / MAGASIN ───────────
            ['name' => 'Inventaire tournant d’un rayon', 'category' => 'controle', 'icon' => 'fa-boxes-stacked', 'color' => 'blue',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([2]), 'scheduled_time' => '15:00',
             'duration_minutes' => 60, 'priority' => 'normale',
             'description' => "Compter une famille d'articles par semaine plutôt que tout une fois l'an : les écarts se voient tant qu'ils sont explicables."],

            ['name' => 'Contrôle des dates de péremption', 'category' => 'controle', 'icon' => 'fa-calendar-xmark', 'color' => 'rose',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([1]), 'scheduled_time' => '09:00',
             'duration_minutes' => 30, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Articles proches de péremption'],

            // ─────────── RESSOURCES (eau & énergie) ───────────
            ['name' => 'Niveau de carburant du groupe', 'category' => 'releve_energie', 'icon' => 'fa-gas-pump', 'color' => 'yellow',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '06:00',
             'duration_minutes' => 10, 'priority' => 'haute',
             'proof_type' => 'valeur', 'proof_label' => 'Carburant restant', 'proof_unit' => 'L'],

            ['name' => 'Niveau des citernes d’eau', 'category' => 'releve_eau', 'icon' => 'fa-water', 'color' => 'cyan',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '06:15',
             'duration_minutes' => 10, 'priority' => 'haute',
             'proof_type' => 'valeur', 'proof_label' => 'Niveau', 'proof_unit' => '%'],

            ['name' => 'Vidange et entretien du groupe électrogène', 'category' => 'maintenance', 'icon' => 'fa-oil-can', 'color' => 'orange',
             'frequency' => 'mensuel', 'day_of_month' => 5, 'scheduled_time' => '10:00',
             'duration_minutes' => 120, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Compteur horaire après vidange'],

            // ─────────── CULTURES (par parcelle) ───────────
            ['name' => 'Buttage et binage', 'category' => 'sarclage', 'icon' => 'fa-trowel', 'color' => 'amber',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([4]), 'scheduled_time' => '07:00',
             'duration_minutes' => 150, 'target_type' => 'plot', 'per_building' => false,
             'plot_types' => json_encode(['tubercule', 'maraicher', 'legume']), 'priority' => 'normale'],

            ['name' => 'Tuteurage et palissage', 'category' => 'controle', 'icon' => 'fa-seedling', 'color' => 'green',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([2]), 'scheduled_time' => '07:30',
             'duration_minutes' => 120, 'target_type' => 'plot', 'per_building' => false,
             'plot_types' => json_encode(['maraicher', 'fruitier', 'legume']), 'priority' => 'normale'],

            ['name' => 'Contrôle du réseau d’irrigation', 'category' => 'maintenance', 'icon' => 'fa-faucet-drip', 'color' => 'cyan',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([1]), 'scheduled_time' => '06:30',
             'duration_minutes' => 60, 'target_type' => 'plot', 'per_building' => false, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Fuites ou casses constatées',
             'description' => "Une fuite non vue se paie deux fois : en eau perdue et en culture non arrosée."],

            ['name' => 'Piégeage et suivi des ravageurs', 'category' => 'controle', 'icon' => 'fa-bug', 'color' => 'rose',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([3]), 'scheduled_time' => '07:00',
             'duration_minutes' => 45, 'target_type' => 'plot', 'per_building' => false, 'priority' => 'haute',
             'proof_type' => 'valeur', 'proof_label' => 'Captures relevées', 'proof_unit' => 'unité',
             'description' => "Compter, pas seulement regarder : c'est la courbe des captures qui décide de traiter ou d'attendre."],

            ['name' => 'Préparation du sol et labour', 'category' => 'sarclage', 'icon' => 'fa-tractor', 'color' => 'amber',
             'frequency' => 'ponctuel', 'scheduled_time' => '06:00',
             'duration_minutes' => 300, 'target_type' => 'plot', 'per_building' => false, 'priority' => 'haute'],

            ['name' => 'Semis ou repiquage', 'category' => 'controle', 'icon' => 'fa-seedling', 'color' => 'emerald',
             'frequency' => 'ponctuel', 'scheduled_time' => '06:00',
             'duration_minutes' => 240, 'target_type' => 'plot', 'per_building' => false, 'priority' => 'critique'],

            ['name' => 'Séchage et conditionnement de la récolte', 'category' => 'recolte', 'icon' => 'fa-sun', 'color' => 'yellow',
             'frequency' => 'ponctuel', 'scheduled_time' => '08:00',
             'duration_minutes' => 180, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Produit conditionné'],

            // ─────────── COMMERCE & CAISSE ───────────
            ['name' => 'Clôture de caisse et comptage', 'category' => 'controle', 'icon' => 'fa-cash-register', 'color' => 'emerald',
             'frequency' => 'quotidien', 'days_of_week' => $everyday, 'scheduled_time' => '18:00',
             'duration_minutes' => 30, 'priority' => 'critique',
             'proof_type' => 'valeur', 'proof_label' => 'Espèces comptées', 'proof_unit' => 'GNF',
             'description' => "Compter avant de fermer : un écart trouvé le soir s'explique, un écart trouvé un mois plus tard ne s'explique plus."],

            ['name' => 'Relance des créances échues', 'category' => 'controle', 'icon' => 'fa-hand-holding-dollar', 'color' => 'orange',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([1]), 'scheduled_time' => '09:00',
             'duration_minutes' => 60, 'priority' => 'haute'],

            ['name' => 'Dépôt bancaire des recettes', 'category' => 'controle', 'icon' => 'fa-building-columns', 'color' => 'blue',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([5]), 'scheduled_time' => '11:00',
             'duration_minutes' => 90, 'priority' => 'haute',
             'proof_type' => 'photo', 'proof_label' => 'Reçu de dépôt'],

            // ─────────── ÉQUIPE ───────────
            ['name' => 'Pointage de l’équipe', 'category' => 'controle', 'icon' => 'fa-user-check', 'color' => 'indigo',
             'frequency' => 'quotidien', 'days_of_week' => $weekdays, 'scheduled_time' => '07:00',
             'duration_minutes' => 15, 'priority' => 'haute',
             'description' => "Pointer le matin, pas le soir de mémoire : la présence se constate, elle ne se reconstitue pas."],

            ['name' => 'Réunion hebdomadaire d’équipe', 'category' => 'controle', 'icon' => 'fa-users', 'color' => 'slate',
             'frequency' => 'hebdo', 'days_of_week' => json_encode([1]), 'scheduled_time' => '07:30',
             'duration_minutes' => 45, 'priority' => 'normale'],
        ];
    }
};
