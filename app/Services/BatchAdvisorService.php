<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\ProductionNorm;
use Illuminate\Support\Collection;

/**
 * BatchAdvisorService — intelligence de suivi des lots.
 *
 * À partir du barème zootechnique (ProductionNorm) et des conditions réelles
 * (âge, effectif, poids relevé, température/hygrométrie du dernier pointage,
 * saison), calcule des recommandations de DOSAGE quotidien d'aliment et d'eau
 * par sujet et pour le lot entier, puis dérive des conseils (stress thermique,
 * sous/sur-alimentation, retard de croissance…).
 *
 * Les recommandations sont INDICATIVES : elles ajustent le barème de souche
 * aux conditions du jour pour guider l'éleveur, sans se substituer à son
 * jugement.
 */
class BatchAdvisorService
{
    /** Seuil de confort thermique (°C) au-delà duquel le stress chaleur agit. */
    private const HEAT_THRESHOLD = 30.0;

    /** Hausse d'abreuvement par °C au-dessus du seuil (caps à +60 %). */
    private const WATER_PER_DEGREE = 0.04;
    private const WATER_MAX_UPLIFT = 0.60;

    /** Baisse d'appétit par °C au-dessus du seuil (plancher -15 %). */
    private const FEED_PER_DEGREE = 0.012;
    private const FEED_MIN_FACTOR = 0.85;

    /** Ratio eau/aliment plancher pour la volaille (ml/g). */
    private const POULTRY_WATER_FEED_RATIO = 1.8;

    /**
     * Recommandation de dosage aliment + eau pour le lot.
     *
     * @return array{
     *   has_norm: bool, week: int, model_name: ?string, phase: ?string,
     *   per_subject: array{feed_g: float, water_ml: float, weight_target_g: float, laying_rate: float},
     *   total: array{feed_kg: float, water_l: float, subjects: int},
     *   environment: array{temp_c: ?float, humidity: ?float, source: string, season: string,
     *                      feed_factor: float, water_factor: float, heat_stress: bool},
     *   actual: array{feed_kg: ?float, water_l: ?float, weight_g: ?float}
     * }|null
     */
    public function recommendation(Batch $batch): ?array
    {
        $week = max(1, (int) ceil($batch->age / 7));

        $curve = $this->normCurve($batch);
        if ($curve->isEmpty()) {
            return null;
        }

        $base = $this->interpolate($curve, $week);

        $env = $this->environment($batch);

        $subjects = max(0, (int) $batch->current_quantity);

        // Aliment : barème ajusté à l'appétit (chaleur → moins de prise).
        $feedG = $base['feed'] !== null
            ? round($base['feed'] * $env['feed_factor'], 1)
            : null;

        // Eau : barème ajusté à la soif (chaleur → plus de besoin), avec
        // plancher du ratio eau/aliment pour la volaille.
        $waterMl = null;
        if ($base['water'] !== null) {
            $waterMl = $base['water'] * $env['water_factor'];
            if ($this->isPoultry($batch) && $feedG !== null) {
                $waterMl = max($waterMl, $feedG * self::POULTRY_WATER_FEED_RATIO);
            }
            $waterMl = round($waterMl, 1);
        }

        $last = $this->lastCheck($batch);

        return [
            'has_norm'    => true,
            'week'        => $week,
            'model_name'  => $batch->model_name,
            'phase'       => $base['phase'],
            'per_subject' => [
                'feed_g'          => $feedG ?? 0.0,
                'water_ml'        => $waterMl ?? 0.0,
                'weight_target_g' => round($base['weight'] ?? 0, 0),
                'laying_rate'     => round($base['laying'] ?? 0, 1),
            ],
            'total' => [
                'feed_kg'  => $feedG !== null ? round($feedG * $subjects / 1000, 2) : 0.0,
                'water_l'  => $waterMl !== null ? round($waterMl * $subjects / 1000, 2) : 0.0,
                'subjects' => $subjects,
            ],
            'environment' => $env,
            'actual' => [
                'feed_kg'  => $last ? (float) $last->feed_consumed : null,
                'water_l'  => $last ? (float) $last->water_consumed : null,
                'weight_g' => $last && $last->avg_weight ? (float) $last->avg_weight * 1000 : null,
            ],
        ];
    }

    /**
     * Conseils textuels dérivés de la recommandation (alertes hiérarchisées).
     *
     * @return array<int, array{severity: string, icon: string, title: string, message: string}>
     */
    public function advisories(Batch $batch): array
    {
        $reco = $this->recommendation($batch);
        if ($reco === null) {
            return [];
        }

        $out = [];
        $env = $reco['environment'];

        // 1. Stress thermique.
        if ($env['heat_stress']) {
            $tempLabel = $env['temp_c'] !== null
                ? number_format($env['temp_c'], 1) . ' °C'
                : 'saison chaude';
            $uplift = round(($env['water_factor'] - 1) * 100);
            $out[] = [
                'severity' => $env['temp_c'] !== null && $env['temp_c'] >= 35 ? 'critique' : 'attention',
                'icon'     => 'fa-temperature-high',
                'title'    => 'Stress thermique',
                'message'  => "Conditions chaudes ({$tempLabel}) : besoin en eau majoré d'environ {$uplift} %"
                    . " et appétit réduit. Assurer un abreuvement frais permanent, distribuer l'aliment aux"
                    . " heures fraîches (matin/soir) et ventiler le bâtiment.",
            ];
        }

        // 2. Aliment réel vs recommandé.
        if ($reco['actual']['feed_kg'] !== null && $reco['total']['feed_kg'] > 0) {
            $ratio = $reco['actual']['feed_kg'] / $reco['total']['feed_kg'];
            if ($ratio < 0.85) {
                $out[] = [
                    'severity' => 'attention',
                    'icon'     => 'fa-bowl-food',
                    'title'    => 'Sous-distribution d\'aliment',
                    'message'  => 'Dernière distribution ' . number_format($reco['actual']['feed_kg'], 1)
                        . ' kg contre ~' . number_format($reco['total']['feed_kg'], 1)
                        . ' kg recommandés. Vérifier l\'accès aux mangeoires et la disponibilité du stock.',
                ];
            } elseif ($ratio > 1.20) {
                $out[] = [
                    'severity' => 'conseil',
                    'icon'     => 'fa-bowl-food',
                    'title'    => 'Sur-distribution d\'aliment',
                    'message'  => 'Distribution ' . number_format($reco['actual']['feed_kg'], 1)
                        . ' kg, soit nettement au-dessus du barème (~' . number_format($reco['total']['feed_kg'], 1)
                        . ' kg). Risque de gaspillage : surveiller l\'indice de consommation (IC).',
                ];
            }
        }

        // 3. Eau réelle vs recommandée.
        if ($reco['actual']['water_l'] !== null && $reco['total']['water_l'] > 0) {
            $ratio = $reco['actual']['water_l'] / $reco['total']['water_l'];
            if ($ratio < 0.80) {
                $out[] = [
                    'severity' => 'attention',
                    'icon'     => 'fa-droplet-slash',
                    'title'    => 'Abreuvement insuffisant',
                    'message'  => 'Consommation d\'eau ' . number_format($reco['actual']['water_l'], 1)
                        . ' L contre ~' . number_format($reco['total']['water_l'], 1)
                        . ' L attendus. Contrôler les abreuvoirs (débit, fuites, propreté) sans délai.',
                ];
            }
        }

        // 4. Croissance vs objectif de poids.
        $target = $reco['per_subject']['weight_target_g'];
        $actualW = $reco['actual']['weight_g'];
        if ($target > 0 && $actualW !== null && $actualW > 0) {
            $perf = $actualW / $target * 100;
            if ($perf < 90) {
                $out[] = [
                    'severity' => $perf < 80 ? 'critique' : 'attention',
                    'icon'     => 'fa-weight-scale',
                    'title'    => 'Retard de croissance',
                    'message'  => 'Poids moyen ' . number_format($actualW) . ' g, soit ' . round($perf)
                        . ' % de l\'objectif (' . number_format($target) . ' g) à cet âge. Revoir densité,'
                        . ' ration et programme sanitaire.',
                ];
            } elseif ($perf > 110) {
                $out[] = [
                    'severity' => 'info',
                    'icon'     => 'fa-weight-scale',
                    'title'    => 'Croissance en avance',
                    'message'  => 'Poids moyen ' . number_format($actualW) . ' g, soit ' . round($perf)
                        . ' % de l\'objectif. Anticiper le passage de phase / la commercialisation.',
                ];
            }
        }

        // 5. Aucune anomalie : confirmation positive.
        if (empty($out)) {
            $out[] = [
                'severity' => 'conseil',
                'icon'     => 'fa-circle-check',
                'title'    => 'Conduite conforme au barème',
                'message'  => 'Les conditions et la conduite du lot sont alignées sur les objectifs de la souche.'
                    . ' Maintenir le suivi quotidien.',
            ];
        }

        return $out;
    }

    // ──────────────────────────────────────────────
    // INTERNES
    // ──────────────────────────────────────────────

    /**
     * Courbe de normes applicable au lot, triée par semaine.
     * Priorité à la souche (model_name) ; repli sur espèce + type ; puis type.
     */
    private function normCurve(Batch $batch): Collection
    {
        if ($batch->model_name) {
            $byModel = ProductionNorm::where('model_name', $batch->model_name)
                ->orderBy('week_number')->get();
            if ($byModel->isNotEmpty()) {
                return $byModel;
            }
        }

        $query = ProductionNorm::query()->where('batch_type', $batch->type);
        if ($batch->species_id) {
            $query->where(function ($q) use ($batch) {
                $q->whereNull('species_id')->orWhere('species_id', $batch->species_id);
            });
        }

        return $query->orderBy('week_number')->get();
    }

    /**
     * Interpole linéairement le barème (poids/aliment/eau/ponte) à une semaine.
     * Hors bornes : valeurs de la première / dernière ligne (extrapolation plate).
     *
     * @return array{weight: ?float, feed: ?float, water: ?float, laying: ?float, phase: ?string}
     */
    private function interpolate(Collection $curve, int $week): array
    {
        $lower = null;
        $upper = null;

        foreach ($curve as $row) {
            if ($row->week_number <= $week) {
                $lower = $row;
            }
            if ($row->week_number >= $week && $upper === null) {
                $upper = $row;
            }
        }

        // Avant la première semaine connue.
        if ($lower === null) {
            $first = $curve->first();
            return $this->pack($first, $first->phase_name);
        }

        // Au-delà de la dernière, ou pile sur un palier.
        if ($upper === null || $lower->week_number === $upper->week_number) {
            return $this->pack($lower, $lower->phase_name);
        }

        $span = $upper->week_number - $lower->week_number;
        $t = $span > 0 ? ($week - $lower->week_number) / $span : 0;

        return [
            'weight' => $this->lerp($lower->target_weight, $upper->target_weight, $t),
            'feed'   => $this->lerp($lower->target_feed_daily, $upper->target_feed_daily, $t),
            'water'  => $this->lerp($lower->target_water_daily, $upper->target_water_daily, $t),
            'laying' => $this->lerp($lower->target_laying_rate, $upper->target_laying_rate, $t),
            // Phase = celle du palier dont on est le plus proche.
            'phase'  => $t < 0.5 ? $lower->phase_name : $upper->phase_name,
        ];
    }

    /** @return array{weight: ?float, feed: ?float, water: ?float, laying: ?float, phase: ?string} */
    private function pack(ProductionNorm $row, ?string $phase): array
    {
        return [
            'weight' => $row->target_weight !== null ? (float) $row->target_weight : null,
            'feed'   => $row->target_feed_daily !== null ? (float) $row->target_feed_daily : null,
            'water'  => $row->target_water_daily !== null ? (float) $row->target_water_daily : null,
            'laying' => $row->target_laying_rate !== null ? (float) $row->target_laying_rate : null,
            'phase'  => $phase,
        ];
    }

    private function lerp($a, $b, float $t): ?float
    {
        if ($a === null && $b === null) {
            return null;
        }
        $a = (float) ($a ?? $b);
        $b = (float) ($b ?? $a);

        return $a + ($b - $a) * $t;
    }

    /**
     * Conditions d'ambiance : température/hygrométrie du dernier pointage si
     * disponible, sinon estimation saisonnière (Guinée). Calcule les facteurs
     * d'ajustement aliment / eau.
     */
    private function environment(Batch $batch): array
    {
        $month = (int) now()->month;
        $season = $this->seasonForMonth($month);

        $last = $this->lastCheck($batch);
        $temp = $last?->temp_max !== null ? (float) $last->temp_max : null;
        $humidity = $last?->humidity !== null ? (float) $last->humidity : null;
        $source = 'pointage';

        if ($temp === null) {
            // Estimation : températures max indicatives par saison en Guinée.
            $temp = match ($season) {
                'saison_seche'         => 34.0, // janv–avr, pic de chaleur
                'grande_saison_pluies' => 30.0,
                default                => 32.0, // petite saison (nov–déc)
            };
            $source = 'estimation_saison';
        }

        $over = max(0.0, $temp - self::HEAT_THRESHOLD);
        $waterFactor = 1.0 + min(self::WATER_MAX_UPLIFT, $over * self::WATER_PER_DEGREE);
        $feedFactor = max(self::FEED_MIN_FACTOR, 1.0 - $over * self::FEED_PER_DEGREE);

        // Hygrométrie élevée + chaleur aggravent le stress (indice THI) : on
        // renforce légèrement la majoration d'eau.
        if ($humidity !== null && $humidity >= 70 && $over > 0) {
            $waterFactor = min(1.0 + self::WATER_MAX_UPLIFT, $waterFactor + 0.05);
        }

        return [
            'temp_c'      => $temp,
            'humidity'    => $humidity,
            'source'      => $source,
            'season'      => $season,
            'feed_factor' => round($feedFactor, 3),
            'water_factor'=> round($waterFactor, 3),
            'heat_stress' => $over > 0,
        ];
    }

    private function lastCheck(Batch $batch): ?DailyCheck
    {
        if ($batch->relationLoaded('dailyChecks')) {
            return $batch->dailyChecks->sortByDesc('check_date')->first();
        }

        return $batch->dailyChecks()->orderByDesc('check_date')->first();
    }

    private function isPoultry(Batch $batch): bool
    {
        return method_exists($batch, 'isVolaille') ? (bool) $batch->isVolaille() : false;
    }

    /**
     * Saison guinéenne à partir du mois (alignée sur CropAdvisorService).
     */
    public function seasonForMonth(int $month): string
    {
        if ($month >= 5 && $month <= 10) {
            return 'grande_saison_pluies';
        }
        if ($month >= 11) {
            return 'petite_saison';
        }

        return 'saison_seche';
    }
}
