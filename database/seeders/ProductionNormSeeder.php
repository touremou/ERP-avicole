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
    public function run(): void
    {
        // Repère espèce par slug pour rattacher chaque souche.
        $speciesBySlug = Species::pluck('id', 'slug');

        // Purge : repart d'un référentiel propre (table de référence, pas de
        // données opérationnelles). Élimine doublons et model_name legacy.
        DB::table('production_norms')->delete();

        $rows = array_merge(
            $this->broilerRoss308(),
            $this->broilerCobb500(),
            $this->localChicken(),
            $this->turkeyBUT6(),
            $this->layerISABrown(),
            $this->layerLohmannBrown(),
            $this->layerLohmannLSL(),
            $this->quail(),
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

        foreach ($rows as $row) {
            $slug = ProductionNorm::guessSpeciesSlug($row['model_name']);
            $row['species_id'] = $slug ? ($speciesBySlug[$slug] ?? null) : null;

            ProductionNorm::create($row);
        }

        $this->command?->info('ProductionNormSeeder : ' . count($rows) . ' normes zootechniques consolidées.');
    }

    /**
     * Fabrique une ligne de norme.
     */
    private function norm(string $type, int $week, string $phase, string $model, float $weight, ?float $feed, ?float $water, float $laying = 0): array
    {
        return [
            'batch_type'         => $type,
            'week_number'        => $week,
            'phase_name'         => $phase,
            'model_name'         => $model,
            'target_weight'      => $weight,
            'target_feed_daily'  => $feed,
            'target_water_daily' => $water,
            'target_laying_rate' => $laying,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // VOLAILLE — CHAIR
    // ─────────────────────────────────────────────────────────────

    /** Poulet de chair Ross 308 (objectifs « tel qu'éclos »). */
    private function broilerRoss308(): array
    {
        $m = 'Ross 308';
        return [
            $this->norm('chair', 1, 'Démarrage',  $m, 190,  26, 50),
            $this->norm('chair', 2, 'Démarrage',  $m, 490,  56, 105),
            $this->norm('chair', 3, 'Croissance', $m, 960,  95, 175),
            $this->norm('chair', 4, 'Croissance', $m, 1550, 140, 255),
            $this->norm('chair', 5, 'Finition',   $m, 2200, 175, 320),
            $this->norm('chair', 6, 'Finition',   $m, 2850, 200, 365),
            $this->norm('chair', 7, 'Finition',   $m, 3400, 215, 390),
        ];
    }

    /** Poulet de chair Cobb 500. */
    private function broilerCobb500(): array
    {
        $m = 'Cobb 500';
        return [
            $this->norm('chair', 1, 'Démarrage',  $m, 185,  25, 48),
            $this->norm('chair', 2, 'Démarrage',  $m, 470,  54, 100),
            $this->norm('chair', 3, 'Croissance', $m, 940,  92, 170),
            $this->norm('chair', 4, 'Croissance', $m, 1520, 138, 250),
            $this->norm('chair', 5, 'Finition',   $m, 2150, 172, 315),
            $this->norm('chair', 6, 'Finition',   $m, 2800, 198, 360),
            $this->norm('chair', 7, 'Finition',   $m, 3350, 212, 385),
        ];
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

    /** Pondeuse ISA Brown : élevage poulette + cycle de ponte. */
    private function layerISABrown(): array
    {
        $m = 'ISA Brown';
        return [
            $this->norm('ponte', 1,  'Démarrage',  $m, 70,   13,  26,  0),
            $this->norm('ponte', 6,  'Croissance', $m, 480,  42,  85,  0),
            $this->norm('ponte', 12, 'Croissance', $m, 950,  62,  125, 0),
            $this->norm('ponte', 17, 'Pré-ponte',  $m, 1380, 75,  150, 0),
            $this->norm('ponte', 18, 'Pré-ponte',  $m, 1450, 82,  165, 5),
            $this->norm('ponte', 20, 'Ponte',      $m, 1550, 95,  190, 25),
            $this->norm('ponte', 22, 'Ponte',      $m, 1620, 110, 220, 75),
            $this->norm('ponte', 25, 'Ponte',      $m, 1750, 115, 230, 92),
            $this->norm('ponte', 30, 'Ponte',      $m, 1850, 118, 236, 95),
            $this->norm('ponte', 40, 'Ponte',      $m, 1920, 120, 240, 93),
            $this->norm('ponte', 52, 'Ponte',      $m, 1960, 122, 244, 88),
            $this->norm('ponte', 72, 'Réforme',    $m, 2000, 120, 240, 78),
        ];
    }

    /** Pondeuse Lohmann Brown : élevage poulette + cycle de ponte. */
    private function layerLohmannBrown(): array
    {
        $m = 'Lohmann Brown';
        return [
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
        ];
    }

    /** Pondeuse Lohmann LSL (œuf blanc, format léger). */
    private function layerLohmannLSL(): array
    {
        $m = 'Lohmann LSL';
        return [
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
        ];
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
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // VOLAILLE — POUSSINIÈRE & DIVERS
    // ─────────────────────────────────────────────────────────────

    /** Poussinière : référence générique d'élevage du poussin (0-4 sem.). */
    private function brooding(): array
    {
        $m = 'Poussin Standard';
        return [
            $this->norm('poussiniere', 1, 'Démarrage',  $m, 160,  22, 45),
            $this->norm('poussiniere', 2, 'Démarrage',  $m, 400,  45, 90),
            $this->norm('poussiniere', 3, 'Croissance', $m, 750,  70, 135),
            $this->norm('poussiniere', 4, 'Croissance', $m, 1100, 95, 180),
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

    /** Ovins, caprins (engraissement), lapins et porcs : 2 repères chacun. */
    private function sheepGoatRabbitPig(): array
    {
        return [
            // Mouton Djallonké (engraissement)
            $this->norm('engraissement', 1,  'Croissance', 'Mouton Djallonké', 12000, 600,  2500),
            $this->norm('engraissement', 12, 'Finition',   'Mouton Djallonké', 30000, 1400, 4500),

            // Chèvre du Sahel (engraissement)
            $this->norm('engraissement', 1,  'Croissance', 'Chèvre du Sahel', 12000, 550,  2500),
            $this->norm('engraissement', 12, 'Finition',   'Chèvre du Sahel', 32000, 1300, 4000),

            // Lapin Néo-Zélandais (engraissement)
            $this->norm('engraissement', 1,  'Croissance', 'Lapin Néo-Zélandais', 700,  60,  120),
            $this->norm('engraissement', 10, 'Finition',   'Lapin Néo-Zélandais', 2500, 150, 350),

            // Porc Large White (engraissement)
            $this->norm('engraissement', 1,  'Croissance', 'Porc Large White', 20000,  900,  4000),
            $this->norm('engraissement', 22, 'Finition',   'Porc Large White', 100000, 2800, 9000),
        ];
    }

    /** Chèvre laitière Saanen (croissance → lactation). */
    private function dairyGoat(): array
    {
        $m = 'Chèvre Saanen';
        return [
            $this->norm('laitiere', 1,  'Croissance', $m, 18000, 700,  3000, 0),
            $this->norm('laitiere', 40, 'Finition',   $m, 60000, 2000, 8000, 0),
        ];
    }

    /** Bovins : engraissement N'Dama (viande) + vache laitière métisse. */
    private function cattle(): array
    {
        return [
            // Bovin N'Dama (race locale trypanotolérante, engraissement viande)
            $this->norm('engraissement', 1,  'Croissance', 'Bovin N\'Dama', 80000,  12000, 25000, 0),
            $this->norm('engraissement', 26, 'Croissance', 'Bovin N\'Dama', 180000, 22000, 40000, 0),
            $this->norm('engraissement', 52, 'Finition',   'Bovin N\'Dama', 280000, 28000, 50000, 0),

            // Vache laitière métisse (croissance → lactation)
            $this->norm('laitiere', 1,  'Croissance', 'Vache Laitière (Métis)', 90000,  13000, 30000, 0),
            $this->norm('laitiere', 52, 'Finition',   'Vache Laitière (Métis)', 400000, 35000, 70000, 0),
        ];
    }

    /** Zébu Gobra (grand zébu sahélien, engraissement viande). */
    private function zebuGobra(): array
    {
        $m = 'Zébu Gobra';
        return [
            $this->norm('engraissement', 1,  'Croissance', $m, 90000,  14000, 28000, 0),
            $this->norm('engraissement', 26, 'Croissance', $m, 220000, 26000, 45000, 0),
            $this->norm('engraissement', 52, 'Finition',   $m, 350000, 32000, 60000, 0),
        ];
    }

    /** Reproducteurs mammifères + lapin (adultes de réforme/reproduction). */
    private function poultryBreeders(): array
    {
        return [
            $this->norm('reproducteur', 1, 'Croissance', 'Bélier Djallonké',  35000, 1500, 5000, 0),
            $this->norm('reproducteur', 1, 'Croissance', 'Bouc Sahélien',     40000, 1400, 4500, 0),
            $this->norm('reproducteur', 1, 'Croissance', 'Lapin Californien', 4500,  200,  450,  0),
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
