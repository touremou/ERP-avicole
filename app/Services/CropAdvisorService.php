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
}
