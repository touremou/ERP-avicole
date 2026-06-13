<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Batch;
use App\Models\Stock;
use App\Models\Species;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\HealthCheck;
use App\Models\EggProduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->startOfDay();
        $startOfMonth = now()->startOfMonth();

        // ---------------------------------------------------------
        // 1. EFFECTIFS & MORTALITÉ GLOBALE
        // ---------------------------------------------------------
        $allActiveBatches = Batch::active()
            ->where('initial_quantity', '>', 0)
            ->with(['species', 'productionType'])
            ->get();

        // Les KPI de ponte (HDP, stock calibré) ne sont pertinents que si
        // au moins un lot actif fait l'objet d'un suivi d'œufs. Sinon (ferme
        // 100% ovins/poisson/lapins...) on affiche des KPI génériques.
        $showEggKpis    = $allActiveBatches->contains(fn ($b) => $b->tracksEggs());
        $activeLotsCount = $allActiveBatches->count();

        $totalBirds = $allActiveBatches->sum('current_quantity');
        $totalInitial = $allActiveBatches->sum('initial_quantity');
        
        $totalMortaliteCumulee = $totalInitial - $totalBirds;
        $globalMortalityRate = $totalInitial > 0 ? ($totalMortaliteCumulee / $totalInitial) * 100 : 0;

        // ---------------------------------------------------------
        // 2. TAUX DE PONTE (HDP) DU JOUR
        // ---------------------------------------------------------
        $totalEggsToday = EggProduction::whereDate('production_date', $today)->sum('total_eggs_collected');
        $totalBrokenToday = EggProduction::whereDate('production_date', $today)->sum('broken_eggs');
        
        // HDP calculé uniquement sur les oiseaux encore en vie aujourd'hui
        $hdp = $totalBirds > 0 ? ($totalEggsToday / $totalBirds) * 100 : 0;

        // ---------------------------------------------------------
        // 3. STOCKS & VALORISATION (CMUP)
        // ---------------------------------------------------------
        $totalEggsStock = Stock::where('category', Stock::CAT_OEUFS)
            ->whereIn('item_name', \App\Models\EggProduction::gradeCodes())
            ->sum('current_quantity');

        // Valeur des matières premières (calculée sur le dernier prix d'achat connu ou CMUP)
        // On part du principe que tu as une colonne 'unit_price' ou qu'on la récupère du dernier achat
        $rawMaterialsValue = Stock::where('category', Stock::CAT_CONSO)->get()->sum(function($item) {
            $lastPurchase = DB::table('feed_purchases')->where('feed_type', $item->item_name)->latest('purchase_date')->first();
            $cmup = $lastPurchase ? $lastPurchase->unit_price : 0;
            return $item->current_quantity * $cmup;
        });

        // ---------------------------------------------------------
        // 4. MARGE NETTE ESTIMÉE DU MOIS (Ventes - Coûts réels)
        // ---------------------------------------------------------
        // A. Chiffre d'affaires réel du mois : ventes validées/livrées (toutes
        //    catégories & espèces) + lait collecté valorisé. Remplace l'ancienne
        //    estimation œufs-seulement à prix figé, désormais incohérente avec
        //    la vente d'animaux vifs et le lait.
        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();

        $caVentes = (float) \App\Models\Sale::whereIn('status', ['valide', 'livre'])
            ->whereBetween('sale_date', [$monthStart, $monthEnd])
            ->sum('total_amount');

        $caLait = (float) \App\Models\MilkProduction::whereBetween('production_date', [$monthStart, $monthEnd])
            ->sum(DB::raw('total_liters * unit_price'));

        $caEstime = $caVentes + $caLait;

        // B. Coût Alimentaire du mois (Quantité consommée * CMUP)
        $coutAliment = DailyCheck::whereMonth('check_date', now()->month)
            ->get()
            ->sum(function($check) {
                // Simplification : on applique un CMUP moyen global pour l'estimation rapide du dashboard
                return $check->feed_consumed * 4500; // Ex: 4500 GNF/kg (à lier à ton vrai CMUP si possible)
            });

        // C. Coûts Santé du mois
        $coutSante = HealthCheck::whereMonth('intervention_date', now()->month)->sum('cost');

        $safeProfit = $caEstime - ($coutAliment + $coutSante);

        // ---------------------------------------------------------
        // 5. GESTION DES ALERTES (Centre de contrôle)
        // ---------------------------------------------------------
        
        // A. Autonomie Silos (< 3 jours)
        $criticalTypes = [];
        //$silos = Stock::where('category', Stock::CAT_CONSO)->get();
        //$consoJournaliereMoyenne = DailyCheck::where('check_date', '>=', now()->subDays(3))->sum('feed_consumed') / 3;
        $silos = Stock::where('category', Stock::CAT_CONSO)->get()->filter(function($item) {
            return ($item->metadata['conso_type'] ?? 'Aliment') === 'Aliment';
        });
        
        $consoJournaliereMoyenne = DailyCheck::where('check_date', '>=', now()->subDays(3))->sum('feed_consumed') / 3;
        
        if ($consoJournaliereMoyenne > 0) {
            foreach($silos as $silo) {
                // On évite les erreurs sur les stocks désactivés ou à 0
                if ($silo->current_quantity <= 0) {
                    $criticalTypes[] = ['type' => $silo->item_name, 'days' => 0];
                    continue;
                }

                $joursRestants = floor($silo->current_quantity / $consoJournaliereMoyenne);
                if ($joursRestants <= 3) {
                    $criticalTypes[] = ['type' => $silo->item_name, 'days' => $joursRestants];
                }
            }
        }

        // B. Urgences Sanitaires (Mortalité soudaine > 0.5% en 1 jour)
        $emergencyBatches = $allActiveBatches->filter(function($batch) use ($today) {
            $todayCheck = $batch->dailyChecks()->whereDate('check_date', $today)->first();
            if (!$todayCheck || $batch->current_quantity == 0) return false;
            $tauxJour = ($todayCheck->mortality / $batch->current_quantity) * 100;
            return $tauxJour > 0.5; // Alerte si plus de 0.5% du cheptel meurt en 24h
        });

        // C. Dérive Technique (Mortalité cumulée > 5%)
        $underperformingBatches = $allActiveBatches->filter(function($batch) {
            $taux = $batch->initial_quantity > 0 ? (($batch->initial_quantity - $batch->current_quantity) / $batch->initial_quantity) * 100 : 0;
            return $taux > 5;
        });

        // D. Vide Sanitaire dépassé — uniquement les bâtiments toujours "En
        // désinfection" alors que le délai réglementaire de 14 jours
        // (cf. Building::getSanitaryBreakRemainingDaysAttribute) est écoulé.
        // Un bâtiment simplement vide/disponible, ou en désinfection depuis
        // moins de 14 jours, n'est PAS une alerte.
        $sanitaryAlertsCount = Building::where('name', '!=', 'Zone Fournisseurs Externes')
            ->where('status', 'En désinfection')
            ->whereNotNull('disinfection_started_at')
            ->where('disinfection_started_at', '<=', now()->subDays(14))
            ->count();

        // ---------------------------------------------------------
        // 6. DONNÉES D'AFFICHAGE (Bâtiments & Pagination)
        // ---------------------------------------------------------
        $buildings = Building::where('name', '!=', 'Zone Fournisseurs Externes')
            ->withCount(['batches' => function($q) {
                $q->active()->where('initial_quantity', '>', 0);
            }])
            ->with(['batches' => function($q) {
                $q->active()->where('initial_quantity', '>', 0);
            }])->get();

        $occupiedBuildingsCount = $buildings->where('batches_count', '>', 0)->count();
        $totalBuildingsCount    = $buildings->count();

        $activeBatches = Batch::with(['building', 'dailyChecks' => function($q) {
                $q->latest('check_date');
            }])
            ->active()
            ->where('initial_quantity', '>', 0) // 💡 CORRECTION
            ->paginate((int) setting('general.items_per_page', 20));

        // ---------------------------------------------------------
        // 7. WIDGET CAMPAGNE TABASKI
        // ---------------------------------------------------------
        // Basé sur une véritable campagne Tabaski active (cf. module
        // Campagnes), pas seulement sur la présence d'ovins/caprins —
        // sinon le widget s'affiche même quand aucune campagne n'a été
        // planifiée (fausse alerte).
        $tabaskiWidget = null;
        $tabaskiCampaign = \App\Models\Campaign::active()
            ->where('type', 'tabaski')
            ->with('batches')
            ->orderBy('target_date')
            ->first();

        if ($tabaskiCampaign) {
            $today = now()->startOfDay();
            $targetDate = Carbon::parse($tabaskiCampaign->target_date)->startOfDay();
            $daysUntilTarget = (int) $today->diffInDays($targetDate, false);

            $tabaskiWidget = [
                'campaign_id' => $tabaskiCampaign->id,
                'days'        => $daysUntilTarget,
                'date'        => $targetDate->translatedFormat('d F Y'),
                'batches'     => $tabaskiCampaign->batches->count(),
                'head_count'  => $tabaskiCampaign->head_count,
                'urgent'      => $daysUntilTarget >= 0 && $daysUntilTarget <= 30,
                'critical'    => $daysUntilTarget >= 0 && $daysUntilTarget <= 7,
            ];
        }

        // ---------------------------------------------------------
        // 8. ALERTES QUALITÉ EAU (Pisciculture)
        // ---------------------------------------------------------
        $waterAlerts = collect();
        $aquaBatches = Batch::active()
            ->whereHas('species', function($q) {
                $q->where('family', 'aquaculture');
            })->with(['species', 'building'])->get();

        foreach ($aquaBatches as $aquaBatch) {
            $lastExt = \App\Models\DailyCheck::where('batch_id', $aquaBatch->id)
                ->whereHas('extension', function($q) {
                    $q->whereNotNull('water_ph')->orWhereNotNull('water_o2_ppm');
                })
                ->with('extension')
                ->latest('check_date')
                ->first()?->extension;

            if ($lastExt) {
                $alerts = $lastExt->getWaterAlerts();
                if (!empty($alerts)) {
                    $waterAlerts->push([
                        'batch'   => $aquaBatch,
                        'alerts'  => $alerts,
                        'has_critical' => collect($alerts)->contains('level', 'critical'),
                    ]);
                }
            }
        }

        // ---------------------------------------------------------
        // 9. RÉPARTITION DES EFFECTIFS PAR FAMILLE D'ESPÈCE
        // ---------------------------------------------------------
        $familyMeta = [
            'volaille'        => ['label' => 'Volaille',         'icon' => '🐔', 'color' => 'amber'],
            'petit_ruminant'  => ['label' => 'Ovins / Caprins',  'icon' => '🐑', 'color' => 'cyan'],
            'grand_ruminant'  => ['label' => 'Bovins',           'icon' => '🐄', 'color' => 'indigo'],
            'aquaculture'     => ['label' => 'Pisciculture',     'icon' => '🐟', 'color' => 'blue'],
            'porcin'          => ['label' => 'Porcins',          'icon' => '🐷', 'color' => 'rose'],
            'lagomorphe'      => ['label' => 'Lapins',           'icon' => '🐰', 'color' => 'purple'],
            'autre'           => ['label' => 'Autres',           'icon' => '🐾', 'color' => 'slate'],
        ];

        $familyBreakdown = $allActiveBatches
            ->groupBy(fn ($b) => $b->species?->family ?? 'volaille')
            ->map(function ($batches, $family) use ($familyMeta) {
                $meta = $familyMeta[$family] ?? ['label' => ucfirst($family), 'icon' => '🐾', 'color' => 'slate'];
                return [
                    'family'     => $family,
                    'label'      => $meta['label'],
                    'icon'       => $meta['icon'],
                    'color'      => $meta['color'],
                    'batches'    => $batches->count(),
                    'head_count' => $batches->sum('current_quantity'),
                ];
            })
            ->sortByDesc('head_count')
            ->values();

        return view('dashboard', compact(
            'totalBirds', 'globalMortalityRate', 'hdp',
            'totalEggsStock', 'totalBrokenToday', 'rawMaterialsValue', 'safeProfit',
            'criticalTypes', 'emergencyBatches', 'underperformingBatches', 'sanitaryAlertsCount',
            'activeBatches', 'buildings', 'totalEggsToday', 'tabaskiWidget', 'waterAlerts',
            'familyBreakdown', 'showEggKpis', 'activeLotsCount',
            'occupiedBuildingsCount', 'totalBuildingsCount'
        ));
    }
}
