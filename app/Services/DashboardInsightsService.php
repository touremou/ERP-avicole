<?php

namespace App\Services;

use App\Models\DailyCheck;
use App\Models\EggProduction;
use App\Models\Expense;
use App\Models\HealthCheck;
use App\Models\MilkProduction;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DashboardInsightsService — enrichissement « industriel » de la console.
 *
 * Centralise trois familles d'indicateurs dérivés des données déjà saisies :
 *   1. KPI techniques (zootechnie) : IC moyen (FCR), GMQ, viabilité, coût
 *      aliment par kg vif, prix de revient de l'œuf.
 *   2. Synthèse financière du mois : chiffre d'affaires (ventes + lait),
 *      charges (aliment, santé, dépenses validées ventilées), marge nette,
 *      trésorerie (encours clients).
 *   3. Tendances 30 jours (mortalité, ponte, consommation d'aliment) pour les
 *      graphiques.
 *
 * Tous les calculs s'appuient sur les lots ACTIFS & RÉELS (->live()), cohérents
 * avec les KPI du DashboardController (exclusion des lots virtuels).
 */
class DashboardInsightsService
{
    /**
     * KPI techniques agrégés à l'échelle de la ferme.
     *
     * @param Collection<int, \App\Models\Batch> $activeBatches Lots actifs & réels.
     * @param float $globalMortalityRate Taux de mortalité cumulé déjà calculé (%).
     * @return array{
     *   fcr: ?float, gmq_g: ?int, viability: float,
     *   feed_cost_per_kg: ?float, cost_per_egg: ?float,
     *   biomass_kg: float, has_data: bool
     * }
     */
    public function technical(Collection $activeBatches, float $globalMortalityRate): array
    {
        $ids = $activeBatches->pluck('id')->all();

        if (empty($ids)) {
            return [
                'fcr' => null, 'gmq_g' => null, 'viability' => 100.0,
                'feed_cost_per_kg' => null, 'cost_per_egg' => null,
                'biomass_kg' => 0.0, 'has_data' => false,
            ];
        }

        // Agrégats aliment par lot (cumul vie du lot) — 1 requête.
        $feedByBatch = DailyCheck::whereIn('batch_id', $ids)
            ->select('batch_id',
                DB::raw('COALESCE(SUM(feed_consumed), 0) AS feed_kg'),
                DB::raw('COALESCE(SUM(feed_consumed * COALESCE(feed_unit_cost, 0)), 0) AS feed_cost'))
            ->groupBy('batch_id')
            ->get()->keyBy('batch_id');

        // Pesées (1ère / dernière) par lot pour le GMQ — 1 requête.
        $weighings = DailyCheck::whereIn('batch_id', $ids)
            ->whereNotNull('avg_weight')
            ->where('avg_weight', '>', 0)
            ->orderBy('check_date')
            ->get(['batch_id', 'avg_weight', 'check_date'])
            ->groupBy('batch_id');

        $totalFeedKg   = 0.0;
        $totalFeedCost = 0.0;
        $totalBiomass  = 0.0;   // kg vif produit (effectif × dernier poids)
        $gmqValues     = [];

        foreach ($activeBatches as $batch) {
            $feed = $feedByBatch->get($batch->id);
            $w    = $weighings->get($batch->id);

            $lastWeight = $w?->last()->avg_weight ? (float) $w->last()->avg_weight : 0.0;
            if ($lastWeight > 0 && $batch->current_quantity > 0) {
                $totalBiomass  += $batch->current_quantity * $lastWeight;
                $totalFeedKg   += (float) ($feed->feed_kg ?? 0);
                $totalFeedCost += (float) ($feed->feed_cost ?? 0);
            }

            // GMQ : gain quotidien entre 1ʳᵉ et dernière pesée (g/j).
            if ($w && $w->count() >= 2) {
                $first = $w->first();
                $last  = $w->last();
                $days  = Carbon::parse($first->check_date)->diffInDays(Carbon::parse($last->check_date));
                $gain  = ((float) $last->avg_weight - (float) $first->avg_weight) * 1000; // g
                if ($days > 0 && $gain > 0) {
                    $gmqValues[] = $gain / $days;
                }
            }
        }

        $fcr = $totalBiomass > 0 ? round($totalFeedKg / $totalBiomass, 2) : null;
        $feedCostPerKg = $totalBiomass > 0 ? round($totalFeedCost / $totalBiomass, 0) : null;
        $gmq = ! empty($gmqValues) ? (int) round(array_sum($gmqValues) / count($gmqValues)) : null;

        return [
            'fcr'              => $fcr,
            'gmq_g'            => $gmq,
            'viability'        => round(max(0, 100 - $globalMortalityRate), 1),
            'feed_cost_per_kg' => $feedCostPerKg,
            'cost_per_egg'     => $this->costPerEgg($activeBatches),
            'biomass_kg'       => round($totalBiomass, 1),
            'has_data'         => $totalBiomass > 0 || ! empty($gmqValues),
        ];
    }

    /**
     * Prix de revient indicatif de l'œuf sur le mois : (aliment + santé des lots
     * de ponte) / œufs collectés. Null si aucune ponte sur la période.
     */
    private function costPerEgg(Collection $activeBatches): ?float
    {
        $layingIds = $activeBatches->filter(fn ($b) => $b->tracksEggs())->pluck('id')->all();
        if (empty($layingIds)) {
            return null;
        }

        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();

        $eggs = (int) EggProduction::whereIn('batch_id', $layingIds)
            ->whereBetween('production_date', [$start, $end])
            ->sum('total_eggs_collected');

        if ($eggs <= 0) {
            return null;
        }

        $feedCost = (float) DailyCheck::whereIn('batch_id', $layingIds)
            ->whereBetween('check_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(feed_consumed * COALESCE(feed_unit_cost, 0)), 0) AS c')
            ->value('c');

        $healthCost = (float) HealthCheck::whereIn('batch_id', $layingIds)
            ->whereBetween('intervention_date', [$start, $end])
            ->sum('cost');

        return round(($feedCost + $healthCost) / $eggs, 0);
    }

    /**
     * Synthèse financière du mois courant (source unique pour la marge nette).
     *
     * @return array{
     *   ca_ventes: float, ca_lait: float, ca_total: float,
     *   cost_feed: float, cost_health: float, cost_expenses: float, cost_total: float,
     *   net_margin: float, receivables: float,
     *   top_expenses: array<int, array{label: string, amount: float}>
     * }
     */
    public function financial(Carbon $monthStart, Carbon $monthEnd): array
    {
        $caVentes = (float) Sale::validated()
            ->whereBetween('sale_date', [$monthStart, $monthEnd])
            ->sum('total_amount');

        $caLait = (float) MilkProduction::whereBetween('production_date', [$monthStart, $monthEnd])
            ->where('unit_price', '>', 0)
            ->sum(DB::raw('total_liters * unit_price'));

        $caTotal = $caVentes + $caLait;

        $costFeed = (float) DailyCheck::whereBetween('check_date', [$monthStart, $monthEnd])
            ->selectRaw('COALESCE(SUM(feed_consumed * COALESCE(feed_unit_cost, 0)), 0) AS c')
            ->value('c');

        $costHealth = (float) HealthCheck::whereBetween('intervention_date', [$monthStart, $monthEnd])->sum('cost');

        // Dépenses validées du mois, ventilées par catégorie (top 4).
        $expensesByCat = Expense::validated()
            ->betweenDates($monthStart, $monthEnd)
            ->select('category', DB::raw('SUM(amount) AS total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $costExpenses = (float) $expensesByCat->sum('total');

        $topExpenses = $expensesByCat->take(4)->map(fn ($e) => [
            'label'  => Expense::CATEGORIES[$e->category] ?? ucfirst((string) $e->category),
            'amount' => (float) $e->total,
        ])->values()->all();

        $costTotal = $costFeed + $costHealth + $costExpenses;

        // Trésorerie : encours clients (ventes non soldées).
        $receivables = (float) Sale::unpaid()->get()->sum(fn ($s) => $s->remaining_amount);

        return [
            'ca_ventes'     => $caVentes,
            'ca_lait'       => $caLait,
            'ca_total'      => $caTotal,
            'cost_feed'     => $costFeed,
            'cost_health'   => $costHealth,
            'cost_expenses' => $costExpenses,
            'cost_total'    => $costTotal,
            'net_margin'    => $caTotal - $costTotal,
            'receivables'   => $receivables,
            'top_expenses'  => $topExpenses,
        ];
    }

    /**
     * Séries temporelles (N derniers jours) pour les graphiques de tendance.
     *
     * @param array<int, int> $batchIds Lots actifs & réels (pour mortalité/aliment).
     * @return array{labels: array<int,string>, mortality: array<int,int>, eggs: array<int,int>, feed: array<int,float>}
     */
    public function trends(array $batchIds, int $days = 30): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        // Squelette de dates (jours sans donnée = 0, pas de trous dans la courbe).
        $labels = [];
        $byDate = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $labels[] = $d;
            $byDate[$d] = true;
        }

        $mortality = array_fill_keys($labels, 0);
        $feed      = array_fill_keys($labels, 0.0);
        $eggs      = array_fill_keys($labels, 0);

        if (! empty($batchIds)) {
            DailyCheck::whereIn('batch_id', $batchIds)
                ->where('check_date', '>=', $start->toDateString())
                ->select('check_date',
                    DB::raw('SUM(mortality) AS m'),
                    DB::raw('SUM(feed_consumed) AS f'))
                ->groupBy('check_date')
                ->get()
                ->each(function ($row) use (&$mortality, &$feed) {
                    $d = Carbon::parse($row->check_date)->toDateString();
                    if (isset($mortality[$d])) {
                        $mortality[$d] = (int) $row->m;
                        $feed[$d]      = round((float) $row->f, 1);
                    }
                });
        }

        EggProduction::where('production_date', '>=', $start->toDateString())
            ->select('production_date', DB::raw('SUM(total_eggs_collected) AS e'))
            ->groupBy('production_date')
            ->get()
            ->each(function ($row) use (&$eggs) {
                $d = Carbon::parse($row->production_date)->toDateString();
                if (isset($eggs[$d])) {
                    $eggs[$d] = (int) $row->e;
                }
            });

        // Libellés courts (jj/mm) pour l'axe X.
        $shortLabels = array_map(fn ($d) => Carbon::parse($d)->format('d/m'), $labels);

        return [
            'labels'    => $shortLabels,
            'mortality' => array_values($mortality),
            'eggs'      => array_values($eggs),
            'feed'      => array_values($feed),
        ];
    }
}
