<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Batch;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Species;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\HealthCheck;
use App\Models\EggProduction;
use App\Models\Plot;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\CropTransformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->startOfDay();
        $startOfMonth = now()->startOfMonth();

        // ─────────────────────────────────────────────────────────
        // CLOISONNEMENT RBAC : la donnée d'un module n'est NI calculée NI
        // transmise à la vue sans la permission Lecture du module. La vue
        // masque (confort) ; ICI on refuse (sécurité) — un vendeur ne
        // déclenche même pas les requêtes SQL d'élevage.
        // ─────────────────────────────────────────────────────────
        $canElevage    = \Illuminate\Support\Facades\Gate::allows('elevage.L');
        $canProduction = \Illuminate\Support\Facades\Gate::allows('production.L');
        $canLogistique = \Illuminate\Support\Facades\Gate::allows('logistique.L');
        $canCommerce   = \Illuminate\Support\Facades\Gate::allows('commerce.L');
        $canCultures   = \Illuminate\Support\Facades\Gate::allows('cultures.L');

        // ---------------------------------------------------------
        // 1. EFFECTIFS & MORTALITÉ GLOBALE (elevage.L — socle partagé
        //    avec les KPI de ponte, donc calculé aussi pour production.L)
        // ---------------------------------------------------------
        // ->live() exclut les lots virtuels (œufs externes, initial_quantity=0) :
        // scope canonique partagé par tous les calculs ci-dessous (cohérence).
        $allActiveBatches = ($canElevage || $canProduction)
            ? Batch::active()->live()->with(['species', 'productionType'])->get()
            : collect();

        // Les KPI de ponte (HDP, stock calibré) ne sont pertinents que si
        // au moins un lot actif fait l'objet d'un suivi d'œufs. Sinon (ferme
        // 100% ovins/poisson/lapins...) on affiche des KPI génériques.
        $showEggKpis    = $canProduction && $allActiveBatches->contains(fn ($b) => $b->tracksEggs());
        $activeLotsCount = $allActiveBatches->count();

        $totalBirds = $canElevage ? $allActiveBatches->sum('current_quantity') : 0;
        $totalInitial = $allActiveBatches->sum('initial_quantity');

        // Mortalité réelle = morts d'arrivage (qty_dead) + mortalité d'élevage
        // (Σ daily_checks.mortality). L'ancien calcul (initial − current) était
        // FAUX : current_quantity intègre aussi les quarantaines et les tris/
        // ventes, qui ne sont pas des morts. La base inclut qty_dead, cohérente
        // avec Batch::getMortalityRateAttribute().
        $batchIds = $allActiveBatches->pluck('id');
        $globalMortalityRate = 0;
        if ($canElevage) {
            $totalQtyDead = (int) $allActiveBatches->sum('qty_dead');
            $totalElevageMortality = (int) DailyCheck::whereIn('batch_id', $batchIds)->sum('mortality');
            $totalMortaliteCumulee = $totalQtyDead + $totalElevageMortality;
            $mortalityBase = $totalInitial + $totalQtyDead;
            $globalMortalityRate = $mortalityBase > 0 ? ($totalMortaliteCumulee / $mortalityBase) * 100 : 0;
        }

        // ---------------------------------------------------------
        // 2. TAUX DE PONTE (HDP) DU JOUR (production.L)
        // ---------------------------------------------------------
        $totalEggsToday = $canProduction ? EggProduction::whereDate('production_date', $today)->sum('total_eggs_collected') : 0;
        $totalBrokenToday = $canProduction ? EggProduction::whereDate('production_date', $today)->sum('broken_eggs') : 0;

        // HDP (Hen-Day Production) : œufs du jour rapportés au SEUL effectif des
        // lots en ponte. L'ancien calcul diluait par TOUT le cheptel (chair,
        // poussinières incluses), écrasant artificiellement le taux d'une ferme
        // mixte. On se base sur l'effectif des lots assurant un suivi d'œufs.
        $hdp = 0;
        if ($canProduction) {
            $layingBirds = $allActiveBatches
                ->filter(fn ($b) => $b->tracksEggs())
                ->sum('current_quantity');
            $hdp = $layingBirds > 0 ? ($totalEggsToday / $layingBirds) * 100 : 0;
        }

        // ---------------------------------------------------------
        // 3. STOCKS & VALORISATION (CMUP) — production.L / logistique.L
        // ---------------------------------------------------------
        $totalEggsStock = $canProduction
            ? Stock::where('category', Stock::CAT_OEUFS)
                ->whereIn('item_name', \App\Models\EggProduction::gradeCodes())
                ->sum('current_quantity')
            : 0;

        // Valorisation du STOCK GLOBAL au coût moyen pondéré (CMUP) porté par
        // l'article (last_unit_price, mis à jour à chaque achat/production —
        // cf. StockIntegrationService). Toutes catégories confondues — l'ancien
        // KPI, étiqueté « Matières premières », ne valorisait en réalité que la
        // catégorie « Aliment & Santé » (CAT_CONSO). La ventilation par
        // catégorie alimente l'info-bulle du KPI.
        $stockValueByCategory = $canLogistique
            ? Stock::query()
                ->selectRaw('category, COALESCE(SUM(current_quantity * COALESCE(last_unit_price, 0)), 0) AS v')
                ->groupBy('category')
                ->pluck('v', 'category')
                ->map(fn ($v) => (float) $v)
                ->filter(fn ($v) => $v > 0)
                ->sortDesc()
            : collect();
        $stockValue = (float) $stockValueByCategory->sum();

        // ---------------------------------------------------------
        // 4. SYNTHÈSE FINANCIÈRE DU MOIS (source unique : service)
        // ---------------------------------------------------------
        // Chiffre d'affaires (ventes validées + lait) − charges réelles du mois
        // (aliment au coût de revient, santé, dépenses validées ventilées). La
        // marge nette inclut désormais les dépenses validées (carburant, main
        // d'œuvre…), pas seulement aliment + santé. Trésorerie = encours clients.
        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();

        $insights  = new \App\Services\DashboardInsightsService();
        // commerce.L : CA, marge, créances — jamais exposés à un rôle terrain.
        $financial = $canCommerce ? $insights->financial($monthStart, $monthEnd) : null;

        $safeProfit     = $financial['net_margin'] ?? 0;
        $encoursClients = $financial['receivables'] ?? 0;

        // ---------------------------------------------------------
        // 5. GESTION DES ALERTES (Centre de contrôle)
        // ---------------------------------------------------------
        
        // A. Autonomie Silos (< 3 jours) — calcul PAR silo (cohérent multiespèce)
        //
        // L'autonomie d'un silo doit se baser sur SA PROPRE consommation
        // (ses sorties de stock), et non sur la consommation globale de la
        // ferme : sinon chaque silo paraît se vider au rythme de tous les
        // autres réunis et déclenche de fausses alertes « épuisé ».
        // Seuils paramétrables (Réglages) — valeurs par défaut conservées.
        $periodDays            = (int) setting('stocks.autonomy_period_days', 30);
        $criticalDaysThreshold = (int) setting('stocks.critical_days_threshold', 3);
        $dailyMortalityPct     = (float) setting('elevage.daily_mortality_alert_pct', 0.5);
        // Plancher absolu : un décès isolé sur un petit lot dépasse mécaniquement
        // le seuil en % (ex. 1/195 = 0,51 % > 0,5 %) sans constituer un vrai pic.
        // On exige donc un minimum de morts en valeur absolue AVANT d'évaluer le %.
        $dailyMortalityMin     = (int) setting('elevage.daily_mortality_alert_min', 3);
        $cumulMortalityPct     = \App\Models\Batch::cumulativeMortalityThreshold();
        $sanitaryDays          = (int) setting('elevage.sanitary_break_days', Building::SANITARY_BREAK_DAYS);
        $protocolWindowDays    = (int) setting('elevage.protocol_overdue_window_days', 30);

        $criticalTypes = [];
        // logistique.L : autonomie silos & angles morts d'alimentation.
        $silos = $canLogistique
            ? Stock::where('category', Stock::CAT_CONSO)->get()->filter(function($item) {
                return ($item->metadata['conso_type'] ?? 'Aliment') === 'Aliment';
            })
            : collect();

        foreach ($silos as $silo) {
            // Silo vide → épuisé.
            if ($silo->current_quantity <= 0) {
                $criticalTypes[] = ['type' => $silo->item_name, 'days' => 0];
                continue;
            }

            // Consommation journalière propre à CE silo : moyenne des sorties
            // sur les 30 derniers jours (même unité que current_quantity).
            $totalOut = (float) \App\Models\StockMovement::where('stock_id', $silo->id)
                ->where('type', 'out')
                ->where('created_at', '>=', now()->subDays($periodDays))
                ->sum('quantity');
            $consoJournaliere = $totalOut / $periodDays;

            // Du stock mais aucune sortie récente : on ne peut pas calculer
            // l'autonomie. Si des lots actifs consomment cet aliment, c'est
            // louche (les sorties ne sont peut-être pas enregistrées) — on
            // l'alerte avec days = -2 (données insuffisantes). Sinon on passe.
            if ($consoJournaliere <= 0) {
                $isConsumedByActiveBatch = DailyCheck::whereIn('batch_id', $batchIds)
                    ->where('check_date', '>=', now()->subDays($periodDays))
                    ->where('feed_type', $silo->item_name)
                    ->where('feed_consumed', '>', 0)
                    ->exists();

                if ($isConsumedByActiveBatch) {
                    $criticalTypes[] = ['type' => $silo->item_name, 'days' => -2];
                }
                continue;
            }

            $joursRestants = (int) floor($silo->current_quantity / $consoJournaliere);
            if ($joursRestants <= $criticalDaysThreshold) {
                $criticalTypes[] = ['type' => $silo->item_name, 'days' => $joursRestants];
            }
        }

        // Angle mort : lots actifs consommant un aliment sans article de stock
        // correspondant. Le silo n'existe pas → boucle précédente ne le voit
        // pas → "tout va bien" affiché à tort. On alerte avec days = -1.
        $activeFeedTypes = $canLogistique
            ? DailyCheck::whereIn('batch_id', $batchIds)
                ->where('check_date', '>=', now()->subDays($periodDays))
                ->whereNotNull('feed_type')
                ->where('feed_consumed', '>', 0)
                ->distinct()
                ->pluck('feed_type')
            : collect();

        $configuredFeedNames = $silos->pluck('item_name');
        foreach ($activeFeedTypes as $feedType) {
            if (! $configuredFeedNames->contains($feedType)) {
                $criticalTypes[] = ['type' => $feedType, 'days' => -1];
            }
        }

        // B. Urgences Sanitaires (pic de mortalité du jour > seuil paramétré).
        // Base = effectif de DÉBUT de journée (effectif courant + morts du jour,
        // déjà décomptés par l'observer) pour ne pas surévaluer le taux.
        $emergencyBatches = (! $canElevage ? collect() : $allActiveBatches)->filter(function($batch) use ($today, $dailyMortalityPct, $dailyMortalityMin) {
            $todayCheck = $batch->dailyChecks()->whereDate('check_date', $today)->first();
            if (!$todayCheck) return false;
            $morts = (int) $todayCheck->mortality;
            // Plancher absolu : sous ce nombre de morts, pas de pic (bruit de petit lot).
            if ($morts < $dailyMortalityMin) return false;
            $base = (int) $batch->current_quantity + $morts;
            if ($base <= 0) return false;
            $tauxJour = ($morts / $base) * 100;
            return $tauxJour > $dailyMortalityPct;
        });

        // C. Dérive Technique (mortalité CUMULÉE réelle > seuil paramétré).
        // S'appuie sur l'accessor mortality_rate (qty_dead + Σ mortalité / base
        // initiale), et non sur initial − current qui mêlait tris & quarantaines.
        $underperformingBatches = (! $canElevage ? collect() : $allActiveBatches)->filter(function($batch) use ($cumulMortalityPct) {
            return $batch->mortality_rate > $cumulMortalityPct;
        });

        // D. Vide Sanitaire dépassé — uniquement les bâtiments toujours "En
        // désinfection" alors que le délai réglementaire de 14 jours
        // (cf. Building::getSanitaryBreakRemainingDaysAttribute) est écoulé.
        // Un bâtiment simplement vide/disponible, ou en désinfection depuis
        // moins de 14 jours, n'est PAS une alerte.
        $sanitaryAlertsCount = $canElevage
            ? Building::where('name', '!=', 'Zone Fournisseurs Externes')
                ->inSanitaryBreak()
                ->whereNotNull('disinfection_started_at')
                ->where('disinfection_started_at', '<=', now()->subDays($sanitaryDays))
                ->count()
            : 0;

        // E. Stock sous le seuil de réapprovisionnement (alert_threshold).
        // Indépendant de l'autonomie silos (qui ne couvre que l'aliment et la
        // vitesse de consommation) : ici on alerte sur TOUT article passé sous
        // son seuil, y compris à consommation lente (vaccins, litière, matériel).
        $lowStocks = $canLogistique
            ? Stock::where('alert_threshold', '>', 0)
                ->whereColumn('current_quantity', '<=', 'alert_threshold')
                ->orderByRaw('current_quantity / NULLIF(alert_threshold, 0) ASC')
                ->get(['id', 'item_name', 'category', 'current_quantity', 'alert_threshold', 'unit'])
            : collect();

        // Péremption des consommables (vaccins, médicaments, intrants…) :
        // articles déjà périmés OU périmant dans la fenêtre d'alerte configurée.
        $expiryWindow = (int) setting('stocks.expiry_alert_days', 30);
        $expiringStocks = $canLogistique
            ? Stock::where(function ($q) use ($expiryWindow) {
                    $q->expired()->orWhere(fn ($q2) => $q2->expiringSoon($expiryWindow));
                })
                ->orderBy('expiry_date')
                ->get(['id', 'item_name', 'category', 'current_quantity', 'unit', 'expiry_date', 'lot_number'])
            : collect();

        // F. Prophylaxie en retard : étapes de protocole échues mais non tracées.
        // Réutilise EXACTEMENT la convention de la fiche lot (date prévue =
        // date de réf. + day_number ; « fait » si un acte sanitaire porte le nom
        // de l'étape). Bornée aux échéances des $protocolWindowDays derniers
        // jours pour rester actionnable et éviter le bruit d'anciens lots.
        $vaccineAlerts = collect();
        $protocolBatches = $canElevage
            ? Batch::active()->live()
                ->whereNotNull('protocol_id')
                ->with(['protocol.steps', 'healthChecks'])
                ->get()
            : collect();

        foreach ($protocolBatches as $batch) {
            if (! $batch->protocol) continue;
            $refDate = Carbon::parse($batch->transfer_date ?? $batch->start_date ?? $batch->arrival_date)->startOfDay();
            $overdue = [];

            foreach ($batch->protocol->steps as $step) {
                $dueDate = $refDate->copy()->addDays((int) $step->day_number);
                if (! $dueDate->isPast()) continue;                       // pas encore échue
                if ($dueDate->lt(now()->subDays($protocolWindowDays))) continue; // trop ancienne

                $done = $batch->healthChecks->contains(
                    fn ($h) => $h->product_name
                        && str_contains(strtolower($h->product_name), strtolower($step->action_name))
                );

                if (! $done) {
                    $overdue[] = ['action' => $step->action_name, 'due' => $dueDate];
                }
            }

            if (! empty($overdue)) {
                $vaccineAlerts->push([
                    'batch'   => $batch,
                    'count'   => count($overdue),
                    'next'    => $overdue[0]['action'],
                    'overdue' => $overdue,
                ]);
            }
        }

        // G. Bien-être animal : taux de boiterie / picage du dernier pointage
        // récent au-delà des seuils paramétrés. Indicateurs préventifs (les
        // sujets sont vivants) — alerte avant que cela ne dégénère en mortalité.
        $welfareWindow = (int) setting('elevage.welfare_window_days', 7);
        $lamenessPct   = (float) setting('elevage.lameness_alert_pct', 5);
        $peckingPct    = (float) setting('elevage.pecking_alert_pct', 2);

        $welfareAlerts = ($canElevage ? Batch::active()->live()->with('latestDailyCheck')->get() : collect())
            ->map(function ($batch) use ($welfareWindow, $lamenessPct, $peckingPct) {
                $check = $batch->latestDailyCheck;
                if (! $check || (int) $batch->current_quantity <= 0) return null;
                if (Carbon::parse($check->check_date)->lt(now()->subDays($welfareWindow))) return null;

                $eff   = (int) $batch->current_quantity;
                $lame  = (int) ($check->lame_count ?? 0);
                $peck  = (int) ($check->pecking_injury_count ?? 0);
                $issues = [];

                if ($lame > 0 && ($lame / $eff) * 100 > $lamenessPct) {
                    $issues[] = ['type' => 'Boiterie', 'pct' => round(($lame / $eff) * 100, 1)];
                }
                if ($peck > 0 && ($peck / $eff) * 100 > $peckingPct) {
                    $issues[] = ['type' => 'Picage', 'pct' => round(($peck / $eff) * 100, 1)];
                }

                return $issues ? ['batch' => $batch, 'issues' => $issues] : null;
            })
            ->filter()
            ->values();

        // ---------------------------------------------------------
        // 6. DONNÉES D'AFFICHAGE (Bâtiments & Pagination)
        // ---------------------------------------------------------
        $buildings = $canElevage
            ? Building::where('name', '!=', 'Zone Fournisseurs Externes')
                ->withCount(['batches' => function($q) {
                    $q->active()->live();
                }])
                ->with(['batches' => function($q) {
                    $q->active()->live();
                }])->get()
            : collect();

        $occupiedBuildingsCount = $buildings->where('batches_count', '>', 0)->count();
        $totalBuildingsCount    = $buildings->count();

        // N'eager-load QUE le dernier pointage (relation latestDailyCheck) au
        // lieu de tout l'historique : évite de charger des centaines de lignes
        // par lot alors que la vue n'affiche que le dernier poids.
        $activeBatches = Batch::with(['building', 'latestDailyCheck'])
            ->active()
            ->live()
            ->when(! $canElevage, fn ($q) => $q->whereRaw('1 = 0')) // paginator vide hors elevage.L
            ->paginate((int) setting('general.items_per_page', 20));

        // ---------------------------------------------------------
        // 7. WIDGET CAMPAGNE TABASKI
        // ---------------------------------------------------------
        // Basé sur une véritable campagne Tabaski active (cf. module
        // Campagnes), pas seulement sur la présence d'ovins/caprins —
        // sinon le widget s'affiche même quand aucune campagne n'a été
        // planifiée (fausse alerte).
        $tabaskiWidget = null;
        $tabaskiCampaign = $canElevage
            ? \App\Models\Campaign::active()
                ->where('type', 'tabaski')
                ->with('batches')
                ->orderBy('target_date')
                ->first()
            : null;

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
        $aquaBatches = $canElevage
            ? Batch::active()
                ->live()
                ->whereHas('species', function($q) {
                    $q->where('family', 'aquaculture');
                })->with(['species', 'building'])->get()
            : collect();

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

        // ---------------------------------------------------------
        // 10. PRODUCTION VÉGÉTALE (ferme intégrée)
        // ---------------------------------------------------------
        // Bloc affiché uniquement si l'exploitation gère des parcelles, pour ne
        // pas encombrer le tableau de bord d'une ferme 100% élevage.
        $plantProduction = null;
        if ($canCultures && Plot::exists()) {
            $plantProduction = [
                'cycles_active'   => CropCycle::inProgress()->count(),
                'area_cultivated' => (float) CropCycle::inProgress()->sum('area_used_ha'),
                'harvest_ytd'     => (float) Harvest::whereYear('harvest_date', now()->year)->sum('quantity'),
                'transform_30d'   => CropTransformation::where('production_date', '>=', now()->subDays(30))->count(),
                'due_soon'        => CropCycle::dueForHarvest(7)->count(),
            ];
        }

        // ---------------------------------------------------------
        // 11. ENRICHISSEMENT INDUSTRIEL (KPI techniques + tendances)
        // ---------------------------------------------------------
        $technical = $canElevage ? $insights->technical($allActiveBatches, $globalMortalityRate) : null;
        $trends    = $canElevage ? $insights->trends($batchIds->all(), 30) : null;

        // ---------------------------------------------------------
        // 12. BANDEAU D'ALERTES PRIORISÉ (centre de contrôle unifié)
        // ---------------------------------------------------------
        // Consolide toutes les alertes déjà calculées en une liste unique triée
        // par criticité (critique > attention > info), avec lien d'action.
        $priorityAlerts = $this->buildPriorityAlerts(
            $emergencyBatches, $underperformingBatches, $waterAlerts,
            $criticalTypes, $lowStocks, $vaccineAlerts, $welfareAlerts, $sanitaryAlertsCount
        );

        return view('dashboard', compact(
            'totalBirds', 'globalMortalityRate', 'hdp', 'plantProduction',
            'totalEggsStock', 'totalBrokenToday', 'stockValue', 'stockValueByCategory', 'safeProfit',
            'encoursClients', 'financial', 'technical', 'trends', 'priorityAlerts',
            'criticalTypes', 'emergencyBatches', 'underperformingBatches', 'sanitaryAlertsCount',
            'lowStocks', 'expiringStocks', 'vaccineAlerts', 'welfareAlerts', 'criticalDaysThreshold',
            'activeBatches', 'buildings', 'totalEggsToday', 'tabaskiWidget', 'waterAlerts',
            'familyBreakdown', 'showEggKpis', 'activeLotsCount',
            'occupiedBuildingsCount', 'totalBuildingsCount'
        ));
    }

    /**
     * Vue analytique CONSOLIDÉE : mortalité + eau + énergie sur une même échelle
     * de temps, pour repérer les corrélations (coupure énergie/ventilation → pic
     * de mortalité, chute d'eau → maladie) sans naviguer entre les modules.
     */
    public function analytics(Request $request)
    {
        // Séries mortalité/eau/énergie = données d'élevage : lecture requise.
        \Illuminate\Support\Facades\Gate::authorize('elevage.L');

        $days = (int) $request->input('days', 30);
        $days = max(7, min(90, $days)); // borné 7-90 j

        $batchIds = Batch::active()->live()->pluck('id')->all();

        $series = (new \App\Services\DashboardInsightsService())
            ->consolidatedTrends($batchIds, $days);

        // Synthèse + corrélation simple : le jour de mortalité maximale et ce qui
        // s'y passait côté eau/énergie (lecture immédiate pour le pilotage).
        $totalMortality = array_sum($series['mortality']);
        $totalWater     = array_sum($series['water']);
        $totalEnergy    = array_sum($series['energy']);

        $peakIdx = $series['mortality'] ? array_keys($series['mortality'], max($series['mortality']))[0] : null;
        $peak = ($peakIdx !== null && max($series['mortality']) > 0) ? [
            'date'      => $series['labels'][$peakIdx],
            'mortality' => $series['mortality'][$peakIdx],
            'water'     => $series['water'][$peakIdx],
            'energy'    => $series['energy'][$peakIdx],
        ] : null;

        return view('dashboard-analytics', compact('series', 'days', 'totalMortality', 'totalWater', 'totalEnergy', 'peak'));
    }

    /**
     * Assemble les alertes éparses du tableau de bord en une liste unique triée
     * par criticité, pour un bandeau « centre de contrôle » actionnable.
     *
     * @return \Illuminate\Support\Collection<int, array{rank:int, level:string, icon:string, title:string, detail:string, url:?string}>
     */
    private function buildPriorityAlerts(
        $emergencyBatches, $underperformingBatches, $waterAlerts,
        array $criticalTypes, $lowStocks, $vaccineAlerts, $welfareAlerts, int $sanitaryAlertsCount
    ) {
        $rank = ['critique' => 0, 'attention' => 1, 'info' => 2];
        $out = collect();

        // Lots sous QUARANTAINE sanitaire (critique) : vente, mutation,
        // collecte et abattage gelés — le pilote doit le voir en premier.
        $quarantined = Batch::active()->live()
            ->whereHas('healthIncidents', fn ($q) => $q
                ->where('is_quarantined', true)
                ->where('status', '!=', \App\Models\HealthIncident::STATUS_RESOLVED))
            ->get(['id', 'code']);
        foreach ($quarantined as $b) {
            $out->push([
                'level' => 'critique', 'icon' => 'fa-biohazard',
                'title' => 'Quarantaine sanitaire',
                'detail' => "Lot {$b->code} — vente, mutation, collecte et abattage suspendus.",
                'url' => route('batches.show', $b->id),
            ]);
        }

        // RESSOURCES (eau / gasoil / maintenance) : le centre de contrôle voit
        // les utilités sans ouvrir le module — une citerne vide ou un groupe
        // à sec arrête la ferme aussi sûrement qu'un silo d'aliment épuisé.
        // Le bandeau n'escalade que le niveau « critique » (cf. return) : les
        // niveaux intermédiaires restent dans le dashboard Ressources.
        foreach (\App\Models\WaterSource::active()->where('type', 'citerne')->get() as $ws) {
            $pct = (float) ($ws->current_level_percent ?? 0);
            if (! $ws->is_low || $pct >= 15) {
                continue; // 15-30 % : visible au module Ressources, pas une urgence hub
            }
            $out->push([
                'level' => 'critique', 'icon' => 'fa-faucet-drip',
                'title' => 'Citerne d\'eau critique',
                'detail' => "{$ws->name} — " . number_format($pct, 0) . ' % ('
                    . number_format((float) $ws->current_level_liters, 0, ',', ' ') . ' L restants). Remplissage immédiat.',
                'url' => route('utilities.water.sources'),
            ]);
        }

        foreach (\App\Models\EnergySource::active()->groupes()->get() as $es) {
            if ($es->is_fuel_low) {
                $autonomy = $es->fuel_autonomy_hours !== null
                    ? "≈ {$es->fuel_autonomy_hours} h de marche"
                    : "≈ {$es->fuel_autonomy_days} j";
                $out->push([
                    'level' => 'critique', 'icon' => 'fa-gas-pump',
                    'title' => 'Gasoil groupe critique',
                    'detail' => "{$es->name} — " . number_format((float) $es->current_fuel_level, 0) . " L en cuve ({$autonomy}). Commander du carburant.",
                    'url' => route('utilities.fuel.index'),
                ]);
            }

            // ≤ 20 h avant révision : un groupe non entretenu qui lâche coupe
            // pompes/éclairage — urgence réelle (double canal : une tâche
            // auto est déjà générée par maintenance:check).
            if ($es->needs_maintenance) {
                $out->push([
                    'level' => 'critique', 'icon' => 'fa-screwdriver-wrench',
                    'title' => 'Maintenance groupe due',
                    'detail' => "{$es->name} — " . round($es->hours_before_maintenance) . ' h avant révision (huile, filtres, courroies).',
                    'url' => route('utilities.energy.sources'),
                ]);
            }
        }

        // Urgences mortalité (critique).
        foreach ($emergencyBatches as $b) {
            $out->push([
                'level' => 'critique', 'icon' => 'fa-heart-pulse',
                'title' => 'Pic de mortalité',
                'detail' => "Lot {$b->code} — contrôle sanitaire immédiat requis.",
                'url' => route('batches.show', $b->id),
            ]);
        }

        // Qualité d'eau aquaculture.
        foreach ($waterAlerts as $w) {
            $out->push([
                'level' => $w['has_critical'] ? 'critique' : 'attention', 'icon' => 'fa-droplet',
                'title' => 'Qualité de l\'eau',
                'detail' => "Bassin {$w['batch']->code} — paramètres hors normes.",
                'url' => route('batches.show', $w['batch']->id),
            ]);
        }

        // Silos d'aliment.
        foreach ($criticalTypes as $c) {
            $detail = match (true) {
                $c['days'] === -1 => "{$c['type']} — consommé mais aucun stock enregistré.",
                $c['days'] === -2 => "{$c['type']} — stock présent mais aucune sortie tracée.",
                $c['days'] === 0  => "{$c['type']} — silo épuisé.",
                default           => "{$c['type']} — {$c['days']} j d'autonomie restante.",
            };
            $out->push([
                'level' => $c['days'] <= 0 ? 'critique' : 'attention', 'icon' => 'fa-wheat-awn',
                'title' => 'Autonomie aliment', 'detail' => $detail,
                'url' => route('stocks.index'),
            ]);
        }

        // Dérive technique (mortalité cumulée).
        foreach ($underperformingBatches as $b) {
            $out->push([
                'level' => 'attention', 'icon' => 'fa-chart-line',
                'title' => 'Dérive technique',
                'detail' => "Lot {$b->code} — mortalité cumulée au-dessus du seuil.",
                'url' => route('batches.show', $b->id),
            ]);
        }

        // Prophylaxie en retard.
        foreach ($vaccineAlerts as $v) {
            $out->push([
                'level' => 'attention', 'icon' => 'fa-syringe',
                'title' => 'Prophylaxie en retard',
                'detail' => "Lot {$v['batch']->code} — {$v['count']} acte(s) à tracer (ex. {$v['next']}).",
                'url' => route('batches.show', $v['batch']->id),
            ]);
        }

        // Bien-être animal.
        foreach ($welfareAlerts as $a) {
            $types = collect($a['issues'])->pluck('type')->join(', ');
            $out->push([
                'level' => 'attention', 'icon' => 'fa-hand-holding-heart',
                'title' => 'Bien-être animal',
                'detail' => "Lot {$a['batch']->code} — {$types} au-dessus du seuil.",
                'url' => route('batches.show', $a['batch']->id),
            ]);
        }

        // Stocks sous seuil — une seule ligne résumée (le détail est dans le
        // bloc dédié sous le Centre de Contrôle, inutile de répéter chaque article).
        if ($lowStocks->isNotEmpty()) {
            $hasEmpty = $lowStocks->where('current_quantity', '<=', 0)->isNotEmpty();
            $out->push([
                'level'  => $hasEmpty ? 'critique' : 'attention',
                'icon'   => 'fa-boxes-stacked',
                'title'  => 'Stocks sous seuil',
                'detail' => "{$lowStocks->count()} article(s) en dessous du seuil de réapprovisionnement.",
                'url'    => route('stocks.index'),
            ]);
        }

        // Vide sanitaire dépassé.
        if ($sanitaryAlertsCount > 0) {
            $out->push([
                'level' => 'attention', 'icon' => 'fa-broom',
                'title' => 'Vide sanitaire dépassé',
                'detail' => "{$sanitaryAlertsCount} bâtiment(s) en désinfection au-delà du délai.",
                'url' => route('buildings.index'),
            ]);
        }

        // Bannière critique seulement : le Centre de Contrôle escalade les
        // urgences (niveau « critique »). Les alertes « attention » restent dans
        // leurs panneaux détaillés (Silos, Sanitaire, Stocks, Eau) pour éviter
        // le doublon hub ↔ panneau.
        return $out
            ->where('level', 'critique')
            ->map(fn ($a) => $a + ['rank' => $rank[$a['level']] ?? 9])
            ->sortBy('rank')
            ->values();
    }
}
