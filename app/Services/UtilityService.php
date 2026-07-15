<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\FuelPurchase;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UtilityService — KPI et alertes pour le module Eau & Énergie.
 *
 * Fournit les données au dashboard et aux alertes automatiques.
 */
class UtilityService
{
    /**
     * Données complètes pour le dashboard eau/énergie.
     */
    public function getDashboardData(int $periodDays = 30): array
    {
        $from = now()->subDays($periodDays);
        $totalBirds = Batch::active()->sum('current_quantity');

        return [
            'water'  => $this->getWaterStats($from, $totalBirds),
            'energy' => $this->getEnergyStats($from, $totalBirds),
            'fuel'   => $this->getFuelStats($from),
            'alerts' => $this->getAlerts(),
            'period' => $periodDays,
        ];
    }

    /**
     * Stats eau : consommation, coût, qualité.
     */
    private function getWaterStats(Carbon $from, int $totalBirds): array
    {
        $readings = WaterReading::where('reading_date', '>=', $from);

        // Conso issue des RELEVÉS manuels (citernes/réseau saisis dans le module).
        $readingsConsumed = (float) (clone $readings)->sum('volume_consumed_liters');
        $readingsCost     = (float) (clone $readings)->sum('cost');

        // Conso DÉRIVÉE des pointages journaliers (water_consumed, en litres) :
        // l'éleveur la saisit déjà au pointage, inutile de la ressaisir ici.
        // On l'agrège pour que le dashboard reflète la conso réelle des lots
        // sans double saisie. Pas de WaterReading créé → aucun double-comptage
        // avec les relevés manuels (ce sont deux sources distinctes : appoint
        // citerne vs consommation animale).
        $dcConsumed = (float) \App\Models\DailyCheck::where('check_date', '>=', $from)
            ->sum('water_consumed');

        // Coût estimé de la part « pointages » au tarif du m³ (paramètre énergie).
        $pricePerM3 = (float) setting('energie.water_price_m3', 0);
        $dcCost     = round(($dcConsumed / 1000) * $pricePerM3, 2);

        $totalConsumed = $readingsConsumed + $dcConsumed;
        $totalCost     = $readingsCost + $dcCost;

        // Nombre de jours avec donnée (relevés OU pointages) pour la moyenne.
        $readingDays = (clone $readings)->distinct('reading_date')->count('reading_date');
        $checkDays   = \App\Models\DailyCheck::where('check_date', '>=', $from)
            ->where('water_consumed', '>', 0)
            ->distinct('check_date')->count('check_date');
        $days = max(1, $readingDays, $checkDays);

        $dailyAvg = $totalConsumed / $days;
        $perBird = ($totalBirds > 0) ? ($dailyAvg / $totalBirds) : 0;
        $costPer1000 = ($totalBirds > 0) ? ($totalCost / ($totalBirds / 1000)) : 0;

        // Évolution par jour (7 derniers jours)
        $dailyTrend = WaterReading::select(
                DB::raw('reading_date'),
                DB::raw('SUM(volume_consumed_liters) as total_liters'),
                DB::raw('SUM(cost) as total_cost')
            )
            ->where('reading_date', '>=', now()->subDays(7))
            ->groupBy('reading_date')
            ->orderBy('reading_date')
            ->get();

        // Sources critiques (citernes basses)
        $criticalSources = WaterSource::critical()->get();

        // Dernier pH
        $lastPh = WaterReading::whereNotNull('quality_ph')
            ->latest('reading_date')->first();

        return [
            'total_consumed'   => round($totalConsumed),
            'total_cost'       => round($totalCost),
            'daily_avg'        => round($dailyAvg),
            'per_bird_per_day' => round($perBird, 3),
            'cost_per_1000'    => round($costPer1000),
            // Coût unitaire RÉALISÉ du m³ (coût total ÷ volume consommé) : KPI de
            // pilotage « usine » — dérive du prix réel, pas du tarif théorique.
            'cost_per_m3'      => $totalConsumed > 0 ? round($totalCost / ($totalConsumed / 1000)) : 0,
            'daily_trend'      => $dailyTrend,
            'critical_sources' => $criticalSources,
            'last_ph'          => $lastPh?->quality_ph,
            'ph_status'        => $lastPh?->ph_status ?? 'non_mesuré',
            // Transparence : part dérivée des pointages vs relevés manuels.
            'from_daily_checks' => round($dcConsumed),
            'from_readings'     => round($readingsConsumed),
        ];
    }

    /**
     * Stats énergie : heures, ratio EDG/groupe, coût, coupures.
     */
    private function getEnergyStats(Carbon $from, int $totalBirds): array
    {
        $readings = EnergyReading::where('reading_date', '>=', $from);

        $totalCost = (clone $readings)->sum('cost');
        $totalOutageHours = (clone $readings)->sum('outage_hours');
        $days = max(1, (clone $readings)->distinct('reading_date')->count('reading_date'));

        // Heures par type de source
        $hoursByType = EnergyReading::select(
                'energy_sources.type',
                DB::raw('SUM(energy_readings.hours_run) as total_hours')
            )
            ->join('energy_sources', 'energy_sources.id', '=', 'energy_readings.energy_source_id')
            ->where('energy_readings.reading_date', '>=', $from)
            ->groupBy('energy_sources.type')
            ->pluck('total_hours', 'type')
            ->toArray();

        $edgHours = $hoursByType['edg'] ?? 0;
        $groupeHours = $hoursByType['groupe'] ?? 0;
        $solaireHours = $hoursByType['solaire'] ?? 0;
        $totalHours = $edgHours + $groupeHours + $solaireHours;

        $edgRatio = ($totalHours > 0) ? round(($edgHours / $totalHours) * 100, 1) : 0;

        // kWh produits + valeur équivalente au tarif EDG (paramètre énergie) :
        // estime l'économie réalisée en autoproduisant plutôt qu'en achetant au réseau.
        $totalKwh = (clone $readings)->sum('kwh_produced');
        $edgValue = $totalKwh * (float) setting('energie.kwh_price_edg', 0);

        // Conso carburant
        $totalFuel = (clone $readings)->sum('fuel_consumed_liters');
        $fuelCostPerLiter = FuelPurchase::where('purchase_date', '>=', $from)
            ->avg('unit_price') ?? 12000; // Prix moyen carburant Guinée

        // Évolution par jour (7 derniers jours)
        $dailyTrend = EnergyReading::select(
                DB::raw('reading_date'),
                DB::raw('SUM(hours_run) as total_hours'),
                DB::raw('SUM(fuel_consumed_liters) as total_fuel'),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('SUM(outage_hours) as total_outage')
            )
            ->where('reading_date', '>=', now()->subDays(7))
            ->groupBy('reading_date')
            ->orderBy('reading_date')
            ->get();

        return [
            'total_cost'        => round($totalCost),
            'total_kwh'         => round($totalKwh, 1),
            'edg_value'         => round($edgValue),
            'total_fuel_liters' => round($totalFuel, 1),
            'total_outage'      => round($totalOutageHours, 1),
            'daily_outage_avg'  => round($totalOutageHours / $days, 1),
            'edg_hours'         => round($edgHours, 1),
            'groupe_hours'      => round($groupeHours, 1),
            'solaire_hours'     => round($solaireHours, 1),
            'edg_ratio'         => $edgRatio,
            'fuel_cost_per_liter' => round($fuelCostPerLiter),
            // Coûts unitaires RÉALISÉS : coût du kWh autoproduit et coût d'une
            // heure de fonctionnement (toutes sources). KPI « usine » pour
            // arbitrer EDG vs groupe et détecter une dérive de rendement.
            'cost_per_kwh'      => $totalKwh > 0 ? round($totalCost / $totalKwh) : 0,
            'cost_per_hour'     => $totalHours > 0 ? round($totalCost / $totalHours) : 0,
            'daily_trend'       => $dailyTrend,
        ];
    }

    /**
     * Stats carburant : stock, autonomie, prix moyen.
     */
    private function getFuelStats(Carbon $from): array
    {
        $groupes = EnergySource::groupes()->get();

        $totalFuelStock = $groupes->sum('current_fuel_level');
        $totalTankCapacity = $groupes->sum('fuel_tank_capacity');

        $purchases = FuelPurchase::where('purchase_date', '>=', $from);
        $totalPurchased = (clone $purchases)->sum('quantity_liters');
        $totalSpent = (clone $purchases)->sum('total_cost');
        $avgPrice = (clone $purchases)->avg('unit_price') ?? 0;

        // Autonomie globale
        $dailyFuelAvg = EnergyReading::where('reading_date', '>=', now()->subDays(7))
            ->whereNotNull('fuel_consumed_liters')
            ->avg('fuel_consumed_liters') ?? 0;

        $autonomyDays = ($dailyFuelAvg > 0) ? (int) floor($totalFuelStock / $dailyFuelAvg) : 30;

        return [
            'total_stock'       => round($totalFuelStock, 1),
            'tank_capacity'     => round($totalTankCapacity),
            'stock_percent'     => ($totalTankCapacity > 0) ? round(($totalFuelStock / $totalTankCapacity) * 100) : 0,
            'autonomy_days'     => $autonomyDays,
            'total_purchased'   => round($totalPurchased, 1),
            'total_spent'       => round($totalSpent),
            'avg_price_per_liter' => round($avgPrice),
            'groupes'           => $groupes,
        ];
    }

    /**
     * Alertes critiques eau & énergie.
     */
    public function getAlerts(): array
    {
        $alerts = [];

        // Citernes basses (< 30%)
        foreach (WaterSource::critical()->get() as $source) {
            $alerts[] = [
                'type'     => 'water',
                'severity' => 'critique',
                'title'    => "Citerne {$source->name} basse",
                'message'  => "Niveau : {$source->current_level_percent}% ({$source->current_level_liters} L)",
                'icon'     => 'fa-droplet',
            ];
        }

        // Carburant bas (autonomie sous le seuil paramétrable energie.autonomy_alert_hours)
        $alertHours = (float) setting('energie.autonomy_alert_hours', 24);
        foreach (EnergySource::groupes()->get() as $groupe) {
            if ($groupe->is_fuel_low) {
                $autonomyLabel = $groupe->fuel_autonomy_hours !== null
                    ? "{$groupe->fuel_autonomy_hours}h (seuil {$alertHours}h)"
                    : "{$groupe->fuel_autonomy_days} jour(s)";
                $alerts[] = [
                    'type'     => 'fuel',
                    'severity' => 'critique',
                    'title'    => "Carburant {$groupe->name} critique",
                    'message'  => "Autonomie : {$autonomyLabel}. Commander immédiatement.",
                    'icon'     => 'fa-gas-pump',
                ];
            }
        }

        // Maintenance groupe imminente (< 20h)
        foreach (EnergySource::groupes()->get() as $groupe) {
            if ($groupe->needs_maintenance) {
                $alerts[] = [
                    'type'     => 'maintenance',
                    'severity' => 'attention',
                    'title'    => "Maintenance {$groupe->name} imminente",
                    'message'  => "Restant : {$groupe->hours_before_maintenance}h avant vidange.",
                    'icon'     => 'fa-wrench',
                ];
            }
        }

        // pH hors norme
        $lastPhReading = WaterReading::whereNotNull('quality_ph')
            ->latest('reading_date')->first();
        if ($lastPhReading && $lastPhReading->ph_status === 'hors_norme') {
            $alerts[] = [
                'type'     => 'water_quality',
                'severity' => 'critique',
                'title'    => "pH eau hors norme",
                'message'  => "pH mesuré : {$lastPhReading->quality_ph}. Norme volaille : 6.5-8.5. Vérifier le traitement.",
                'icon'     => 'fa-flask',
            ];
        }

        // Anomalies de consommation (P3 — analytique : fuite eau / défaut moteur)
        foreach ($this->detectAnomalies() as $anomaly) {
            $alerts[] = $anomaly;
        }

        return $alerts;
    }

    /**
     * Détection d'anomalies de consommation par comparaison du dernier relevé
     * à la ligne de base récente de la source :
     *  - Eau   : volume du jour vs moyenne des relevés précédents → fuite/gaspillage.
     *  - Énergie : conso horaire (L/h) du jour vs moyenne → rendement anormal / défaut moteur.
     *
     * Le seuil d'écart est paramétrable (energie.anomaly_threshold_pct, défaut 50%).
     * Une ligne de base d'au moins 5 relevés est exigée pour éviter les faux
     * positifs sur les sources récentes.
     */
    public function detectAnomalies(): array
    {
        $threshold   = (float) setting('energie.anomaly_threshold_pct', 50);
        $minBaseline = 5;
        $anomalies   = [];

        // ─── EAU : volume consommé ───
        foreach (WaterSource::active()->get() as $source) {
            $readings = $source->readings()
                ->where('volume_consumed_liters', '>', 0)
                ->orderByDesc('reading_date')
                ->limit($minBaseline + 1)
                ->get();

            if ($readings->count() <= $minBaseline) continue;

            $latest   = (float) $readings->first()->volume_consumed_liters;
            $baseline = $readings->slice(1);
            $avg      = (float) $baseline->avg('volume_consumed_liters');

            if ($avg <= 0) continue;

            $deviation = ($latest - $avg) / $avg * 100;

            if ($deviation >= $threshold) {
                $anomalies[] = [
                    'type'     => 'anomaly_water',
                    'severity' => 'attention',
                    'title'    => "Conso eau anormale — {$source->name}",
                    'message'  => "+" . round($deviation) . "% vs habituel ("
                        . number_format($latest) . " L contre ~" . number_format($avg) . " L). "
                        . "Vérifier une fuite ou un abreuvoir ouvert.",
                    'icon'     => 'fa-droplet',
                ];
            }
        }

        // ─── ÉNERGIE : conso horaire (L/h) des groupes ───
        foreach (EnergySource::groupes()->get() as $source) {
            $readings = $source->readings()
                ->where('hours_run', '>', 0)
                ->whereNotNull('fuel_consumed_liters')
                ->where('fuel_consumed_liters', '>', 0)
                ->orderByDesc('reading_date')
                ->limit($minBaseline + 1)
                ->get();

            if ($readings->count() <= $minBaseline) continue;

            $rate = fn ($r) => (float) $r->fuel_consumed_liters / (float) $r->hours_run;

            $latestRate   = $rate($readings->first());
            $baselineRates = $readings->slice(1)->map($rate);
            $avgRate      = (float) $baselineRates->avg();

            if ($avgRate <= 0) continue;

            $deviation = ($latestRate - $avgRate) / $avgRate * 100;

            if ($deviation >= $threshold) {
                $anomalies[] = [
                    'type'     => 'anomaly_energy',
                    'severity' => 'attention',
                    'title'    => "Surconsommation — {$source->name}",
                    'message'  => "+" . round($deviation) . "% de carburant/heure ("
                        . number_format($latestRate, 1, ',', ' ') . " L/h contre ~"
                        . number_format($avgRate, 1, ',', ' ') . " L/h). "
                        . "Contrôler le moteur (filtres, injecteurs, charge).",
                    'icon'     => 'fa-gauge-high',
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Alerte composite « risque ventilation » : croise une forte chaleur
     * annoncée (prévision) avec la dépendance réelle au groupe électrogène.
     *
     * Logique métier : par canicule, le besoin de ventilation/refroidissement
     * grimpe ; si la ferme tourne déjà beaucoup sur groupe, une panne ou une
     * panne sèche couperait la ventilation au pire moment. On combine donc le
     * pic de température prévu (fourni par l'appelant, qui détient la prévision)
     * et la sollicitation récente du parc groupe (heures/jour sur 7 j).
     *
     * Méthode pure côté données énergie (aucun appel réseau) → testable sans
     * mocker la météo : l'appelant passe la T° max prévue.
     *
     * @param  float|null  $forecastMaxTemp  T° max annoncée sur l'horizon (°C).
     * @return array{type:string,severity:string,icon:string,title:string,message:string}|null
     */
    public function ventilationRisk(?float $forecastMaxTemp): ?array
    {
        $heatThreshold = (float) setting('energie.ventilation_heat_threshold', 36);
        if ($forecastMaxTemp === null || $forecastMaxTemp < $heatThreshold) {
            return null;
        }

        $groupes = EnergySource::groupes()->get();
        if ($groupes->isEmpty()) {
            return null; // Pas de groupe → pas de dépendance électrogène.
        }

        // Sollicitation récente : heures moyennes par jour du parc sur 7 jours.
        $hoursSum = EnergyReading::whereIn('energy_source_id', $groupes->pluck('id'))
            ->whereDate('reading_date', '>=', now()->subDays(7)->toDateString())
            ->sum('hours_run');
        $dailyHours = (float) $hoursSum / 7;

        $relianceThreshold = (float) setting('energie.ventilation_reliance_hours', 5);
        if ($dailyHours < $relianceThreshold) {
            return null; // Faible dépendance → risque non significatif.
        }

        // Autonomie carburant la plus basse du parc : aggrave le risque.
        $lowAutonomy = $groupes
            ->map(fn ($g) => $g->fuel_autonomy_hours)
            ->filter(fn ($h) => $h !== null)
            ->min();

        $autonomyTxt = $lowAutonomy !== null
            ? " Autonomie carburant la plus basse : {$lowAutonomy}h."
            : '';

        return [
            'type'     => 'composite_ventilation',
            'severity' => 'critique',
            'icon'     => 'fa-fan',
            'title'    => 'Risque ventilation (chaleur × dépendance groupe)',
            'message'  => "Forte chaleur annoncée ({$forecastMaxTemp}°C) et recours soutenu au groupe (~"
                . round($dailyHours, 1) . " h/j). Une panne ou une panne sèche couperait la ventilation."
                . $autonomyTxt
                . " Vérifier le carburant, l'état du groupe et la solution de secours avant la vague de chaleur.",
        ];
    }
}
