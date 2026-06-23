<?php

namespace App\Services;

use App\Models\CropCycle;
use App\Models\CropSpecies;
use App\Models\CropVariety;
use App\Models\Plot;
use App\Models\WeatherReading;
use Carbon\Carbon;

/**
 * CropAdvisorService — intelligence agronomique du module Production Végétale.
 *
 * Transforme les données déjà saisies (catalogue, dates de semis, saisons
 * guinéennes, relevés météo, historique d'assolement) en conseils actionnables.
 * Service en lecture seule, sans effet de bord : il ne fait que produire des
 * tableaux d'« advisories » consommés par les vues et les notifications.
 *
 * Chaque advisory a la forme :
 *   ['type' => 'rotation'|'planting_risk'|'weather',
 *    'severity' => 'critique'|'attention'|'conseil'|'info',
 *    'icon' => 'fa-...', 'title' => '...', 'message' => '...']
 */
class CropAdvisorService
{
    /** Types de cultures sensibles à l'humidité au moment de la récolte. */
    private const MOISTURE_SENSITIVE_TYPES = ['cereale', 'oleagineux', 'legumineuse'];

    /** Types « exigeants » (gros consommateurs d'éléments du sol). */
    private const HEAVY_FEEDER_TYPES = ['cereale', 'maraicher', 'tubercule', 'oleagineux'];

    /**
     * Résout la saison agricole guinéenne à partir d'un numéro de mois.
     */
    public function seasonForMonth(int $month): string
    {
        if ($month >= 5 && $month <= 10) {
            return 'grande_saison_pluies';
        }
        if ($month >= 11) {
            return 'petite_saison';
        }

        return 'saison_seche'; // janv–avr
    }

    // ──────────────────────────────────────────────
    // MÉTHODE A — RISQUES SEMIS / RÉCOLTE D'UN CYCLE
    // ──────────────────────────────────────────────

    /**
     * Conseils liés au calendrier de semis/récolte d'un cycle en cours.
     */
    public function cycleRisks(CropCycle $cycle): array
    {
        $advisories = [];

        // 1. Résolution du catalogue (espèce par nom, insensible à la casse) + variété.
        $species = CropSpecies::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $cycle->crop_name)])->first();

        $variety = null;
        if ($cycle->variety) {
            $varietyQuery = CropVariety::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $cycle->variety)]);
            if ($species) {
                $varietyQuery->where('crop_species_id', $species->id);
            }
            $variety = $varietyQuery->first();
        }

        // 2. Durée de cycle effective.
        $cycleDays = $variety?->cycle_days
            ?? $species?->cycle_days_max
            ?? $species?->cycle_days_min
            ?? null;

        $inProgress = in_array($cycle->status, CropCycle::IN_PROGRESS_STATUSES, true);
        $today = now()->startOfDay();

        // 3. Récolte en retard.
        $overdue = false;
        if ($inProgress && $cycle->expected_harvest_date && $cycle->expected_harvest_date->copy()->startOfDay()->lt($today)) {
            $overdue = true;
            $lateDays = (int) $cycle->expected_harvest_date->copy()->startOfDay()->diffInDays($today);
            $advisories[] = [
                'type'     => 'planting_risk',
                'severity' => 'critique',
                'icon'     => 'fa-clock',
                'title'    => 'Récolte en retard',
                'message'  => "La récolte prévue le {$cycle->expected_harvest_date->format('d/m/Y')} est dépassée de {$lateDays} jours.",
            ];
        }

        // Date de récolte projetée d'après le catalogue.
        $projected = ($cycleDays && $cycle->planting_date)
            ? Carbon::parse($cycle->planting_date)->copy()->addDays((int) $cycleDays)
            : null;

        // 4. Récolte projetée en grande saison des pluies (cultures sensibles).
        if (! $overdue && $projected) {
            $isMoistureSensitive = $species
                && (in_array($species->type, self::MOISTURE_SENSITIVE_TYPES, true)
                    || preg_match('/oignon|ail|échalote/i', (string) $species->name));

            // Le riz (riziculture de bas-fond) se récolte bien en saison des pluies : exception.
            $isRice = preg_match('/riz/i', (string) $cycle->crop_name)
                || ($species && preg_match('/riz/i', (string) $species->name));

            if ($isMoistureSensitive && ! $isRice
                && $this->seasonForMonth((int) $projected->month) === 'grande_saison_pluies') {
                $advisories[] = [
                    'type'     => 'planting_risk',
                    'severity' => 'attention',
                    'icon'     => 'fa-cloud-showers-heavy',
                    'title'    => 'Récolte en saison des pluies',
                    'message'  => "Récolte projetée vers {$projected->translatedFormat('F')} (grande saison des pluies) : séchage difficile et risque de pourriture pour {$cycle->crop_name}. Prévoir séchage/abri.",
                ];
            }
        }

        // 5. Date de récolte à renseigner.
        if (! $cycle->expected_harvest_date && $projected) {
            $advisories[] = [
                'type'     => 'planting_risk',
                'severity' => 'conseil',
                'icon'     => 'fa-calendar-plus',
                'title'    => 'Date de récolte à renseigner',
                'message'  => "D'après le catalogue ({$cycleDays} j), la récolte devrait tomber vers le {$projected->format('d/m/Y')}. Renseignez-la pour activer les rappels.",
            ];
        }

        // 6. Culture hors catalogue (seulement si aucun autre conseil n'a été émis).
        if (! $species && empty($advisories)) {
            $advisories[] = [
                'type'     => 'planting_risk',
                'severity' => 'info',
                'icon'     => 'fa-circle-info',
                'title'    => 'Culture hors catalogue',
                'message'  => "« {$cycle->crop_name} » n'est pas au catalogue : ajoutez-la pour bénéficier des recommandations automatiques.",
            ];
        }

        return $advisories;
    }

    // ──────────────────────────────────────────────
    // MÉTHODE B — ALERTES MÉTÉO D'UNE PARCELLE
    // ──────────────────────────────────────────────

    /**
     * Conseils météo pour une parcelle (basés sur les relevés récents).
     */
    public function weatherAlerts(Plot $plot): array
    {
        $since14 = now()->subDays(14);

        // Relevés de la parcelle (14 j), avec repli sur les relevés de la ferme.
        $readings = WeatherReading::where('plot_id', $plot->id)
            ->where('reading_date', '>=', $since14)
            ->get();

        if ($readings->isEmpty()) {
            $readings = WeatherReading::where('farm_id', $plot->farm_id)
                ->where('reading_date', '>=', $since14)
                ->get();
        }

        // 1. Aucun relevé récent.
        if ($readings->isEmpty()) {
            return [[
                'type'     => 'weather',
                'severity' => 'info',
                'icon'     => 'fa-cloud',
                'title'    => 'Pas de données météo',
                'message'  => 'Aucun relevé météo récent pour cette parcelle. Saisissez la pluviométrie pour activer les alertes.',
            ]];
        }

        $advisories = [];
        $since7 = now()->subDays(7);
        $last7 = $readings->filter(fn ($r) => $r->reading_date && $r->reading_date->gte($since7->copy()->startOfDay()));

        // 2. Stress hydrique : peu de pluie cumulée en saison sèche, sans irrigation.
        $totalRain = round((float) $readings->sum('rainfall_mm'), 1);
        if ($totalRain < 10
            && $this->seasonForMonth((int) now()->month) === 'saison_seche'
            && empty($plot->irrigation_type)) {
            $advisories[] = [
                'type'     => 'weather',
                'severity' => 'attention',
                'icon'     => 'fa-droplet-slash',
                'title'    => 'Stress hydrique probable',
                'message'  => "Seulement {$totalRain} mm de pluie sur 14 j en saison sèche, sans irrigation déclarée. Prévoir un arrosage.",
            ];
        }

        // 3. Fortes pluies récentes (≥ 50 mm sur un relevé des 7 derniers jours).
        $maxDay = round((float) $last7->max('rainfall_mm'), 1);
        if ($maxDay >= 50) {
            $advisories[] = [
                'type'     => 'weather',
                'severity' => 'attention',
                'icon'     => 'fa-cloud-showers-heavy',
                'title'    => 'Fortes pluies récentes',
                'message'  => "{$maxDay} mm relevés récemment : risque de lessivage des engrais et de maladies fongiques. Surveiller et différer la fertilisation.",
            ];
        }

        // 4. Stress thermique (température max. moyenne ≥ 38 °C sur 7 j).
        $temps = $last7->pluck('temperature_max')->filter(fn ($t) => $t !== null);
        if ($temps->isNotEmpty()) {
            $avgTemp = round((float) $temps->avg(), 1);
            if ($avgTemp >= 38) {
                $advisories[] = [
                    'type'     => 'weather',
                    'severity' => 'attention',
                    'icon'     => 'fa-temperature-high',
                    'title'    => 'Stress thermique',
                    'message'  => "Température max. moyenne de {$avgTemp}°C sur 7 j : ombrage/irrigation conseillés pour les cultures sensibles.",
                ];
            }
        }

        return $advisories;
    }

    // ──────────────────────────────────────────────
    // MÉTHODE C — SUGGESTIONS DE ROTATION
    // ──────────────────────────────────────────────

    /**
     * Conseils d'assolement / rotation pour la prochaine culture d'une parcelle.
     */
    public function rotationSuggestions(Plot $plot): array
    {
        // 1. Trois cycles les plus récents.
        $cycles = $plot->cropCycles()
            ->orderByDesc('planting_date')
            ->take(3)
            ->get();

        if ($cycles->isEmpty()) {
            return [[
                'type'     => 'rotation',
                'severity' => 'info',
                'icon'     => 'fa-circle-info',
                'title'    => 'Parcelle sans historique',
                'message'  => "Aucune culture enregistrée sur cette parcelle : démarrez par une légumineuse (arachide, niébé, soja) pour enrichir le sol en azote.",
            ]];
        }

        // Résolution du catalogue (type + famille) pour chaque cycle.
        $resolved = $cycles->map(function (CropCycle $c) {
            $species = CropSpecies::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $c->crop_name)])->first();

            return [
                'crop'   => $c->crop_name,
                'type'   => $species?->type,
                'family' => $species?->family,
            ];
        })->values();

        $advisories = [];

        // 2. Données du cycle le plus récent.
        $lastType   = $resolved[0]['type'];
        $lastFamily = $resolved[0]['family'];
        $lastCrop   = $resolved[0]['crop'];

        // 3. Même famille botanique sur deux cycles consécutifs distincts.
        if (isset($resolved[1])
            && $lastFamily
            && $resolved[1]['family']
            && mb_strtolower((string) $lastFamily) === mb_strtolower((string) $resolved[1]['family'])) {
            $advisories[] = [
                'type'     => 'rotation',
                'severity' => 'attention',
                'icon'     => 'fa-arrows-rotate',
                'title'    => 'Rotation à respecter',
                'message'  => "Deux cultures consécutives de la même famille ({$lastFamily}) : risque d'épuisement du sol et de maladies. Changez de famille botanique.",
            ];
        }

        // 4. Après une culture exigeante → suggérer une légumineuse.
        if (in_array($lastType, self::HEAVY_FEEDER_TYPES, true)) {
            $advisories[] = [
                'type'     => 'rotation',
                'severity' => 'conseil',
                'icon'     => 'fa-seedling',
                'title'    => 'Enrichir le sol',
                'message'  => "Après {$lastCrop} (culture exigeante), semez une légumineuse (arachide, niébé, soja, haricot) pour fixer l'azote et restaurer la fertilité.",
            ];
        }

        // 5. Après une légumineuse → suggérer une céréale / un maraîchage exigeant.
        if ($lastType === 'legumineuse') {
            $advisories[] = [
                'type'     => 'rotation',
                'severity' => 'conseil',
                'icon'     => 'fa-wheat-awn',
                'title'    => 'Profiter de l\'azote',
                'message'  => "Après une légumineuse, le sol est riche en azote : enchaînez avec une céréale (maïs, riz) ou un maraîchage exigeant (tomate, chou).",
            ];
        }

        // 6. Trois cultures exigeantes enchaînées sans légumineuse → jachère.
        $hasLegumeInHistory = $resolved->contains(fn ($r) => $r['type'] === 'legumineuse');
        if ($resolved->count() >= 3 && ! $hasLegumeInHistory) {
            $advisories[] = [
                'type'     => 'rotation',
                'severity' => 'conseil',
                'icon'     => 'fa-pause',
                'title'    => 'Repos / jachère',
                'message'  => "Plusieurs cultures exigeantes enchaînées : envisagez une jachère ou un engrais vert pour régénérer le sol.",
            ];
        }

        return $advisories;
    }

    // ──────────────────────────────────────────────
    // MÉTHODE D — RECOMMANDATION DE CULTURES POUR UNE PARCELLE
    // ──────────────────────────────────────────────

    /**
     * Recommande les cultures du catalogue les mieux adaptées à une parcelle, en
     * croisant zone agro-écologique, type de sol, saison de semis et historique
     * d'assolement (rotation). Service en lecture seule : ne renvoie une culture
     * que lorsqu'il existe une vraie raison agronomique (score >= 2).
     *
     * Chaque résultat :
     *   ['species' => CropSpecies, 'score' => int, 'reasons' => string[],
     *    'sowing_label' => ?string, 'in_season' => bool, 'avoid' => bool]
     *
     * Retourne [] si aucune espèce ne dispose encore de données de référence.
     */
    public function recommendCropsForPlot(Plot $plot, int $limit = 6): array
    {
        // 1. Contexte de la parcelle.
        $zone  = $plot->resolvedAgroZone();
        $soil  = mb_strtolower(trim((string) ($plot->soil_type ?? '')));
        $month = (int) now()->month;

        // 2. Culture la plus récente (famille/type), même résolution que rotationSuggestions.
        $lastFamily = null;
        $lastType   = null;
        $lastCrop   = null;
        $lastCycle = $plot->cropCycles()->orderByDesc('planting_date')->first();
        if ($lastCycle) {
            $lastSpecies = CropSpecies::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $lastCycle->crop_name)])->first();
            $lastFamily = $lastSpecies?->family;
            $lastType   = $lastSpecies?->type;
            $lastCrop   = $lastCycle->crop_name;
        }

        // 3. Espèces de référence : actives ET dotées d'au moins une zone agro.
        $species = CropSpecies::active()
            ->whereNotNull('agro_zones')
            ->get()
            ->filter(fn (CropSpecies $s) => ! empty($s->agro_zones));

        if ($species->isEmpty()) {
            return [];
        }

        $zoneLabel = $zone ? (CropSpecies::ZONES[$zone] ?? $zone) : null;

        $scored = [];
        foreach ($species as $sp) {
            $score   = 0;
            $reasons = [];

            // +3 — Zone agro-écologique favorable.
            if ($zone && is_array($sp->agro_zones) && in_array($zone, $sp->agro_zones, true)) {
                $score += 3;
                $reasons[] = "Adaptée à la zone {$zoneLabel}";
            } elseif (! $zone) {
                // Zone inconnue : on score quand même sur sol/saison, avec une note.
                $reasons[] = "Zone inconnue";
            }

            // +2 — Sol compatible (correspondance souple dans les deux sens).
            if ($soil !== '' && is_array($sp->soil_types) && ! empty($sp->soil_types)) {
                foreach ($sp->soil_types as $st) {
                    $st = mb_strtolower(trim((string) $st));
                    if ($st !== '' && (str_contains($soil, $st) || str_contains($st, $soil))) {
                        $score += 2;
                        $reasons[] = "Convient au sol {$soil}";
                        break;
                    }
                }
            }

            // +2 — Période de semis favorable (mois courant dans la fenêtre).
            if (is_array($sp->sowing_months) && in_array($month, array_map('intval', $sp->sowing_months), true)) {
                $score += 2;
                $reasons[] = "Période de semis favorable ({$sp->sowing_label})";
                $inSeason = true;
            } else {
                $inSeason = false;
            }

            // +2 — Légumineuse après une culture exigeante (restauration de l'azote).
            if ($sp->type === 'legumineuse'
                && in_array($lastType, ['cereale', 'maraicher', 'tubercule', 'oleagineux'], true)) {
                $score += 2;
                $reasons[] = "Légumineuse : restaure l'azote après {$lastCrop}";
            }

            // -4 — Même famille que la culture précédente (mauvaise rotation).
            $avoid = false;
            if ($lastFamily && $sp->family
                && mb_strtolower((string) $sp->family) === mb_strtolower((string) $lastFamily)) {
                $score -= 4;
                $reasons[] = "Même famille que la culture précédente (éviter)";
                $avoid = true;
            }

            // On ne retient qu'un match significatif.
            if ($score < 2) {
                continue;
            }

            $scored[] = [
                'species'      => $sp,
                'score'        => $score,
                'reasons'      => $reasons,
                'sowing_label' => $sp->sowing_label,
                'in_season'    => $inSeason,
                'avoid'        => $avoid,
            ];
        }

        // 4. Tri : score décroissant, puis rendement de référence décroissant.
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return (float) $b['species']->avg_yield_tha <=> (float) $a['species']->avg_yield_tha;
        });

        return array_slice($scored, 0, $limit);
    }

    // ──────────────────────────────────────────────
    // MÉTHODE E — PLAN DE SUIVI D'UN CYCLE
    // ──────────────────────────────────────────────

    /**
     * Plan de suivi/conseils d'un cycle : fenêtre de semis conseillée, date de
     * récolte recommandée, durée de cycle, besoin en eau/irrigation, conseils de
     * rendement et remarques dérivées. Résout l'espèce par nom (insensible à la
     * casse) et la variété.
     */
    public function monitoringPlan(CropCycle $cycle): array
    {
        // 1. Résolution catalogue (espèce + variété), même approche que cycleRisks.
        $species = CropSpecies::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $cycle->crop_name)])->first();

        $variety = null;
        if ($cycle->variety) {
            $varietyQuery = CropVariety::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $cycle->variety)]);
            if ($species) {
                $varietyQuery->where('crop_species_id', $species->id);
            }
            $variety = $varietyQuery->first();
        }

        // 2. Espèce hors catalogue : aucun conseil de référence possible.
        if (! $species) {
            return [
                'has_reference'             => false,
                'sowing_window'             => null,
                'sowing_ok'                 => null,
                'recommended_harvest_date'  => null,
                'cycle_days'                => null,
                'water_need'                => null,
                'irrigation'                => null,
                'yield_tips'                => null,
                'notes'                     => ["Culture hors catalogue : ajoutez-la pour activer les conseils de suivi."],
            ];
        }

        $notes = [];

        // 3. Durée de cycle effective : variété > max catalogue > min catalogue.
        $cycleDays = $variety?->cycle_days
            ?? $species->cycle_days_max
            ?? $species->cycle_days_min
            ?? null;

        // 4. Fenêtre de semis et conformité du semis réel.
        $sowingWindow = $species->sowing_label;
        $sowingOk = null;
        if (is_array($species->sowing_months) && ! empty($species->sowing_months) && $cycle->planting_date) {
            $plantingMonth = (int) Carbon::parse($cycle->planting_date)->month;
            $sowingOk = in_array($plantingMonth, array_map('intval', $species->sowing_months), true);
            if (! $sowingOk) {
                $notes[] = "Semis hors période conseillée (conseillé : {$sowingWindow}).";
            }
        }

        // 5. Date de récolte recommandée = semis + durée de cycle.
        $recommendedHarvest = ($cycleDays && $cycle->planting_date)
            ? Carbon::parse($cycle->planting_date)->copy()->addDays((int) $cycleDays)
            : null;

        // 6. Besoin en eau / irrigation.
        $waterNeed = $species->water_need
            ? (CropSpecies::WATER_NEEDS[$species->water_need] ?? $species->water_need)
            : null;
        $irrigation = $cycle->plot?->irrigation_type ?: null;

        if ($species->water_need === 'eleve' && empty($cycle->plot?->irrigation_type)) {
            $notes[] = "Besoin en eau élevé sans irrigation déclarée : prévoir un apport d'eau.";
        }

        return [
            'has_reference'             => true,
            'sowing_window'             => $sowingWindow,
            'sowing_ok'                 => $sowingOk,
            'recommended_harvest_date'  => $recommendedHarvest,
            'cycle_days'                => $cycleDays ? (int) $cycleDays : null,
            'water_need'                => $waterNeed,
            'irrigation'                => $irrigation,
            'yield_tips'                => $species->yield_tips,
            'notes'                     => $notes,
        ];
    }
}
