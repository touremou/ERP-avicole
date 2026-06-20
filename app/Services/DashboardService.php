<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Building;
use App\Models\Stock;
use App\Models\RawMaterial;
use App\Models\EggProduction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Service Dashboard — Console de commande AviSmart.
 *
 * BUGS CORRIGÉS dans cette version :
 * - DS-01 : $criticalBatches jamais initialisé → Undefined variable → crash vue
 * - DS-02 : Double boucle sur $allActive (identique, redondante)
 * - DS-03 : withSum syntax incorrecte (3 args au lieu d'appels séparés)
 * - DS-04 : StockHelper::getFeedAutonomy() non garanti → check conditionnel fragile
 * - DS-05 : Seuils de mortalité hardcodés (0.2%) → configurable par type/âge
 * - DS-06 : $offline_mode dans getOfflineData() encourage le pattern try/catch PDO
 *
 * LOGIQUES MÉTIER :
 * Logique 1 — Réactive (Urgence) : mortalité du DERNIER JOUR dépasse le seuil dynamique (ex: chair finition > 0.2%, poussin démarrage > 1%). Fenêtre 48h. Bloc rouge dans le dashboard.
 * - Logique 1 (Réactive) : Alerte sanitaire quotidienne — mortalité jour > seuil dynamique
 * Logique 2 — Cumulative (Dérive) : mortalité TOTALE du lot > 5% de l'effectif initial. Bloc orange dans le dashboard. Lien vers la fiche lot pour analyse.
 * - Logique 2 (Cumulative) : Dérive technique — mortalité cumulée > 5% total
 */
class DashboardService
{
    public function getOnlineData(): array
    {
        // ─── 1. LOTS ACTIFS ───
        $activeBatches = Batch::with(['building', 'dailyChecks'])
            ->active()
            ->live()
            ->latest()
            ->paginate(10);

        $allActive = Batch::active()
            ->live()
            ->with(['dailyChecks', 'productionType'])
            ->get();

        // ─── 2. EFFECTIFS ───
        $totalBirds    = $allActive->sum('current_quantity');
        $totalInitial  = $allActive->sum('initial_quantity');
        $layersCount   = $allActive->filter(fn (Batch $batch) => $batch->tracksEggs())
                                   ->sum('current_quantity');

        $globalMortalityRate = $totalInitial > 0
            ? (($totalInitial - $totalBirds) / $totalInitial) * 100
            : 0;

        // ─── 3. STOCKS & OEUFS ───
        $rawMaterialsValue = RawMaterial::selectRaw('COALESCE(SUM(stock_qty * unit_cost), 0) as total')
            ->value('total');

        $totalEggsStock = Stock::where('category', Stock::CAT_OEUFS)->sum('current_quantity');

        $eggsToday       = EggProduction::whereDate('production_date', today())->sum('total_eggs_collected');
        $totalBrokenToday = EggProduction::whereDate('production_date', today())->sum('broken_eggs');

        // ─── 4. ALERTES SANITAIRES ───
        // DS-01 corrigé : $emergencyBatches et $underperformingBatches initialisés proprement

        // LOGIQUE 1 — RÉACTIVE : Mortalité quotidienne anormale (48h)
        $emergencyBatches = $allActive->filter(function (Batch $batch) {
            if ($batch->current_quantity <= 0) return false;

            $lastCheck = $batch->dailyChecks->sortByDesc('check_date')->first();
            if (! $lastCheck || $lastCheck->mortality <= 0) return false;

            // Alerte valide uniquement sur les 48 dernières heures
            $checkDate = Carbon::parse($lastCheck->check_date);
            if ($checkDate->diffInDays(now()) > 2) return false;

            // DS-05 corrigé : Seuil dynamique par âge
            $age = $batch->age ?? Carbon::parse($batch->arrival_date ?? $batch->created_at)->diffInDays(now());
            $threshold = $this->getDailyMortalityThreshold($batch->type, $age);

            $dailyRate = ($lastCheck->mortality / $batch->current_quantity) * 100;
            return $dailyRate >= $threshold;
        })->values();

        // LOGIQUE 2 — CUMULATIVE : Dérive technique (mortalité cumulée > 5%)
        $underperformingBatches = $allActive->filter(function (Batch $batch) {
            if ($batch->initial_quantity <= 0) return false;

            $totalMortality = $batch->dailyChecks->sum('mortality') + (int) ($batch->qty_dead ?? 0);
            $globalRate = ($totalMortality / $batch->initial_quantity) * 100;

            return $globalRate > 5;
        })->values();

        // ─── 5. AUTONOMIE DES SILOS ───
        $criticalTypes = $this->calculateFeedAutonomy($allActive);

        // ─── 6. BÂTIMENTS ───
        $buildings = Building::withCount(['batches' => fn($q) => $q->active()])->get();
        $totalBuildings    = $buildings->count();
        $occupiedBuildings = $buildings->where('batches_count', '>', 0)->count();

        $sanitaryAlertsCount = $buildings->filter(function ($b) {
            return $b->status === Building::STATUS_DESINFECTION
                && Carbon::parse($b->updated_at)->diffInDays(now()) > ($b->max_sanitary_days ?? 21);
        })->count();

        $criticalStocksCount = Stock::whereRaw('current_quantity <= alert_threshold')->count();

        // ─── 7. MARGE NETTE ───
        $safeProfit = 0;
        if (Gate::allows('S') || (Auth::check() && in_array(Auth::user()->role ?? '', ['admin', 'Admin']))) {
            foreach ($allActive as $b) {
                $revenue = (float) ($b->total_revenue ?? 0);
                $costs   = (float) ($b->feedPurchases()->sum('total_price') ?? 0)
                         + (float) ($b->total_acquisition_cost ?? 0)
                         + (float) ($b->additional_costs ?? 0);
                $safeProfit += ($revenue - $costs);
            }
        }

        // ─── 8. HDP ───
        $hdp = $layersCount > 0 ? ($eggsToday / $layersCount) * 100 : 0;

        return [
            'activeBatches'          => $activeBatches,
            'totalBirds'             => $totalBirds,
            'globalMortalityRate'    => $globalMortalityRate,
            'emergencyBatches'       => $emergencyBatches,
            'underperformingBatches' => $underperformingBatches,
            'buildings'              => $buildings,
            'sanitaryAlertsCount'    => $sanitaryAlertsCount,
            'criticalStocksCount'    => $criticalStocksCount,
            'rawMaterialsValue'      => $rawMaterialsValue,
            'totalEggsStock'         => $totalEggsStock,
            'eggsToday'              => $eggsToday,
            'totalBrokenToday'       => $totalBrokenToday,
            'criticalTypes'          => $criticalTypes,
            'totalBuildings'         => $totalBuildings,
            'occupiedBuildings'      => $occupiedBuildings,
            'safeProfit'             => $safeProfit,
            'hdp'                    => $hdp,
            'offline_mode'           => false,
        ];
    }

    public function getOfflineData(): array
    {
        return [
            'activeBatches'          => collect([]),
            'totalBirds'             => 0,
            'globalMortalityRate'    => 0,
            'emergencyBatches'       => collect([]),
            'underperformingBatches' => collect([]),
            'buildings'              => collect([]),
            'sanitaryAlertsCount'    => 0,
            'criticalStocksCount'    => 0,
            'rawMaterialsValue'      => 0,
            'totalEggsStock'         => 0,
            'eggsToday'              => 0,
            'totalBrokenToday'       => 0,
            'criticalTypes'          => [],
            'totalBuildings'         => 0,
            'occupiedBuildings'      => 0,
            'safeProfit'             => 0,
            'hdp'                    => 0,
            'offline_mode'           => true,
        ];
    }

    // ─────────────────────────────────────────────
    // MÉTHODES PRIVÉES
    // ─────────────────────────────────────────────

    /**
     * Seuil de mortalité quotidienne par type et âge.
     *
     * Logique 1 (Réactive) : Ces seuils déclenchent une alerte "Urgence"
     * quand le taux de mortalité du JOUR dépasse le seuil.
     */
    private function getDailyMortalityThreshold(string $type, int $age): float
    {
        $type = strtolower($type);

        // Seuils en % de mortalité quotidienne par rapport à l'effectif vivant
        return match (true) {
            // Chair : seuil plus tolérant en démarrage, strict en finition
            $type === 'chair' && $age <= 7   => 1.0,   // Démarrage : jusqu'à 1%/jour acceptable
            $type === 'chair' && $age <= 28  => 0.5,   // Croissance
            $type === 'chair'                => 0.2,   // Finition

            // Ponte / Reproducteur
            in_array($type, ['ponte', 'repro', 'reproducteur']) && $age <= 42 => 0.5,
            in_array($type, ['ponte', 'repro', 'reproducteur'])               => 0.1,

            // Poussinière
            $type === 'poussiniere' && $age <= 14 => 1.0,
            $type === 'poussiniere'               => 0.3,

            // Par défaut
            default => 0.2,
        };
    }

    /**
     * Calcule l'autonomie des silos d'alimentation.
     *
     * DS-04 corrigé : plus de dépendance à StockHelper (peut ne pas exister).
     * Calcul direct : stock actuel / consommation moyenne journalière (30 derniers jours).
     */
    private function calculateFeedAutonomy($allActive): array
    {
        $criticalTypes = [];

        $feedNames = [
            'Chair Démarrage', 'Chair Croissance', 'Chair Finition',
            'Ponte Démarrage (Poussin)', 'Ponte Croissance (Poulette)',
            'Ponte 1 (Pic de ponte)', 'Ponte 2 (Entretien)',
        ];

        foreach ($feedNames as $name) {
            $stock = Stock::where('item_name', $name)->where('category', Stock::CAT_CONSO)->first();
            if (! $stock) continue;

            $currentKg = (float) $stock->current_quantity;

            // Consommation moyenne journalière sur les 30 derniers jours
            $avgDailyConsumption = DB::table('stock_movements')
                ->where('stock_id', $stock->id)
                ->where('type', 'out')
                ->where('created_at', '>=', now()->subDays(30))
                ->avg('quantity') ?? 0;

            $daysAutonomy = $avgDailyConsumption > 0
                ? (int) floor($currentKg / $avgDailyConsumption)
                : ($currentKg > 0 ? 999 : 0); // Si pas de conso mais du stock, pas d'alerte

            if ($daysAutonomy <= 3) {
                $criticalTypes[] = ['type' => $name, 'days' => $daysAutonomy];
            }
        }

        return $criticalTypes;
    }
}
