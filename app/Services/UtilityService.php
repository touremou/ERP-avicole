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

        $totalConsumed = (clone $readings)->sum('volume_consumed_liters');
        $totalCost = (clone $readings)->sum('cost');
        $days = max(1, (clone $readings)->distinct('reading_date')->count('reading_date'));

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
            'daily_trend'      => $dailyTrend,
            'critical_sources' => $criticalSources,
            'last_ph'          => $lastPh?->quality_ph,
            'ph_status'        => $lastPh?->ph_status ?? 'non_mesuré',
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

        // Conso gasoil
        $totalFuel = (clone $readings)->sum('fuel_consumed_liters');
        $fuelCostPerLiter = FuelPurchase::where('purchase_date', '>=', $from)
            ->avg('unit_price') ?? 12000; // Prix moyen gasoil Guinée

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

        // Gasoil bas (autonomie sous le seuil paramétrable energie.autonomy_alert_hours)
        $alertHours = (float) setting('energie.autonomy_alert_hours', 24);
        foreach (EnergySource::groupes()->get() as $groupe) {
            if ($groupe->is_fuel_low) {
                $autonomyLabel = $groupe->fuel_autonomy_hours !== null
                    ? "{$groupe->fuel_autonomy_hours}h (seuil {$alertHours}h)"
                    : "{$groupe->fuel_autonomy_days} jour(s)";
                $alerts[] = [
                    'type'     => 'fuel',
                    'severity' => 'critique',
                    'title'    => "Gasoil {$groupe->name} critique",
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

        return $alerts;
    }
}
