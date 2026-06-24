<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\ProductionNorm;
use App\Models\Stock;
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
    /** Hausse d'abreuvement par unité de THI au-dessus du seuil espèce (plafond +60 %). */
    private const WATER_PER_THI = 0.05;
    private const WATER_MAX_UPLIFT = 0.60;

    /** Baisse d'appétit par unité de THI au-dessus du seuil espèce (plancher −15 %). */
    private const FEED_PER_THI = 0.015;
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

        // 1. Stress thermique (THI).
        if ($env['heat_stress']) {
            $tempLabel = number_format($env['temp_c'], 1) . ' °C';
            $humLabel  = $env['humidity'] !== null ? number_format($env['humidity'], 0) . ' %HR' : '';
            $thiLabel  = 'THI ' . $env['thi'];
            $uplift    = round(($env['water_factor'] - 1) * 100);
            $isLater = isset($env['thi']) && $env['thi'] >= ($env['thi_threshold'] + 3.0);
            $out[] = [
                'severity' => $isLater ? 'critique' : 'attention',
                'icon'     => 'fa-temperature-high',
                'title'    => 'Stress thermique',
                'message'  => "{$thiLabel} ({$tempLabel}" . ($humLabel ? ", {$humLabel}" : '') . ") : besoin en eau majoré"
                    . " d'environ {$uplift} % et appétit réduit. Distribuer l'aliment aux heures fraîches"
                    . " (matin/soir), assurer un abreuvement frais permanent et ventiler le bâtiment.",
            ];
        }

        // 1b. Stock aliment — autonomie.
        $autonomy = $this->feedAutonomy($batch);
        if ($autonomy !== null) {
            if ($autonomy['is_critical']) {
                $out[] = [
                    'severity' => 'critique',
                    'icon'     => 'fa-box-open',
                    'title'    => 'Stock aliment critique',
                    'message'  => "Autonomie estimée : {$autonomy['days']} jour(s) ({$autonomy['stock_kg']} kg"
                        . " disponibles pour {$autonomy['daily_kg']} kg/j recommandés). Commander immédiatement.",
                ];
            } elseif ($autonomy['is_warning']) {
                $out[] = [
                    'severity' => 'attention',
                    'icon'     => 'fa-boxes-stacked',
                    'title'    => 'Stock aliment faible',
                    'message'  => "Autonomie estimée : {$autonomy['days']} jour(s) ({$autonomy['stock_kg']} kg"
                        . " disponibles pour {$autonomy['daily_kg']} kg/j). Planifier une commande cette semaine.",
                ];
            }
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
     * Conditions d'ambiance avec THI (indice température-humidité) par espèce.
     * Formule Celsius : THI = T − (0.55 − 0.55×HR/100) × (T − 14.4)
     * Seuils d'alerte par espèce (cf. thiThresholds).
     */
    private function environment(Batch $batch): array
    {
        $month  = (int) now()->month;
        $season = $this->seasonForMonth($month);

        $last     = $this->lastCheck($batch);
        $temp     = $last?->temp_max !== null ? (float) $last->temp_max : null;
        $humidity = $last?->humidity !== null ? (float) $last->humidity : null;
        $source   = 'pointage';

        if ($temp === null) {
            // Estimation saisonnière indicative (Guinée conakrylaise).
            [$temp, $humidity] = match ($season) {
                'saison_seche'         => [34.0, 40.0],
                'grande_saison_pluies' => [30.0, 80.0],
                default                => [32.0, 60.0],
            };
            $source = 'estimation_saison';
        } elseif ($humidity === null) {
            // Température mesurée mais humidité absente : estimation saisonnière.
            $humidity = match ($season) {
                'saison_seche'         => 40.0,
                'grande_saison_pluies' => 80.0,
                default                => 60.0,
            };
        }

        $thi        = $this->computeThi($temp, $humidity);
        $alertThi   = $this->thiThresholds($batch)['alert'];
        $over       = max(0.0, $thi - $alertThi);

        $waterFactor = 1.0 + min(self::WATER_MAX_UPLIFT, $over * self::WATER_PER_THI);
        $feedFactor  = max(self::FEED_MIN_FACTOR, 1.0 - $over * self::FEED_PER_THI);

        return [
            'temp_c'       => $temp,
            'humidity'     => $humidity,
            'thi'          => round($thi, 1),
            'thi_threshold'=> $alertThi,
            'source'       => $source,
            'season'       => $season,
            'feed_factor'  => round($feedFactor, 3),
            'water_factor' => round($waterFactor, 3),
            'heat_stress'  => $over > 0,
        ];
    }

    /** THI Celsius : T − (0.55 − 0.55×HR/100) × (T − 14.4) */
    private function computeThi(float $temp, float $humidity): float
    {
        return $temp - (0.55 - 0.55 * $humidity / 100.0) * ($temp - 14.4);
    }

    /**
     * Seuils THI (échelle Celsius) par FAMILLE d'espèce (source fiable :
     * Species::$family), avec repli sur le type de production / la souche pour
     * les lots sans espèce renseignée (données héritées).
     *
     * La famille lève l'ambiguïté du type (« grossissement » = poisson OU
     * ruminant d'embouche) : un poisson n'a pas de stress thermique aérien
     * (l'eau tamponne), un ruminant si.
     *
     * @return array{alert: float, critical: float}
     */
    private function thiThresholds(Batch $batch): array
    {
        $family = $batch->species?->family;
        $type   = strtolower((string) ($batch->type ?? ''));
        $model  = strtolower((string) ($batch->model_name ?? ''));

        // Aquaculture — l'eau tamponne, pas de stress thermique aérien.
        if ($family === 'aquaculture' || in_array($type, ['pisciculture', 'aquaculture', 'alevinage'], true)) {
            return ['alert' => PHP_INT_MAX, 'critical' => PHP_INT_MAX];
        }

        // Lapin
        if ($family === 'lagomorphe' || $type === 'lapin') {
            return ['alert' => 25.5, 'critical' => 28.0];
        }

        // Porc
        if ($family === 'porcin' || $type === 'porc') {
            return ['alert' => 26.0, 'critical' => 29.5];
        }

        // Ruminants (petits & grands)
        if (in_array($family, ['petit_ruminant', 'grand_ruminant'], true)
            || in_array($type, ['bovin', 'ovin', 'caprin', 'grossissement', 'lait', 'laitiere', 'embouche'], true)
        ) {
            return ['alert' => 27.0, 'critical' => 30.5];
        }

        // Volaille — la pondeuse (et le reproducteur) est plus sensible que le broiler.
        if ($family === 'volaille'
            || in_array($type, ['ponte', 'chair', 'dinde', 'pintade', 'canard', 'pigeon', 'poussiniere', 'reproducteur'], true)
            || str_contains($model, 'isa') || str_contains($model, 'lohmann')
            || str_contains($model, 'ross') || str_contains($model, 'cobb') || str_contains($model, 'but')
        ) {
            $isLayer = in_array($type, ['ponte', 'reproducteur'], true)
                || str_contains($model, 'isa') || str_contains($model, 'lohmann') || str_contains($model, 'caille');

            return $isLayer ? ['alert' => 24.0, 'critical' => 27.0] : ['alert' => 25.0, 'critical' => 28.5];
        }

        return ['alert' => 26.0, 'critical' => 30.0];
    }

    /**
     * Autonomie estimée en aliment : rapproche le dosage recommandé du stock
     * disponible (category=conso, unit=KG). Retourne null si aucun stock trouvé
     * ou si aucune recommandation calculable.
     *
     * @return array{stock_kg: float, daily_kg: float, days: int, is_critical: bool, is_warning: bool, item_names: string}|null
     */
    public function feedAutonomy(Batch $batch): ?array
    {
        $reco = $this->recommendation($batch);
        if ($reco === null || $reco['total']['feed_kg'] <= 0) {
            return null;
        }

        $dailyKg = $reco['total']['feed_kg'];

        // Type d'aliment utilisé par ce lot (depuis le dernier pointage avec feed_type)
        $feedType = $batch->dailyChecks()
            ->orderByDesc('check_date')
            ->whereNotNull('feed_type')
            ->value('feed_type');

        $baseQuery = Stock::where('category', Stock::CAT_CONSO)
            ->where('unit', 'KG')
            ->where('current_quantity', '>', 0);

        if ($feedType) {
            $matched = (clone $baseQuery)->where('feed_type', $feedType)->get();
            if ($matched->isEmpty()) {
                $matched = $baseQuery->get();
            }
        } else {
            $matched = $baseQuery->get();
        }

        if ($matched->isEmpty()) {
            return null;
        }

        $totalKg = (float) $matched->sum('current_quantity');
        $days    = (int) floor($totalKg / $dailyKg);

        return [
            'stock_kg'   => round($totalKg, 1),
            'daily_kg'   => round($dailyKg, 1),
            'days'       => $days,
            'is_critical' => $days < 3,
            'is_warning' => $days < 7,
            'item_names' => $matched->pluck('item_name')->join(', '),
        ];
    }

    /**
     * Courbe de croissance : poids moyen réel pesé (kg/sujet) confronté au
     * poids-cible interpolé de la souche, point par point sur les pointages.
     *
     * Sert le 3e graphique du show : la divergence réel/cible saute aux yeux.
     * Renvoie [] si aucune donnée exploitable (ni pesée, ni norme de poids).
     *
     * @return array{
     *   labels: array<int, string>,
     *   actual: array<int, ?float>,
     *   target: array<int, ?float>,
     *   has_actual: bool,
     *   has_target: bool
     * }
     */
    public function weightCurve(Batch $batch): array
    {
        $checks = $batch->relationLoaded('dailyChecks')
            ? $batch->dailyChecks->sortBy('check_date')->values()
            : $batch->dailyChecks()->orderBy('check_date')->get();

        $empty = ['labels' => [], 'actual' => [], 'target' => [], 'has_actual' => false, 'has_target' => false];
        if ($checks->isEmpty()) {
            return $empty;
        }

        $curve = $this->normCurve($batch);

        // Origine d'âge : même référence que le calendrier sanitaire du show.
        $start = $batch->transfer_date ?? $batch->start_date ?? $batch->arrival_date;
        $start = $start ? \Carbon\Carbon::parse($start)->startOfDay() : null;

        $labels = $actual = $target = [];
        $hasActual = $hasTarget = false;

        foreach ($checks as $i => $check) {
            $labels[] = 'J' . ($i + 1);

            // Poids réel pesé (kg/sujet). Null = pas de pesée ce jour-là
            // (Chart.js relie les points via spanGaps).
            $w = $check->avg_weight !== null ? (float) $check->avg_weight : null;
            $actual[] = ($w !== null && $w > 0) ? round($w, 3) : null;
            if ($actual[count($actual) - 1] !== null) {
                $hasActual = true;
            }

            // Poids-cible interpolé à l'âge du sujet au jour de la pesée
            // (le barème est stocké en grammes → on convertit en kg).
            $tg = null;
            if ($curve->isNotEmpty()) {
                $ageDays = $start
                    ? max(0, (int) $start->diffInDays(\Carbon\Carbon::parse($check->check_date)))
                    : $i;
                $week  = max(1, (int) ceil(($ageDays + 1) / 7));
                $tw    = $this->interpolate($curve, $week)['weight'];
                $tg    = $tw !== null ? round($tw / 1000, 3) : null;
                if ($tg !== null) {
                    $hasTarget = true;
                }
            }
            $target[] = $tg;
        }

        if (! $hasActual && ! $hasTarget) {
            return $empty;
        }

        return [
            'labels'     => $labels,
            'actual'     => $actual,
            'target'     => $target,
            'has_actual' => $hasActual,
            'has_target' => $hasTarget,
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
