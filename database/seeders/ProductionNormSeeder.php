<?php

namespace Database\Seeders;

use App\Models\ProductionNorm;
use App\Models\Species;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiel zootechnique consolidé (normes de production).
 *
 * SOURCE UNIQUE DE VÉRITÉ pour la table `production_norms`. Les anciennes
 * courbes de croissance dispersées dans ReferentialSeeder ont été retirées
 * pour éliminer les doublons de souches et les lignes à 0.
 *
 * Couverture :
 *  - VOLAILLE : courbes hebdomadaires complètes (poids, ration, eau, ponte)
 *    pour la chair (Ross 308, Cobb 500, Dinde BUT 6), la ponte (ISA Brown,
 *    Caille), la poussinière et les autres volailles (pintade, canard, pigeon).
 *  - MAMMIFÈRES & POISSONS : repères fiables (jeune → marché/adulte) avec
 *    poids cible, ration et eau réalistes — jamais de valeurs nulles.
 *
 * Unités : poids en grammes, ration `target_feed_daily` en g/sujet/jour,
 * eau `target_water_daily` en ml/sujet/jour, `target_laying_rate` en %.
 *
 * Valeurs INDICATIVES (guides de souche Aviagen/Cobb/ISA, FAO élevage tropical,
 * normes pisciculture Afrique de l'Ouest) — à ajuster par chaque ferme selon
 * sa génétique, son climat et son alimentation.
 *
 * Idempotent : purge puis réinsertion. Aucune FK ne référence cette table.
 */
class ProductionNormSeeder extends Seeder
{
    /**
     * Durée de cycle propre à une souche (jours), quand elle diffère nettement
     * de celle du type de production. Les autres souches suivent leur type
     * (cf. ProductionNorm::cycleDaysFor) ; l'exploitant règle les siennes dans
     * le référentiel des normes.
     */
    public const CYCLES = [
        'Poulet local Cou Nu' => 112,   // 16 semaines (fin de sa courbe)
        'Dinde BUT 6'         => 140,   // 20 semaines (fin de sa courbe)
        // Engraissement des mammifères, en ÂGE à la vente (courbes par âge).
        'Mouton Djallonké'    => 364,   // 52 semaines
        'Chèvre du Sahel'     => 364,   // 52 semaines
        'Lapin Néo-Zélandais' => 77,    // 11 semaines
        'Porc Large White'    => 168,   // 24 semaines
        // Fin de courbe = fin de cycle (le type en disait une autre).
        'Canard de Pékin'     => 49,    // 7 semaines (le type : 70 j)
        'Pintade locale'      => 98,    // 14 semaines (le type : 100 j)
        'Caille de chair'     => 42,    // 6 semaines
        'Reproducteur chair (poule)' => 448, // 64 semaines
        'Pintade pondeuse locale'    => 364, // 52 semaines
        'Pigeonneau de chair'        => 28,  // 4 semaines
        'Bovin N\'Dama'       => 364,   // 52 semaines (le type : 270 j)
        'Zébu Gobra'          => 364,
    ];

    public function run(): void
    {
        // Repère espèce par slug pour rattacher chaque souche.
        $speciesBySlug = Species::pluck('id', 'slug');

        // Durées de cycle saisies par l'exploitant : la purge ci-dessous les
        // effacerait. On les garde, et elles priment sur les valeurs livrées.
        $dureesSaisies = DB::table('production_norms')->whereNotNull('cycle_days')
            ->pluck('cycle_days', 'model_name')->all();

        // Purge : repart d'un référentiel propre (table de référence, pas de
        // données opérationnelles). Élimine doublons et model_name legacy.
        DB::table('production_norms')->delete();

        $rows = $this->lignes();

        foreach ($rows as $row) {
            $slug = ProductionNorm::guessSpeciesSlug($row['model_name']);
            $row['species_id'] = $slug ? ($speciesBySlug[$slug] ?? null) : null;
            $row['cycle_days'] = $dureesSaisies[$row['model_name']] ?? self::CYCLES[$row['model_name']] ?? null;

            ProductionNorm::create($row);
        }

        $this->command?->info('ProductionNormSeeder : ' . count($rows) . ' normes zootechniques consolidées.');
    }

    /**
     * Toutes les lignes livrées — publique : les migrations de reprise lisent
     * cette même déclaration plutôt qu'une copie.
     *
     * @return list<array<string, mixed>>
     */
    public function lignes(): array
    {
        return array_merge(
            $this->broilerRoss308(),
            $this->broilerCobb500(),
            $this->localChicken(),
            $this->turkeyBUT6(),
            $this->layerISABrown(),
            $this->layerLohmannBrown(),
            $this->layerLohmannLSL(),
            $this->quail(),
            $this->otherPoultry(),
            $this->brooding(),
            $this->guineaFowl(),
            $this->duck(),
            $this->pigeonBreeder(),
            $this->sheepGoatRabbitPig(),
            $this->dairyGoat(),
            $this->cattle(),
            $this->zebuGobra(),
            $this->poultryBreeders(),
            $this->fishGrowout(),
            $this->fishFry(),
        );
    }

    /**
     * Fabrique une ligne de norme.
     *
     * $guide (optionnel) : colonnes détaillées issues des fiches officielles
     * de souche — fourchettes conso/poids, uniformité cible, programme
     * lumineux (heures + lux), températures bâtiment.
     */
    private function norm(string $type, int $week, string $phase, string $model, float $weight, ?float $feed, ?float $water, float $laying = 0, array $guide = []): array
    {
        return array_merge([
            'batch_type'         => $type,
            'week_number'        => $week,
            'phase_name'         => $phase,
            'model_name'         => $model,
            'target_weight'      => $weight,
            'target_feed_daily'  => $feed,
            'target_water_daily' => $water,
            'target_laying_rate' => $laying,
        ], $guide);
    }

    /**
     * Merge un guide de CONDUITE STANDARD dans des lignes de normes, sans
     * écraser une valeur déjà posée (la fiche officielle de la souche prime —
     * cf. layerISABrown, calé sur le guide Hendrix).
     *
     * Sources : schémas communs aux guides commerciaux (programme lumineux
     * dégressif pondeuse, 23L:1D broiler première semaine, dégression de
     * température −2 à −3 °C/semaine, homogénéité ≥ 80 %). À CALER sur la
     * fiche officielle de chaque souche dès réception (même motif qu'ISA).
     */
    private function applyGuide(array $rows, string $guideType): array
    {
        return array_map(function (array $row) use ($guideType) {
            $guide = $guideType === 'broiler'
                ? $this->standardBroilerGuide((int) $row['week_number'])
                : $this->standardLayerGuide((int) $row['week_number']);

            return array_merge($guide, $row);
        }, $rows);
    }

    /** Conduite standard PONDEUSE commerciale (élevage dégressif puis stimulation). */
    private function standardLayerGuide(int $week): array
    {
        [$hours, $luxMin, $luxMax, $tMin, $tMax] = match (true) {
            $week === 1  => [18.0, 30, 40, 31, 33],
            $week <= 3   => [15.0, 10, 20, 26, 30],
            $week <= 6   => [12.0, 10, 10, 17, 23],
            $week <= 16  => [10.0, 10, 10, 17, 20],
            $week === 17 => [12.0, 10, 20, 17, 20],
            $week <= 19  => [13.0, 15, 20, 17, 20],
            $week <= 21  => [15.0, 15, 20, 17, 25],
            default      => [16.0, 15, 20, 17, 25],
        };

        return [
            'light_hours' => $hours, 'light_lux_min' => $luxMin, 'light_lux_max' => $luxMax,
            'temp_min_c' => $tMin, 'temp_max_c' => $tMax, 'uniformity_target' => 80,
        ];
    }

    /** Conduite standard BROILER (23L:1D en démarrage, lumière réduite ensuite). */
    private function standardBroilerGuide(int $week): array
    {
        [$hours, $luxMin, $luxMax, $tMin, $tMax] = match (true) {
            $week === 1 => [23.0, 30, 40, 30, 32],
            $week === 2 => [20.0, 20, 25, 27, 29],
            $week === 3 => [18.0, 10, 15, 24, 26],
            $week === 4 => [18.0, 5, 10, 21, 23],
            default     => [18.0, 5, 10, 18, 21],
        };

        return [
            'light_hours' => $hours, 'light_lux_min' => $luxMin, 'light_lux_max' => $luxMax,
            'temp_min_c' => $tMin, 'temp_max_c' => $tMax, 'uniformity_target' => 80,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // VOLAILLE — CHAIR
    // ─────────────────────────────────────────────────────────────

    /** Poulet de chair Ross 308 (objectifs « tel qu'éclos ») + conduite standard broiler. */
    private function broilerRoss308(): array
    {
        $m = 'Ross 308';
        return $this->applyGuide([
            $this->norm('chair', 1, 'Démarrage',  $m, 190,  26, 50),
            $this->norm('chair', 2, 'Démarrage',  $m, 490,  56, 105),
            $this->norm('chair', 3, 'Croissance', $m, 960,  95, 175),
            $this->norm('chair', 4, 'Croissance', $m, 1550, 140, 255),
            $this->norm('chair', 5, 'Finition',   $m, 2200, 175, 320),
            $this->norm('chair', 6, 'Finition',   $m, 2850, 200, 365),
            $this->norm('chair', 7, 'Finition',   $m, 3400, 215, 390),
        ], 'broiler');
    }

    /** Poulet de chair Cobb 500 + conduite standard broiler. */
    private function broilerCobb500(): array
    {
        $m = 'Cobb 500';
        return $this->applyGuide([
            $this->norm('chair', 1, 'Démarrage',  $m, 185,  25, 48),
            $this->norm('chair', 2, 'Démarrage',  $m, 470,  54, 100),
            $this->norm('chair', 3, 'Croissance', $m, 940,  92, 170),
            $this->norm('chair', 4, 'Croissance', $m, 1520, 138, 250),
            $this->norm('chair', 5, 'Finition',   $m, 2150, 172, 315),
            $this->norm('chair', 6, 'Finition',   $m, 2800, 198, 360),
            $this->norm('chair', 7, 'Finition',   $m, 3350, 212, 385),
        ], 'broiler');
    }

    /** Poulet local « Cou Nu » (souche rustique, croissance lente, Guinée). */
    private function localChicken(): array
    {
        $m = 'Poulet local Cou Nu';
        return [
            $this->norm('chair', 2,  'Démarrage',  $m, 120,  18, 36),
            $this->norm('chair', 4,  'Croissance', $m, 300,  35, 70),
            $this->norm('chair', 8,  'Croissance', $m, 700,  55, 110),
            $this->norm('chair', 12, 'Finition',   $m, 1100, 70, 140),
            $this->norm('chair', 16, 'Finition',   $m, 1400, 80, 160),
        ];
    }

    /** Dinde de chair BUT 6 (souche lourde). */
    private function turkeyBUT6(): array
    {
        $m = 'Dinde BUT 6';
        return [
            $this->norm('chair', 2,  'Démarrage',  $m, 450,   55,  110),
            $this->norm('chair', 4,  'Démarrage',  $m, 1300,  130, 260),
            $this->norm('chair', 8,  'Croissance', $m, 4200,  330, 650),
            $this->norm('chair', 12, 'Croissance', $m, 8200,  480, 950),
            $this->norm('chair', 16, 'Finition',   $m, 12500, 620, 1200),
            $this->norm('chair', 20, 'Finition',   $m, 16500, 720, 1400),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // VOLAILLE — PONTE
    // ─────────────────────────────────────────────────────────────

    /**
     * Pondeuse ISA Brown : élevage poulette S1-18 d'après le TABLEAU D'ÉLEVAGE
     * officiel Hendrix Genetics (fiche L-71-50-1) — fourchettes conso/poids
     * exactes, programme lumineux dégressif (paliers puis stimulation +2 h au
     * poids seuil ~1 350 g), intensités lumineuses et températures bâtiment.
     * Uniformité cible : ≥ 80 % des sujets à ±10 % du poids moyen.
     * Cycle de ponte 18-90 sem : pic 96 %, 50 % de ponte à 144 j, viabilité
     * 94 %, 417 œufs/poule départ (fiche produit ISA Brown).
     */
    private function layerISABrown(): array
    {
        $m = 'ISA Brown';

        // [sem, phase, conso min-max g/j, poids min-max g, h lumière, lux min-max, T° min-max]
        $rearing = [
            [1,  'Démarrage',  10, 12, 65,   68,   18.0, 30, 40, 31, 33],
            [2,  'Démarrage',  17, 19, 132,  138,  16.0, 20, 30, 28, 30],
            [3,  'Démarrage',  24, 26, 209,  219,  15.0, 10, 20, 26, 28],
            [4,  'Croissance', 30, 32, 294,  309,  14.0, 10, 10, 23, 25],
            [5,  'Croissance', 36, 38, 386,  406,  13.0, 10, 10, 21, 23],
            [6,  'Croissance', 41, 43, 482,  507,  12.0, 10, 10, 17, 20],
            [7,  'Croissance', 46, 48, 582,  611,  11.0, 10, 10, 17, 20],
            [8,  'Croissance', 50, 52, 682,  717,  10.0, 10, 10, 17, 20],
            [9,  'Croissance', 53, 55, 783,  823,  10.0, 10, 10, 17, 20],
            [10, 'Croissance', 57, 59, 882,  928,  10.0, 10, 10, 17, 20],
            [11, 'Croissance', 60, 62, 980,  1030, 10.0, 10, 10, 17, 20],
            [12, 'Croissance', 63, 65, 1074, 1129, 10.0, 10, 10, 17, 20],
            [13, 'Croissance', 66, 68, 1163, 1223, 10.0, 10, 10, 17, 20],
            [14, 'Croissance', 69, 71, 1248, 1312, 10.0, 10, 10, 17, 20],
            [15, 'Croissance', 72, 74, 1327, 1396, 10.0, 10, 10, 17, 20],
            [16, 'Croissance', 77, 79, 1401, 1473, 10.0, 10, 10, 17, 20],
            // Stimulation lumineuse (+2 h) dès le poids idéal (~1 350 g standard).
            [17, 'Pré-ponte',  80, 82, 1468, 1543, 12.0, 10, 20, 17, 20],
            [18, 'Pré-ponte',  84, 86, 1528, 1607, 13.0, 15, 20, 17, 20],
        ];

        $rows = [];
        foreach ($rearing as [$week, $phase, $feedMin, $feedMax, $wMin, $wMax, $hours, $luxMin, $luxMax, $tMin, $tMax]) {
            $feed   = round(($feedMin + $feedMax) / 2, 1);
            $weight = round(($wMin + $wMax) / 2);

            $rows[] = $this->norm('ponte', $week, $phase, $m, $weight, $feed, $feed * 2, $week >= 18 ? 5 : 0, [
                'feed_min_daily'    => $feedMin,
                'feed_max_daily'    => $feedMax,
                'weight_min'        => $wMin,
                'weight_max'        => $wMax,
                'uniformity_target' => 80,
                'light_hours'       => $hours,
                'light_lux_min'     => $luxMin,
                'light_lux_max'     => $luxMax,
                'temp_min_c'        => $tMin,
                'temp_max_c'        => $tMax,
            ]);
        }

        // Cycle de ponte : montée 14 → 16 h de lumière, 15-20 lux, bâtiment
        // tempéré. Courbe calée sur la fiche produit (50 % à 144 j ≈ S20-21,
        // pic 96 % vers S25-30, persistance longue).
        $laying = [
            [20, 'Ponte',   1550, 95,  25, 14.0],
            [22, 'Ponte',   1620, 110, 75, 15.0],
            [25, 'Ponte',   1750, 115, 96, 16.0],
            [30, 'Ponte',   1850, 118, 95, 16.0],
            [40, 'Ponte',   1920, 120, 93, 16.0],
            [52, 'Ponte',   1960, 122, 88, 16.0],
            [72, 'Réforme', 2000, 120, 78, 16.0],
        ];
        foreach ($laying as [$week, $phase, $weight, $feed, $rate, $hours]) {
            $rows[] = $this->norm('ponte', $week, $phase, $m, $weight, $feed, $feed * 2, $rate, [
                'uniformity_target' => 80,
                'light_hours'       => $hours,
                'light_lux_min'     => 15,
                'light_lux_max'     => 20,
                'temp_min_c'        => 17,
                'temp_max_c'        => 25,
            ]);
        }

        return $rows;
    }

    /** Pondeuse Lohmann Brown : élevage poulette + cycle de ponte + conduite standard. */
    private function layerLohmannBrown(): array
    {
        $m = 'Lohmann Brown';
        return $this->applyGuide([
            $this->norm('ponte', 1,  'Démarrage',  $m, 70,   13,  26,  0),
            $this->norm('ponte', 6,  'Croissance', $m, 480,  42,  85,  0),
            $this->norm('ponte', 12, 'Croissance', $m, 980,  63,  126, 0),
            $this->norm('ponte', 17, 'Pré-ponte',  $m, 1400, 76,  152, 0),
            $this->norm('ponte', 18, 'Pré-ponte',  $m, 1470, 83,  166, 5),
            $this->norm('ponte', 20, 'Ponte',      $m, 1580, 96,  192, 30),
            $this->norm('ponte', 25, 'Ponte',      $m, 1780, 116, 232, 93),
            $this->norm('ponte', 30, 'Ponte',      $m, 1880, 119, 238, 95),
            $this->norm('ponte', 40, 'Ponte',      $m, 1950, 121, 242, 92),
            $this->norm('ponte', 52, 'Ponte',      $m, 1980, 122, 244, 87),
            $this->norm('ponte', 72, 'Réforme',    $m, 2020, 120, 240, 76),
        ], 'layer');
    }

    /** Pondeuse Lohmann LSL (œuf blanc, format léger) + conduite standard. */
    private function layerLohmannLSL(): array
    {
        $m = 'Lohmann LSL';
        return $this->applyGuide([
            $this->norm('ponte', 1,  'Démarrage',  $m, 65,   12,  24,  0),
            $this->norm('ponte', 6,  'Croissance', $m, 420,  38,  76,  0),
            $this->norm('ponte', 12, 'Croissance', $m, 870,  58,  116, 0),
            $this->norm('ponte', 17, 'Pré-ponte',  $m, 1250, 70,  140, 0),
            $this->norm('ponte', 18, 'Pré-ponte',  $m, 1300, 78,  156, 5),
            $this->norm('ponte', 20, 'Ponte',      $m, 1380, 90,  180, 30),
            $this->norm('ponte', 25, 'Ponte',      $m, 1500, 108, 216, 94),
            $this->norm('ponte', 30, 'Ponte',      $m, 1560, 112, 224, 96),
            $this->norm('ponte', 40, 'Ponte',      $m, 1620, 114, 228, 93),
            $this->norm('ponte', 52, 'Ponte',      $m, 1680, 115, 230, 88),
            $this->norm('ponte', 72, 'Réforme',    $m, 1750, 113, 226, 77),
        ], 'layer');
    }

    /** Caille japonaise (Coturnix) : ponte précoce. */
    private function quail(): array
    {
        $m = 'Caille Japonaise';
        return [
            $this->norm('ponte', 1, 'Démarrage',  $m, 30,  6,  12, 0),
            $this->norm('ponte', 2, 'Démarrage',  $m, 70,  12, 24, 0),
            $this->norm('ponte', 4, 'Croissance', $m, 150, 22, 44, 0),
            $this->norm('ponte', 6, 'Ponte',      $m, 200, 28, 56, 50),
            $this->norm('ponte', 8, 'Ponte',      $m, 220, 30, 60, 85),
            // Plateau puis déclin de ponte : la courbe s'arrêtait à 8 semaines
            // pour un cycle de 240 jours (34 semaines).
            $this->norm('ponte', 20, 'Ponte',     $m, 230, 32, 64, 80),
            $this->norm('ponte', 34, 'Ponte',     $m, 230, 32, 64, 65),

            // Caille de CHAIR (type « Caille de Chair », 42 j) : aucune courbe
            // n'existait — la bande recevait le barème générique.
            $this->norm('chair', 1, 'Démarrage',  'Caille de chair', 30,  6,  12),
            $this->norm('chair', 3, 'Croissance', 'Caille de chair', 120, 18, 36),
            $this->norm('chair', 5, 'Finition',   'Caille de chair', 200, 26, 52),
            $this->norm('chair', 6, 'Finition',   'Caille de chair', 220, 28, 56),
        ];
    }

    /**
     * Types sans courbe jusqu'ici — repères PROPOSÉS (à caler sur le guide de
     * la souche dès réception) :
     *   • reproducteur chair (poule, cycle 450 j ≈ 64 sem.) : croissance
     *     rationnée, entrée en ponte vers 25 semaines ;
     *   • pintade pondeuse (365 j) : croissance jusqu'à 26 sem., saison de ponte ;
     *   • pigeonneau de chair (28 j) : nourri par ses parents — la « ration »
     *     est la part des parents qui lui revient.
     * Le dindon reproducteur reste sans courbe : pas de repère fiable ici.
     */
    private function otherPoultry(): array
    {
        $rp = 'Reproducteur chair (poule)';
        $pp = 'Pintade pondeuse locale';
        $pg = 'Pigeonneau de chair';
        return [
            $this->norm('reproducteur', 1,  'Démarrage',  $rp, 120,  25,  50,  0),
            $this->norm('reproducteur', 10, 'Croissance', $rp, 1100, 55,  110, 0),
            $this->norm('reproducteur', 20, 'Croissance', $rp, 2200, 110, 220, 0),
            $this->norm('reproducteur', 25, 'Ponte',      $rp, 3000, 150, 300, 5),
            $this->norm('reproducteur', 30, 'Ponte',      $rp, 3500, 165, 330, 80),
            $this->norm('reproducteur', 45, 'Ponte',      $rp, 3900, 160, 320, 75),
            $this->norm('reproducteur', 64, 'Ponte',      $rp, 4100, 155, 310, 55),

            $this->norm('ponte', 4,  'Démarrage',  $pp, 500,  45,  90,  0),
            $this->norm('ponte', 12, 'Croissance', $pp, 1300, 80,  160, 0),
            $this->norm('ponte', 26, 'Croissance', $pp, 1600, 100, 200, 0),
            $this->norm('ponte', 30, 'Ponte',      $pp, 1650, 110, 220, 30),
            $this->norm('ponte', 40, 'Ponte',      $pp, 1700, 115, 230, 45),
            $this->norm('ponte', 52, 'Ponte',      $pp, 1700, 110, 220, 35),

            $this->norm('chair', 1, 'Démarrage',  $pg, 150, 30, 60),
            $this->norm('chair', 2, 'Croissance', $pg, 350, 50, 100),
            $this->norm('chair', 3, 'Croissance', $pg, 500, 60, 120),
            $this->norm('chair', 4, 'Finition',   $pg, 550, 60, 120),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // VOLAILLE — POUSSINIÈRE & DIVERS
    // ─────────────────────────────────────────────────────────────

    /** Poussinière : référence générique d'élevage du poussin (0-4 sem.). */
    /**
     * Poussinière : ÉLEVAGE DE POULETTES jusqu'au transfert en ponte (13 sem.,
     * cycle du type : 90 j). La courbe s'arrêtait à 4 semaines, avec des
     * poids de poulet de chair (1,1 kg à 28 j) — pour un type de 90 jours.
     * Repères proposés, proches des guides de poulettes brunes.
     */
    private function brooding(): array
    {
        $m = 'Poussin Standard';
        return [
            $this->norm('poussiniere', 1,  'Démarrage',  $m, 65,   10, 20),
            $this->norm('poussiniere', 2,  'Démarrage',  $m, 120,  17, 34),
            $this->norm('poussiniere', 4,  'Croissance', $m, 270,  28, 56),
            $this->norm('poussiniere', 6,  'Croissance', $m, 450,  38, 76),
            $this->norm('poussiniere', 8,  'Croissance', $m, 650,  47, 94),
            $this->norm('poussiniere', 10, 'Croissance', $m, 840,  52, 104),
            $this->norm('poussiniere', 13, 'Finition',   $m, 1080, 60, 120),
        ];
    }

    /** Pintade locale (croissance lente). */
    private function guineaFowl(): array
    {
        $m = 'Pintade locale';
        return [
            $this->norm('chair', 2,  'Démarrage',  $m, 180,  22, 44),
            $this->norm('chair', 4,  'Croissance', $m, 500,  45, 90),
            $this->norm('chair', 8,  'Croissance', $m, 1100, 75, 150),
            $this->norm('chair', 12, 'Finition',   $m, 1400, 85, 170),
            $this->norm('chair', 14, 'Finition',   $m, 1500, 90, 180),
        ];
    }

    /** Canard de Pékin (forte consommation d'eau). */
    private function duck(): array
    {
        $m = 'Canard de Pékin';
        return [
            $this->norm('chair', 1, 'Démarrage',  $m, 180,  30,  90),
            $this->norm('chair', 2, 'Démarrage',  $m, 600,  90,  270),
            $this->norm('chair', 4, 'Croissance', $m, 1600, 160, 480),
            $this->norm('chair', 7, 'Finition',   $m, 3200, 220, 650),
        ];
    }

    /** Pigeon de chair/reproduction (Goliath). */
    private function pigeonBreeder(): array
    {
        return [
            $this->norm('reproducteur', 1, 'Croissance', 'Pigeon Goliath', 900, 40, 60, 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // MAMMIFÈRES — repères jeune → marché/adulte
    // ─────────────────────────────────────────────────────────────

    /**
     * ENGRAISSEMENT DES OVINS, CAPRINS, LAPINS ET PORCS — courbes par ÂGE.
     *
     * Ces courbes comptaient leurs semaines depuis l'ARRIVÉE (semaine 1 = un
     * agneau de 12 kg, porc de 20 kg) : depuis que l'âge part de la naissance,
     * un agneau acheté à 3 mois sautait en fin de courbe. Ré-exprimées en
     * semaines D'ÂGE — décision de l'exploitation, valeurs PROPOSÉES (repères
     * usuels en élevage tropical, à ajuster dans Admin › Normes) :
     *
     *   [semaine d'âge, phase, poids g, ration g/j, eau ml/j]
     *   - Mouton Djallonké : race naine trypanotolérante, ~25–30 kg à 12 mois ;
     *   - Chèvre du Sahel : ~30–35 kg à 12 mois ;
     *   - Lapin Néo-Zélandais : sevrage 5 sem., abattage ~2,5 kg à 11 sem. ;
     *   - Porc Large White : sevrage 4 sem., ~100 kg à 24 sem.
     *
     * Ration = ration totale (fourrage + concentré, matière sèche) pour les
     * ruminants ; aliment complet pour porc et lapin.
     */
    private function sheepGoatRabbitPig(): array
    {
        return [
            $this->norm('engraissement', 13, 'Croissance', 'Mouton Djallonké', 10000, 400,  1500),
            $this->norm('engraissement', 26, 'Croissance', 'Mouton Djallonké', 18000, 800,  3000),
            $this->norm('engraissement', 52, 'Finition',   'Mouton Djallonké', 28000, 1300, 4500),

            $this->norm('engraissement', 13, 'Croissance', 'Chèvre du Sahel', 12000, 450,  1500),
            $this->norm('engraissement', 26, 'Croissance', 'Chèvre du Sahel', 20000, 850,  2500),
            $this->norm('engraissement', 52, 'Finition',   'Chèvre du Sahel', 32000, 1300, 4000),

            $this->norm('engraissement', 5,  'Croissance', 'Lapin Néo-Zélandais', 900,  80,  200),
            $this->norm('engraissement', 8,  'Croissance', 'Lapin Néo-Zélandais', 1800, 130, 350),
            $this->norm('engraissement', 11, 'Finition',   'Lapin Néo-Zélandais', 2500, 160, 450),

            $this->norm('engraissement', 4,  'Démarrage',  'Porc Large White', 7000,   300,  1000),
            $this->norm('engraissement', 10, 'Croissance', 'Porc Large White', 25000,  1100, 4000),
            $this->norm('engraissement', 16, 'Croissance', 'Porc Large White', 55000,  2000, 6000),
            $this->norm('engraissement', 24, 'Finition',   'Porc Large White', 100000, 2800, 9000),
        ];
    }

    /** Chèvre laitière Saanen (croissance → lactation). */
    private function dairyGoat(): array
    {
        $m = 'Chèvre Saanen';
        return [
            // Une bande LAITIÈRE est un troupeau adulte en lactation (cycle du
            // type : 210 j, compté depuis l'arrivée). La courbe décrivait la
            // croissance d'un chevreau (18 kg → 60 kg). Repères de lactation
            // proposés : ration MS, eau majorée par la production de lait.
            $this->norm('laitiere', 1,  'Début de lactation', $m, 60000, 2200, 9000, 0),
            $this->norm('laitiere', 8,  'Pic de lactation',   $m, 60000, 2600, 11000, 0),
            $this->norm('laitiere', 30, 'Fin de lactation',   $m, 62000, 2000, 8000, 0),
        ];
    }

    /**
     * Bovins : engraissement N'Dama (viande) + vache laitière métisse.
     *
     * Ration en MATIÈRE SÈCHE, comme les petits ruminants — décision de
     * l'exploitation. Elle était exprimée en fourrage frais (12 kg/j pour un
     * veau de 80 kg, 15 % du poids vif) pendant que moutons et chèvres
     * l'étaient en matière sèche (~4 %) : deux échelles sous la même
     * étiquette « ration ». Valeurs PROPOSÉES : ingestion de MS ≈ 3 % du poids
     * vif chez le jeune, 2,6–2,7 % à l'engraissement, 3 % chez la vache en
     * lactation ; eau ≈ 10–12 % du poids vif (15 % en lactation).
     */
    private function cattle(): array
    {
        return [
            // Bovin N'Dama (race locale trypanotolérante, engraissement viande)
            $this->norm('engraissement', 1,  'Croissance', 'Bovin N\'Dama', 80000,  2400, 10000, 0),
            $this->norm('engraissement', 26, 'Croissance', 'Bovin N\'Dama', 180000, 4900, 22000, 0),
            $this->norm('engraissement', 52, 'Finition',   'Bovin N\'Dama', 280000, 7300, 32000, 0),

            // Vache laitière métisse (croissance → lactation)
            // Vache laitière métisse : troupeau EN LACTATION (cycle du type :
            // 305 j depuis l'arrivée), et non la croissance d'un veau.
            $this->norm('laitiere', 1,  'Début de lactation', 'Vache Laitière (Métis)', 400000, 11000, 55000, 0),
            $this->norm('laitiere', 8,  'Pic de lactation',   'Vache Laitière (Métis)', 400000, 13000, 70000, 0),
            $this->norm('laitiere', 43, 'Fin de lactation',   'Vache Laitière (Métis)', 420000, 10500, 50000, 0),
        ];
    }

    /** Zébu Gobra (grand zébu sahélien, engraissement viande). */
    private function zebuGobra(): array
    {
        $m = 'Zébu Gobra';
        return [
            $this->norm('engraissement', 1,  'Croissance', $m, 90000,  2700, 11000, 0),
            $this->norm('engraissement', 26, 'Croissance', $m, 220000, 5900, 26000, 0),
            $this->norm('engraissement', 52, 'Finition',   $m, 350000, 8800, 40000, 0),
        ];
    }

    /** Reproducteurs mammifères + lapin (adultes de réforme/reproduction). */
    private function poultryBreeders(): array
    {
        return [
            $this->norm('reproducteur', 1, 'Entretien', 'Bélier Djallonké',  35000, 1500, 5000, 0),
            $this->norm('reproducteur', 1, 'Entretien', 'Bouc Sahélien',     40000, 1400, 4500, 0),
            $this->norm('reproducteur', 1, 'Entretien', 'Lapin Californien', 4500,  200,  450,  0),
            // Types « Truie Reproductrice » et « Taureau Reproducteur » : aucune
            // courbe jusqu'ici. Adultes, ration d'entretien/gestation (MS pour
            // le taureau).
            $this->norm('reproducteur', 1, 'Entretien', 'Truie Large White', 200000, 2500, 15000, 0),
            $this->norm('reproducteur', 1, 'Entretien', 'Taureau N\'Dama',   350000, 8500, 40000, 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // PISCICULTURE — eau N/A (sujets aquatiques)
    // ─────────────────────────────────────────────────────────────

    /** Grossissement : tilapia, carpe, silure (ration en g/poisson/jour). */
    private function fishGrowout(): array
    {
        return [
            // Tilapia du Nil
            $this->norm('grossissement', 1,  'Démarrage',  'Tilapia du Nil', 5,   0.5, null),
            $this->norm('grossissement', 12, 'Croissance', 'Tilapia du Nil', 150, 3,   null),
            $this->norm('grossissement', 24, 'Finition',   'Tilapia du Nil', 400, 6,   null),

            // Carpe Commune
            $this->norm('grossissement', 1,  'Démarrage',  'Carpe Commune', 8,   0.6, null),
            $this->norm('grossissement', 24, 'Finition',   'Carpe Commune', 600, 8,   null),

            // Silure Africain (Clarias)
            $this->norm('grossissement', 1,  'Démarrage',  'Silure Africain', 10,   0.8, null),
            $this->norm('grossissement', 12, 'Croissance', 'Silure Africain', 500,  9,   null),
            $this->norm('grossissement', 20, 'Finition',   'Silure Africain', 1000, 15,  null),
        ];
    }

    /** Alevinage tilapia (pré-grossissement). */
    private function fishFry(): array
    {
        $m = 'Alevin Tilapia';
        return [
            $this->norm('alevinage', 1, 'Démarrage',  $m, 1, 0.1, null),
            $this->norm('alevinage', 4, 'Croissance', $m, 5, 0.4, null),
        ];
    }
}
