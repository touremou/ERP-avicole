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
        $allActiveBatches = Batch::where('status', 'Actif')
            ->where('initial_quantity', '>', 0) 
            ->get();
            
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
        $totalEggsStock = Stock::where('category', 'oeufs')
            ->whereIn('item_name', ['XL', 'L', 'M', 'S'])
            ->sum('current_quantity');

        // Valeur des matières premières (calculée sur le dernier prix d'achat connu ou CMUP)
        // On part du principe que tu as une colonne 'unit_price' ou qu'on la récupère du dernier achat
        $rawMaterialsValue = Stock::where('category', 'conso')->get()->sum(function($item) {
            $lastPurchase = DB::table('feed_purchases')->where('feed_type', $item->item_name)->latest('purchase_date')->first();
            $cmup = $lastPurchase ? $lastPurchase->unit_price : 0;
            return $item->current_quantity * $cmup;
        });

        // ---------------------------------------------------------
        // 4. MARGE NETTE ESTIMÉE DU MOIS (Ventes - Coûts réels)
        // ---------------------------------------------------------
        // A. Chiffre d'Affaires du mois (Sorties magasin x Prix de vente moyen)
        // A. Chiffre d'Affaires du mois (Sorties magasin x Prix de vente moyen)
        $caEstime = DB::table('stock_movements')
            ->join('stocks', 'stock_movements.stock_id', '=', 'stocks.id') // <-- CORRECTION ICI
            ->where('stocks.category', 'oeufs')
            ->where('stock_movements.type', 'out')
            ->whereMonth('stock_movements.created_at', now()->month)
            ->sum(DB::raw('stock_movements.quantity * 30 * 1500')); // Ex: 1500 GNF l'oeuf

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
        //$silos = Stock::where('category', 'conso')->get();
        //$consoJournaliereMoyenne = DailyCheck::where('check_date', '>=', now()->subDays(3))->sum('feed_consumed') / 3;
        $silos = Stock::where('category', 'conso')->get()->filter(function($item) {
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

        // D. Vide Sanitaire
        $sanitaryAlertsCount = Building::where('name', '!=', 'Zone Fournisseurs Externes')
            ->whereDoesntHave('batches', function($q) {
                $q->where('status', 'Actif')->where('initial_quantity', '>', 0);
            })->count();

        // ---------------------------------------------------------
        // 6. DONNÉES D'AFFICHAGE (Bâtiments & Pagination)
        // ---------------------------------------------------------
        $buildings = Building::where('name', '!=', 'Zone Fournisseurs Externes')
            ->withCount(['batches' => function($q) {
                $q->where('status', 'Actif')->where('initial_quantity', '>', 0);
            }])
            ->with(['batches' => function($q) {
                $q->where('status', 'Actif')->where('initial_quantity', '>', 0);
            }])->get();

        $activeBatches = Batch::with(['building', 'dailyChecks' => function($q) {
                $q->latest('check_date');
            }])
            ->where('status', 'Actif')
            ->where('initial_quantity', '>', 0) // 💡 CORRECTION
            ->paginate((int) setting('general.items_per_page', 20));

        // ---------------------------------------------------------
        // 7. WIDGET TABASKI (Eid al-Adha countdown)
        // ---------------------------------------------------------
        $tabaskiWidget = null;
        $hasOvineBatches = Batch::where('status', 'Actif')
            ->whereHas('species', function($q) {
                $q->where('family', 'petit_ruminant');
            })->exists();

        if ($hasOvineBatches) {
            // Dates approchées Eid al-Adha (10 Dhu al-Hijja) pour les prochaines années
            $eidDates = [
                '2026-06-16',
                '2027-06-06',
                '2028-05-26',
                '2029-05-15',
                '2030-05-05',
            ];
            $today = now()->startOfDay();
            $nextEid = null;
            foreach ($eidDates as $date) {
                $eid = Carbon::parse($date)->startOfDay();
                if ($eid->gte($today)) {
                    $nextEid = $eid;
                    break;
                }
            }
            if ($nextEid) {
                $daysUntilEid = (int) $today->diffInDays($nextEid, false);
                $ovineBatchCount = Batch::where('status', 'Actif')
                    ->whereHas('species', function($q) { $q->where('family', 'petit_ruminant'); })
                    ->count();
                $ovineHeadCount = Batch::where('status', 'Actif')
                    ->whereHas('species', function($q) { $q->where('family', 'petit_ruminant'); })
                    ->sum('current_quantity');
                $tabaskiWidget = [
                    'days'        => $daysUntilEid,
                    'date'        => $nextEid->translatedFormat('d F Y'),
                    'batches'     => $ovineBatchCount,
                    'head_count'  => $ovineHeadCount,
                    'urgent'      => $daysUntilEid <= 30,
                    'critical'    => $daysUntilEid <= 7,
                ];
            }
        }

        return view('dashboard', compact(
            'totalBirds', 'globalMortalityRate', 'hdp',
            'totalEggsStock', 'totalBrokenToday', 'rawMaterialsValue', 'safeProfit',
            'criticalTypes', 'emergencyBatches', 'underperformingBatches', 'sanitaryAlertsCount',
            'activeBatches', 'buildings', 'totalEggsToday', 'tabaskiWidget'
        ));
    }
}
