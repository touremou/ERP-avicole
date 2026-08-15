<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\DailyCheck;
use App\Models\DiscrepancyReport;
use App\Models\EmployeeLeave;
use App\Models\EnergySource;
use App\Models\Module;
use App\Models\Setting;
use App\Models\ModulePermission;
use App\Models\NotificationPreference;
use App\Services\WebPushService;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaterSource;
use Illuminate\Support\Facades\Log;

/**
 * NotificationHub — Orchestrateur central des notifications AviSmart.
 *
 * Deux modes :
 * 1. Résumé quotidien (cron 7h) — compile toutes les données de la nuit
 * 2. Alertes temps réel — appelé par les observers/controllers quand un événement se produit
 */
class NotificationHub
{
    /**
     * OÙ MÈNE LE CLIC, par type d'alerte — repli quand l'objet précis n'est pas
     * connu (contrôle planifié portant sur plusieurs enregistrements).
     *
     * Une alerte dit qu'il y a QUELQUE CHOSE À FAIRE. Sans destination, elle
     * laisse chercher où — et sur un téléphone, chercher signifie renoncer.
     *
     * Déclaration UNIQUE : la cloche, le push et l'e-mail lisent tous la même
     * adresse. Trois cartes divergeraient, et l'on ouvrirait trois écrans
     * différents pour la même alerte.
     *
     * Les alertes qui DÉSIGNENT un enregistrement (un lot, un article, un congé)
     * passent leur propre adresse à broadcast() : mieux vaut la fiche que la
     * liste où il faut ensuite retrouver la ligne.
     */
    private const DESTINATIONS = [
        // [ route web (nom), écran du TERRAIN (chemin PWA) ]
        //
        // DEUX APPLICATIONS, DEUX JEUX DE ROUTES. Le bureau connaît /batches/12 ;
        // le terrain a ses propres écrans. Envoyer une adresse web au téléphone
        // le renvoie à l'accueil — son routeur ignore le chemin. Les deux vivent
        // donc dans LA MÊME table : deux cartes séparées divergeraient.
        //
        // Le terrain reçoit l'écran où l'on AGIT, pas celui où l'on consulte :
        // un pointage manquant ouvre la feuille de présence, un registre
        // incomplet ouvre la tournée de températures. C'est ce qu'on lui demande
        // de faire.
        'alert_mortality'     => ['batches.index', '/nouvelle'],
        // Qualité de l'eau : on mène à la FICHE DU LOT, où le relevé et ses alertes
        // sont affichés — pas à la liste. Le terrain, lui, va au pointage du jour :
        // c'est là qu'on agit (aération, renouvellement d'eau).
        'alert_water'         => ['batches.index', '/nouvelle'],
        'alert_stock'         => ['stocks.index', '/logistique/stocks'],
        'alert_energy'        => ['utilities.energy.sources', '/ressources/ravitaillement'],
        'alert_sales'         => ['sales.index', '/commerce/journal'],
        'alert_fraud'         => ['dispatches.discrepancies', '/commerce/journal'],
        'alert_budget'        => ['budgets.index', '/tresorerie/journal'],
        'alert_haccp'         => ['slaughter.registres.index', '/abattoir/temperature/tournee'],
        'alert_hr_attendance' => ['attendance.index', '/rh/presence'],
        // Administratif : le terrain n'a pas d'écran, il reste sur ses alertes.
        'alert_hr_contract'   => ['employees.contracts.index', '/alertes'],
        'alert_leave'         => ['payroll.leaves', '/alertes'],
        'daily_summary'       => ['dashboard', '/'],
        'activity_digest'     => ['dashboard', '/'],
        // Le récepteur valide la réception au bureau : la PWA n'a pas d'écran
        // d'expédition (vérifié dans SCREENS), le terrain reste donc sur ses
        // alertes plutôt que d'être renvoyé à l'accueil par un chemin inconnu.
        'alert_dispatch'      => ['dispatches.index', '/alertes'],
        // Sauvegarde en défaut : affaire d'administration, le terrain n'a pas
        // d'écran de sauvegardes et reste sur ses alertes.
        'alert_backup'        => ['backups.index', '/alertes'],
    ];

    /**
     * Destination par défaut d'un type d'alerte.
     *
     * Un type inconnu mène au centre d'alertes plutôt que nulle part : la
     * notification y est relisible en entier, ce qui vaut mieux qu'un clic sans
     * effet — lequel se lit comme une panne.
     */
    public static function destinationFor(string $type): string
    {
        // Des NOMS de route, pas des chemins écrits à la main : écrits à la main
        // ils s'inventent — « /stocks » n'existe pas, l'écran est /inventory. Un
        // nom est vérifié par le framework et suit l'URL si elle change.
        $name = self::DESTINATIONS[$type][0] ?? null;

        if ($name && \Illuminate\Support\Facades\Route::has($name)) {
            return route($name, absolute: false);
        }

        return route('notifications.index', absolute: false);
    }

    /**
     * Destination sur le TERRAIN (application mobile).
     *
     * Le push et le centre d'alertes mobile la consomment. Le routeur de la PWA
     * ne connaît que ses propres chemins : lui donner « /batches/12 » ou
     * « /notifications » le renvoie à l'accueil, ce qui se lit comme un clic sans
     * effet. « /alertes » est SON centre d'alertes — pas celui du bureau.
     */
    public static function mobileDestinationFor(string $type): string
    {
        return self::DESTINATIONS[$type][1] ?? '/alertes';
    }

    public function __construct(
        private WhatsAppService $whatsapp,
        private WebPushService $push,
    ) {}

    // ──────────────────────────────────────────────
    // RÉSUMÉ QUOTIDIEN (7H)
    // ──────────────────────────────────────────────

    /**
     * Construit et envoie le résumé du matin à tous les abonnés.
     */
    public function sendDailySummary(): int
    {
        // Par broadcast() et NON par un envoi WhatsApp direct : c'est LE résumé du
        // promoteur, qui vit à l'étranger. Envoyé en direct, il n'existait que sur
        // le canal WhatsApp — lequel est en mode journal sur cette installation. Le
        // résumé ne partait donc nulle part, chaque matin, sans que rien ne le dise.
        // Il passe désormais aussi par la cloche, le push et l'e-mail, selon les
        // préférences de chacun.
        $sent = $this->broadcast(
            'daily_summary',
            $this->buildDailySummary(),
            'Résumé Quotidien'
        );

        Log::info("NotificationHub: résumé quotidien remis à {$sent} destinataire(s), tous canaux.");

        return $sent;
    }

    /**
     * Compile le résumé du matin.
     */
    private function buildDailySummary(): string
    {
        $farmName = Setting::companyName();
        $date = now()->translatedFormat('l d F Y');

        // Données
        $totalBirds = Batch::active()->live()->sum('current_quantity');
        $activeBatches = Batch::active()->live()->count();

        // Mortalité dernières 24h
        $mortality24h = DailyCheck::where('check_date', '>=', now()->subDay())
            ->sum('mortality');

        // Stock aliment critique
        $criticalStocks = Stock::where('category', Stock::CAT_CONSO)
            ->whereRaw('current_quantity <= alert_threshold')
            ->where('alert_threshold', '>', 0)
            ->get(['item_name', 'current_quantity', 'unit']);

        // Carburant
        $groupes = EnergySource::groupes()->get();
        $fuelAlerts = $groupes->filter(fn($g) => $g->is_fuel_low);

        // Citernes basses
        $lowCiternes = WaterSource::critical()->get();

        // CA de la veille.
        //
        // C'ÉTAIT `yesterday()`, une fonction qui N'EXISTE PAS. `yesterday()` est une
        // méthode statique de Carbon, pas une fonction globale — PHP levait donc une
        // erreur fatale « Call to undefined function App\Services\yesterday() » ici
        // même, et le résumé quotidien N'A JAMAIS ÉTÉ ENVOYÉ, sur aucun canal, depuis
        // que ces deux lignes existent. La tâche de 07:00 mourait chaque matin en
        // silence. Vérifié à la main : `php artisan avismart:daily-summary` plantait.
        //
        // C'est la même famille que l'incident du 14 juillet — un symbole absent,
        // invoqué seulement à l'exécution, dans une tâche que personne ne regarde
        // tourner. UndefinedFunctionCallTest balaie désormais tout le code à la
        // recherche du même défaut.
        $yesterdaySales = Sale::whereDate('sale_date', now()->subDay()->toDateString())
            ->whereNotIn('status', ['annule', 'brouillon']);
        $yesterdayCA = $yesterdaySales->sum('total_amount');
        $yesterdayCount = $yesterdaySales->count();

        // Paiements reçus hier
        $yesterdayPayments = Payment::whereDate('payment_date', now()->subDay()->toDateString())->sum('amount');

        // Écarts non résolus
        $openDiscrepancies = DiscrepancyReport::where('resolution', 'en_cours')->count();

        // ─── RAPPORT ŒUFS (EggAnalysisService) ───
        $eggService = new \App\Services\EggAnalysisService();
        $eggBlock = $eggService->buildWhatsAppBlock();

        // ─── CONSTRUCTION DU MESSAGE ───
        $lines = [];
        $lines[] = "🌅 *{$farmName} — Résumé du {$date}*";
        $lines[] = "";

        // Cheptel
        $lines[] = "🐔 *CHEPTEL*";
        $lines[] = "  Effectif actif : *{$totalBirds}* sujets ({$activeBatches} lots)";
        if ($mortality24h > 0) {
            $rate = $totalBirds > 0 ? round(($mortality24h / $totalBirds) * 100, 2) : 0;
            // Seuil paramétré, et non un 0,5 codé en dur : le résumé WhatsApp
            // teintait en rouge selon un seuil que la ferme ne pouvait pas régler,
            // alors même que le réglage existait et pilotait les autres alertes.
            // Ici le taux est un AGRÉGAT tous lots confondus : le seuil par phase
            // ne s'applique pas, c'est bien le seuil général qui fait foi.
            $emoji = $rate > (float) setting('elevage.daily_mortality_alert_pct', 0.5) ? '🔴' : '⚠️';
            $lines[] = "  {$emoji} Mortalité 24h : *{$mortality24h}* ({$rate}%)";
        } else {
            $lines[] = "  ✅ Mortalité 24h : 0";
        }
        $lines[] = "";

        // Rapport œufs (si pondeuses actives)
        if ($eggBlock) {
            $lines[] = $eggBlock;
        }

        // Stocks critiques
        if ($criticalStocks->count() > 0) {
            $lines[] = "📦 *STOCKS CRITIQUES*";
            foreach ($criticalStocks as $s) {
                $lines[] = "  🔴 {$s->item_name} : {$s->current_quantity} {$s->unit}";
            }
            $lines[] = "";
        }

        // Énergie
        if ($fuelAlerts->count() > 0 || $lowCiternes->count() > 0) {
            $lines[] = "⚡ *ALERTES RESSOURCES*";
            foreach ($fuelAlerts as $g) {
                $lines[] = "  ⛽ {$g->name} : *{$g->fuel_autonomy_days}j* d'autonomie carburant";
            }
            foreach ($lowCiternes as $c) {
                $lines[] = "  💧 {$c->name} : *{$c->current_level_percent}%*";
            }
            $lines[] = "";
        }

        // Maintenance
        $maintenanceDue = $groupes->filter(fn($g) => $g->needs_maintenance);
        if ($maintenanceDue->count() > 0) {
            foreach ($maintenanceDue as $g) {
                $lines[] = "  🔧 {$g->name} : vidange dans *{$g->hours_before_maintenance}h*";
            }
            $lines[] = "";
        }

        // Ventes veille
        $lines[] = "💰 *VENTES HIER*";
        $lines[] = "  CA : *" . number_format($yesterdayCA, 0, ',', '.') . " GNF* ({$yesterdayCount} vente(s))";
        $lines[] = "  Encaissé : *" . number_format($yesterdayPayments, 0, ',', '.') . " GNF*";
        $lines[] = "";

        // Anti-fraude
        if ($openDiscrepancies > 0) {
            $lines[] = "🚨 *ANTI-FRAUDE*";
            $lines[] = "  {$openDiscrepancies} écart(s) non résolu(s)";
            $lines[] = "";
        }

        $lines[] = "— {$farmName} ERP 🇬🇳";

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────
    // DIGEST D'ACTIVITÉ PAR EMPLOYÉ (FIN DE JOURNÉE)
    // ──────────────────────────────────────────────

    /**
     * Digest d'activité par employé — outil de redevabilité pour le
     * propriétaire hors site.
     *
     * Compile, pour la journée écoulée, QUI a fait QUOI (ventes saisies,
     * encaissements, mouvements de stock). Envoyé aux abonnés du résumé
     * quotidien ET systématiquement au numéro admin de secours, afin que le
     * propriétaire puisse repérer une activité anormale (saisies tardives,
     * ajustements de stock à répétition, agent inactif…). Aucun envoi si
     * aucune activité (pas de bruit inutile).
     *
     * @return int Nombre d'envois réussis.
     */
    public function sendActivityDigest(): int
    {
        $message = $this->buildActivityDigest();

        if ($message === null) {
            return 0;
        }

        // Même raison que le résumé quotidien : envoyé en direct, ce digest
        // n'existait que sur WhatsApp. C'est l'outil de redevabilité d'un promoteur
        // hors site — le canal le plus important à ne PAS laisser dépendre d'un
        // seul provider.
        $sent = $this->broadcast('activity_digest', $message, 'Activité du jour');

        // Filet de sécurité : toujours au propriétaire hors site, même s'il n'est
        // pas abonné. Conservé tel quel — c'est un numéro, pas un compte, donc il
        // n'a ni cloche ni push : le WhatsApp direct est ici le seul chemin.
        $adminPhone = (string) setting('whatsapp.admin_phone', '');
        if ($adminPhone !== '') {
            if ($this->whatsapp->send($adminPhone, $message, [
                'type'  => 'activity_digest',
                'title' => 'Activité du jour',
            ])) {
                $sent++;
            }
        }

        Log::info("NotificationHub: digest d'activité envoyé à {$sent} destinataire(s).");

        return $sent;
    }

    /**
     * Compile le digest d'activité du jour, ventilé par employé.
     * Retourne null si aucune activité attribuable n'a eu lieu.
     */
    private function buildActivityDigest(): ?string
    {
        $farmName = Setting::companyName();
        $date = now()->translatedFormat('l d F Y');
        $start = now()->copy()->startOfDay();
        $end = now()->copy()->endOfDay();

        $salesByUser = Sale::whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'brouillon')
            ->selectRaw('user_id, COUNT(*) as cnt, SUM(total_amount) as total')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $paymentsByUser = Payment::whereBetween('created_at', [$start, $end])
            ->selectRaw('received_by, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('received_by')->get()->keyBy('received_by');

        $cancelledByUser = Sale::whereBetween('updated_at', [$start, $end])
            ->where('status', 'annule')
            ->selectRaw('user_id, COUNT(*) as cnt')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $movementsByUser = StockMovement::whereBetween('created_at', [$start, $end])
            ->selectRaw('user_id, type, COUNT(*) as cnt')
            ->groupBy('user_id', 'type')->get()->groupBy('user_id');

        $userIds = collect()
            ->merge($salesByUser->keys())
            ->merge($paymentsByUser->keys())
            ->merge($cancelledByUser->keys())
            ->merge($movementsByUser->keys())
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return null;
        }

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $lines = [];
        $lines[] = "📋 *{$farmName} — Activité du {$date}*";
        $lines[] = "";

        foreach ($userIds as $uid) {
            $name = $users->get($uid)?->name ?? "Utilisateur #{$uid}";
            $lines[] = "👤 *{$name}*";

            if ($s = $salesByUser->get($uid)) {
                $lines[] = "  💰 Ventes : {$s->cnt} (" . number_format((float) $s->total, 0, ',', '.') . " GNF)";
            }
            if ($p = $paymentsByUser->get($uid)) {
                $lines[] = "  💵 Encaissé : {$p->cnt} (" . number_format((float) $p->total, 0, ',', '.') . " GNF)";
            }
            if ($movs = $movementsByUser->get($uid)) {
                $byType = $movs->keyBy('type');
                $parts = [];
                if ($in = $byType->get('in')) {
                    $parts[] = "{$in->cnt} entrée(s)";
                }
                if ($out = $byType->get('out')) {
                    $parts[] = "{$out->cnt} sortie(s)";
                }
                if ($adj = $byType->get('adjustment')) {
                    $parts[] = "⚠️ {$adj->cnt} ajustement(s)";
                }
                if ($parts) {
                    $lines[] = "  📦 Stock : " . implode(', ', $parts);
                }
            }
            if ($c = $cancelledByUser->get($uid)) {
                $lines[] = "  🚫 Annulations : *{$c->cnt}*";
            }
            $lines[] = "";
        }

        $lines[] = "— {$farmName} ERP 🇬🇳";

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────
    // ALERTES TEMPS RÉEL
    // ──────────────────────────────────────────────

    /**
     * Construit le corps d'un message à partir d'un modèle éditable
     * (NotificationTemplate) ou de son défaut livré, puis substitue les
     * variables {{ clé }}.
     */
    /**
     * Alerte sanitaire HACCP (CCP non conforme, température hors seuil,
     * blocage/libération de lot) — type 'alert_haccp' inconnu de
     * typeRecipients() → diffusée à TOUS les utilisateurs actifs : la
     * sécurité sanitaire ne dépend pas d'un abonnement individuel.
     */
    public function alertHaccp(string $message, string $title, string $severity = 'normal'): void
    {
        $this->broadcast('alert_haccp', $message, $title, $severity);
    }

    /**
     * POINTAGE MANQUANT — le trou qui coûte de l'argent en silence.
     *
     * Les jours non pointés sont présumés travaillés : sans feuille de présence,
     * la paie du mois est identique à celle d'un mois de présence parfaite. Une
     * absence d'une semaine est donc payée, et rien ne le signale.
     *
     * L'alerte se déclenche le SOIR même, quand la journée se rattrape encore.
     * Découverte à la paie, l'information n'a plus de valeur : on ne reconstitue
     * pas un mois de présence de mémoire.
     *
     * @param  array<int, string>  $missingDates  Jours ouvrés sans aucune feuille.
     */
    public function alertAttendanceMissing(string $farmName, array $missingDates, int $headcount): void
    {
        if (empty($missingDates)) return;

        $count = count($missingDates);
        $days = collect($missingDates)
            ->map(fn ($d) => '• ' . \Illuminate\Support\Carbon::parse($d)->translatedFormat('D d/m'))
            ->join("\n");

        $message = "🕓 *Pointage manquant — {$farmName}*\n\n"
            . ($count === 1
                ? "Aucune feuille de présence pour :\n{$days}"
                : "{$count} jours ouvrés sans feuille de présence :\n{$days}")
            . "\n\n{$headcount} employé(s) concerné(s)."
            . "\n\n⚠️ Sans pointage, la paie présume ces jours TRAVAILLÉS et les règle en entier."
            . " Une absence non pointée est payée.";

        // Au-delà de deux jours, le rattrapage devient une reconstitution de
        // mémoire : ce n'est plus un oubli, c'est une perte de traçabilité.
        $this->broadcast('alert_hr_attendance', $message, 'Pointage manquant', $count > 2 ? 'critique' : 'attention');
    }

    private function tpl(string $key, array $vars): string
    {
        return \App\Models\NotificationTemplate::interpolate(
            \App\Models\NotificationTemplate::bodyFor($key),
            $vars
        );
    }

    /**
     * Alerte mortalité pic.
     */
    public function alertMortality(Batch $batch, int $mortality, float $rate): void
    {
        $message = $this->tpl('alert_mortality', [
            'emoji'     => $rate > 1 ? '🔴' : '⚠️',
            'batch_code' => $batch->code,
            'building'  => $batch->building->name,
            'deaths'    => $mortality,
            'rate'      => $rate,
            'remaining' => $batch->current_quantity,
        ]);

        $this->broadcast('alert_mortality', $message, 'Mortalité ' . $batch->code, 'critique', route('batches.show', $batch->id, absolute: false), "/lot/{$batch->id}");
    }

    /**
     * Alerte INCIDENT SANITAIRE déclaré (anomalie/crise). Diffusée sur le canal
     * sanitaire (alert_mortality) ; sévérité « critique » → escalade (admin
     * WhatsApp/e-mail), sinon « normal ». Le vétérinaire/responsable est ainsi
     * prévenu dès la déclaration terrain pour accélérer le diagnostic.
     */
    public function alertHealthIncident(\App\Models\HealthIncident $incident): void
    {
        $batchCode = $incident->batch?->code ?? '—';
        $building  = $incident->building?->name ?? '—';

        // Message via template éditable (Notifications › Modèles), à variables réelles.
        $message = $this->tpl('alert_incident', [
            'severity'   => $incident->severity_label,
            'batch_code' => $batchCode,
            'building'   => $building,
            'deaths'     => $incident->mortality_count,
            'symptoms'   => \Illuminate\Support\Str::limit((string) $incident->symptoms, 180),
        ]);

        $severity = $incident->severity === \App\Models\HealthIncident::SEVERITY_CRITICAL ? 'critique' : 'normal';

        $this->broadcast('alert_mortality', $message, 'Incident sanitaire ' . $batchCode, $severity);
    }

    /**
     * Alerte PIC de mortalité QUOTIDIEN (early-warning maladie), par bâtiment.
     *
     * Complète l'alerte de mortalité CUMULÉE (seuil 5 %, qui arrive tard) : un
     * pic journalier anormal (≥ seuil quotidien en nombre ET en %) signale une
     * maladie, un problème d'eau/température ou une intoxication AVANT que le
     * cumul ne devienne critique. Déclenchée à la saisie du pointage.
     */
    public function alertDailyMortalitySpike(Batch $batch, int $deaths, float $dailyRate): void
    {
        $building = $batch->building->name ?? 'Bâtiment ?';
        $message = $this->tpl('daily_mortality_spike', [
            'batch_code' => $batch->code,
            'building'   => $building,
            'deaths'     => $deaths,
            'daily_rate' => $dailyRate,
            'remaining'  => $batch->current_quantity,
        ]);

        $this->broadcast('alert_mortality', $message, 'Pic mortalité ' . $batch->code, 'critique', route('batches.show', $batch->id, absolute: false), "/lot/{$batch->id}");
    }

    /**
     * Alerte stock critique.
     */
    public function alertStockCritical(Stock $stock): void
    {
        $message = $this->tpl('alert_stock', [
            'item_name' => $stock->item_name,
            'category'  => $stock->category,
            'quantity'  => $stock->current_quantity,
            'unit'      => $stock->unit,
            'threshold' => $stock->alert_threshold,
        ]);

        $this->broadcast('alert_stock', $message, 'Stock ' . $stock->item_name, 'critique', route('stocks.show', $stock->id, absolute: false));
    }

    /**
     * Alerte niveau de citerne bas (< 30 %) — ravitaillement à prévoir. Émise
     * une seule fois, au FRANCHISSEMENT du seuil (cf. WaterSource observer), via
     * la souscription « ressources / énergie » (alert_energy).
     */
    public function alertCiterneLow(\App\Models\WaterSource $source): void
    {
        $pct   = round((float) $source->current_level_percent);
        $level = number_format((float) $source->current_level_liters);
        $cap   = number_format((float) $source->capacity_liters);

        $message = "💧 Citerne « {$source->name} » à {$pct}% ({$level} / {$cap} L). "
            . "Ravitaillement à prévoir.";

        $this->broadcast('alert_energy', $message, 'Citerne basse — ' . $source->name, 'attention');
    }

    /**
     * Relance de paiement adressée AU CLIENT (et non au staff) pour une vente
     * impayée échue. Envoie sur le téléphone du client et journalise la relance
     * (PaymentReminder) pour l'historique de recouvrement et l'anti-doublon.
     *
     * @return bool  Vrai si un message a été émis (client joignable + texte rendu).
     */
    public function remindClientPayment(Sale $sale, ?int $userId = null): bool
    {
        $client = $sale->client;
        $phone  = $client?->phone;

        $message = $this->tpl('payment_reminder', [
            'client'    => $client?->name ?? 'Client',
            'reference' => $sale->reference,
            'amount'    => number_format($sale->remaining_amount, 0, ',', ' '),
            'days'      => $sale->days_overdue,
            'farm'      => Setting::companyName(),
        ]);

        $sent = false;
        if ($phone) {
            $sent = (bool) $this->whatsapp->send($phone, $message, [
                'type'  => 'payment_reminder',
                'title' => 'Relance ' . $sale->reference,
            ]);
        }

        \App\Models\PaymentReminder::create([
            'farm_id'   => $sale->farm_id,
            'sale_id'   => $sale->id,
            'client_id' => $sale->client_id,
            'user_id'   => $userId,
            'channel'   => 'whatsapp',
            'message'   => $message,
            'sent_at'   => $sent ? now() : null,
        ]);

        return $sent;
    }

    /**
     * Alerte de péremption des consommables (vaccins, médicaments, intrants…).
     * Reçoit la collection d'articles périmés ou périmant bientôt.
     */
    public function alertStockExpiry($items): void
    {
        if ($items->isEmpty()) return;

        $hasExpired = false;
        $lines = $items->map(function ($s) use (&$hasExpired) {
            $left = $s->days_until_expiry;
            if ($left < 0) $hasExpired = true;
            $when = $left < 0 ? 'PÉRIMÉ' : "J-{$left}";
            $date = optional($s->expiry_date)->format('d/m/Y');
            return "• {$s->item_name} ({$date} — {$when})";
        })->join("\n");

        $message = $this->tpl('stock_expiry', [
            'farm'  => Setting::companyName(),
            'count' => $items->count(),
            'items' => $lines,
        ]);

        $this->broadcast('alert_stock', $message, 'Péremption consommables', $hasExpired ? 'critique' : 'attention');
    }

    /**
     * Contrats à terme arrivant à échéance — ou déjà dépassés sans décision.
     *
     * Le terme dépassé passe en tête et fait basculer la sévérité en critique :
     * c'est le seul cas qui expose juridiquement (un CDD qui court au-delà de
     * son terme sans acte se requalifie), et « critique » déclenche l'escalade
     * admin même hors heures silencieuses.
     */
    public function alertContractsToDecide($employees, $missingTerm = null): void
    {
        $missingTerm = $missingTerm ?? collect();

        if ($employees->isEmpty() && $missingTerm->isEmpty()) return;

        $overdue = false;
        $lines = $employees
            ->sortBy(fn ($e) => $e->days_until_contract_end)
            ->map(function ($e) use (&$overdue) {
                $left = $e->days_until_contract_end;
                if ($left < 0) $overdue = true;
                $when = $left < 0 ? 'TERME DÉPASSÉ' : "J-{$left}";
                $date = optional($e->contract_end_date)->format('d/m/Y');

                return "• {$e->name} ({$e->contract_type}, {$date} — {$when})";
            })
            ->join("\n");

        // Les contrats SANS terme sont annoncés à part : leur problème n'est pas
        // une échéance qui approche, c'est l'absence de toute échéance. Les
        // fondre dans la même liste rendrait le message incompréhensible.
        if ($missingTerm->isNotEmpty()) {
            $overdue = true; // aucun suivi possible : c'est au moins aussi grave
            $lines .= ($lines !== '' ? "\n" : '')
                . "\n⚠️ SANS TERME RENSEIGNÉ (hors de tout suivi) :\n"
                . $missingTerm->map(fn ($e) => "• {$e->name} ({$e->contract_type}, embauché le "
                    . (optional($e->hire_date)->format('d/m/Y') ?? '—') . ')')->join("\n");
        }

        $message = $this->tpl('contract_expiry', [
            'farm'      => Setting::companyName(),
            'count'     => $employees->count() + $missingTerm->count(),
            'employees' => $lines,
        ]);

        // Réutilise la souscription « fraude/anomalies » — contrôle
        // administratif — comme le fait déjà alert_budget, plutôt que d'ajouter
        // une colonne de préférence pour un seul type d'alerte.
        $this->broadcast('alert_hr_contract', $message, 'Contrats à terme', $overdue ? 'critique' : 'attention');
    }

    /**
     * Alerte carburant bas.
     */
    public function alertFuelLow(EnergySource $source): void
    {
        $autonomyLabel = $source->fuel_autonomy_hours !== null
            ? "{$source->fuel_autonomy_hours}h de fonctionnement"
            : "{$source->fuel_autonomy_days} jour(s)";

        $message = $this->tpl('alert_fuel', [
            'source'   => $source->name,
            'autonomy' => $autonomyLabel,
            'level'    => $source->current_fuel_level,
            'capacity' => $source->fuel_tank_capacity,
        ]);

        $this->broadcast('alert_energy', $message, 'Carburant ' . $source->name, 'critique');
    }

    /**
     * Alerte de DÉPASSEMENT BUDGÉTAIRE : le cumul des dépenses validées d'un
     * poste a franchi son budget mensuel. Déclenchée au moment du franchissement
     * (cf. App\Services\BudgetMonitor), une seule fois par poste/mois.
     */
    public function alertBudgetOverrun(string $category, int $year, int $month, float $spent, float $budget): void
    {
        $label = \App\Models\Expense::CATEGORIES[$category] ?? ucfirst($category);
        $monthLabel = \Carbon\Carbon::create($year, $month, 1)->locale('fr')->isoFormat('MMMM YYYY');
        $pct  = $budget > 0 ? round($spent / $budget * 100) : 0;
        $over = $spent - $budget;

        $message = "📊 *DÉPASSEMENT BUDGET*\n\n"
            . "Poste : *{$label}*\n"
            . "Mois : {$monthLabel}\n"
            . "Budget : " . number_format($budget, 0, ',', ' ') . " GNF\n"
            . "Dépensé : *" . number_format($spent, 0, ',', ' ') . " GNF* ({$pct}%)\n"
            . "Dépassement : " . number_format($over, 0, ',', ' ') . " GNF\n\n"
            . "Vérifier les dépenses de ce poste.";

        $this->broadcast('alert_budget', $message, "Budget {$label}", 'critique');
    }

    /**
     * Notification vente créée.
     *
     * Une vente dont le montant dépasse le seuil `whatsapp.large_sale_threshold`
     * est escaladée en CRITIQUE : elle atteint alors aussi le numéro admin de
     * secours même si personne n'est explicitement abonné aux ventes — garde-fou
     * contre les ventes inhabituelles passées à l'insu du propriétaire.
     */
    public function notifySaleCreated(Sale $sale): void
    {
        $threshold = (float) setting('whatsapp.large_sale_threshold', 0);
        $isLarge = $threshold > 0 && (float) $sale->total_amount >= $threshold;
        $afterHours = $this->isAfterHours();

        $flags = '';
        if ($isLarge) {
            $flags .= "\n\n⚠️ Montant au-delà du seuil de " . number_format($threshold, 0, ',', '.') . " GNF.";
        }
        if ($afterHours) {
            $flags .= "\n\n🌙 Enregistrée HORS heures ouvrées (" . now()->format('H:i') . ").";
        }

        $message = $this->tpl('sale_created', [
            'header'    => $isLarge ? "💰🔴 *GROSSE VENTE*" : "💰 *NOUVELLE VENTE*",
            'reference' => $sale->reference,
            'client'    => $sale->client?->name ?? 'Client',
            'total'     => number_format($sale->total_amount, 0, ',', '.'),
            'status'    => $sale->payment_status,
            'flags'     => $flags,
        ]);

        $this->broadcast('alert_sales', $message, 'Vente ' . $sale->reference, ($isLarge || $afterHours) ? 'critique' : 'normal', route('sales.show', $sale->id, absolute: false));
    }

    /**
     * Alerte annulation d'une vente (vecteur de détournement : encaisser puis
     * annuler la trace). Diffusée via le canal anti-fraude ; escaladée en
     * critique si la vente avait été validée/livrée (donc déstockée).
     */
    public function alertSaleCancelled(Sale $sale, string $reason = '', ?string $previousStatus = null): void
    {
        $status = $previousStatus ?? $sale->getOriginal('status');
        $wasCommitted = in_array($status, ['valide', 'livre'], true);
        $emoji = $wasCommitted ? '🚨' : '⚠️';

        $message = "{$emoji} *VENTE ANNULÉE*\n\n"
            . "Réf : *{$sale->reference}*\n"
            . "Client : " . ($sale->client?->name ?? 'N/A') . "\n"
            . "Montant : *" . number_format($sale->total_amount, 0, ',', '.') . " GNF*\n"
            . "Statut avant annulation : *{$status}*\n"
            . "Par : " . (\Illuminate\Support\Facades\Auth::user()?->name ?? 'Système') . "\n"
            . ($reason !== '' ? "Motif : {$reason}\n" : '')
            . ($wasCommitted ? "\nLa vente était validée (stock restitué). Vérifier la légitimité." : '');

        $this->broadcast('alert_fraud', $message, 'Annulation ' . $sale->reference, $wasCommitted ? 'critique' : 'normal', route('sales.show', $sale->id, absolute: false));
    }

    /**
     * Alerte ajustement manuel de stock (vecteur de dissimulation de vol :
     * « corriger » un stock à la baisse sans flux documenté). Diffusée via le
     * canal anti-fraude ; critique uniquement pour les baisses.
     */
    public function alertStockAdjustment(Stock $stock, float $oldQty, float $newQty, ?string $notes = null): void
    {
        $delta = $newQty - $oldQty;
        $isDecrease = $delta < 0;
        $emoji = $isDecrease ? '🚨' : 'ℹ️';

        $message = "{$emoji} *AJUSTEMENT STOCK*\n\n"
            . "Article : *{$stock->item_name}*\n"
            . "Avant : {$oldQty} {$stock->unit}\n"
            . "Après : *{$newQty} {$stock->unit}*\n"
            . "Écart : *" . ($delta > 0 ? '+' : '') . round($delta, 2) . " {$stock->unit}*\n"
            . "Par : " . (\Illuminate\Support\Facades\Auth::user()?->name ?? 'Système') . "\n"
            . ($notes ? "Note : {$notes}\n" : '')
            . ($isDecrease ? "\nDiminution manuelle d'inventaire — vérifier la justification." : '');

        $this->broadcast('alert_fraud', $message, 'Ajustement ' . $stock->item_name, $isDecrease ? 'critique' : 'normal', route('stocks.show', $stock->id, absolute: false));
    }

    /**
     * Notification paiement reçu.
     */
    public function notifyPaymentReceived(Payment $payment): void
    {
        $sale = $payment->sale;
        $afterHours = $this->isAfterHours();

        $flags = $afterHours
            ? "\n\n⚠️ Enregistré à " . now()->format('H:i') . ", hors heures ouvrées — à vérifier."
            : '';

        $message = $this->tpl('payment_received', [
            'header'    => $afterHours ? "🌙 *ENCAISSEMENT HORS HORAIRES*" : "✅ *PAIEMENT REÇU*",
            'amount'    => number_format($payment->amount, 0, ',', '.'),
            'method'    => $payment->method_label,
            'reference' => $sale->reference,
            'client'    => $sale->client?->name ?? 'Client',
            'remaining' => number_format($sale->remaining_amount, 0, ',', '.'),
            'flags'     => $flags,
        ]);

        $this->broadcast('alert_sales', $message, 'Paiement ' . $sale->reference, $afterHours ? 'critique' : 'normal', route('sales.show', $sale->id, absolute: false));
    }

    // ──────────────────────────────────────────────
    // CONGÉS RH
    // ──────────────────────────────────────────────

    /**
     * Notifie les responsables RH qu'une nouvelle demande de congé est en attente.
     * Cible : tous les utilisateurs ayant le droit annuaire.S (can_delete = true).
     */
    public function notifyLeaveRequested(EmployeeLeave $leave): void
    {
        $emp   = $leave->employee;
        $start = $leave->start_date->format('d/m/Y');
        $end   = $leave->end_date->format('d/m/Y');

        $message = "📋 *DEMANDE DE CONGÉ*\n\n"
            . "Employé : *{$emp->first_name} {$emp->last_name}*\n"
            . "Type : {$leave->type_label}\n"
            . "Période : {$start} → {$end} ({$leave->days_count} j)\n"
            . ($leave->reason ? "Motif : {$leave->reason}\n" : '')
            . "\nValidation requise dans l'ERP › Congés & Absences.";

        $annuaireModule = Module::where('slug', 'annuaire')->first();
        if (! $annuaireModule) {
            return;
        }

        $approverRoleIds = ModulePermission::where('module_id', $annuaireModule->id)
            ->where('can_delete', true)
            ->pluck('role_id');

        // Plus de filtre sur le téléphone WhatsApp : un valideur qui n'en a pas
        // doit tout de même voir la demande sur sa cloche et son téléphone. C'est
        // broadcast() qui écarte ensuite, canal par canal, ceux qu'il ne peut pas
        // joindre.
        $approvers = User::whereIn('role_id', $approverRoleIds)->get();

        // Diffusion COMPLÈTE, à l'audience imposée : cloche, push, e-mail et
        // WhatsApp selon les préférences de chacun — au lieu du seul WhatsApp
        // envoyé en direct. Tant que le WhatsApp reste en mode journal, une demande
        // de congé n'atteignait tout simplement personne.
        $this->broadcast(
            'alert_leave',
            $message,
            "Congé {$emp->first_name}",
            'attention',
            null,
            null,
            $approvers
        );

        Log::info("NotificationHub: demande de congé #{$leave->id} notifiée à {$approvers->count()} responsable(s).");
    }

    /**
     * Notifie l'employé que sa demande de congé a été approuvée.
     */
    public function notifyLeaveApproved(EmployeeLeave $leave): void
    {
        // On sort si l'on ne sait pas QUI prévenir — pas s'il n'a pas de WhatsApp.
        // Le garde portait sur `whatsapp_phone` : un employé sans numéro n'était
        // donc prévenu par AUCUN canal, alors que la cloche et le push l'auraient
        // atteint. C'est broadcast() qui décide, canal par canal, ce qui est
        // joignable.
        $recipient = $leave->employee->user ?? $leave->requester;
        if (! $recipient) {
            return;
        }

        $emp   = $leave->employee;
        $start = $leave->start_date->format('d/m/Y');
        $end   = $leave->end_date->format('d/m/Y');

        $message = "✅ *CONGÉ APPROUVÉ*\n\n"
            . "Bonjour {$emp->first_name},\n\n"
            . "Votre demande de congé a été approuvée.\n"
            . "Période : *{$start} → {$end}* ({$leave->days_count} j)\n"
            . "Type : {$leave->type_label}\n\n"
            . "Si vous avez des tâches à déléguer, connectez-vous à l'ERP avant votre départ.";

        // L'employé voit sa réponse dans l'application, même sans WhatsApp.
        $this->broadcast('alert_leave', $message, 'Congé approuvé', 'normal', null, null, collect([$recipient]));
    }

    /**
     * Notifie l'employé que sa demande de congé a été refusée.
     */
    public function notifyLeaveRejected(EmployeeLeave $leave): void
    {
        // On sort si l'on ne sait pas QUI prévenir — pas s'il n'a pas de WhatsApp.
        // Le garde portait sur `whatsapp_phone` : un employé sans numéro n'était
        // donc prévenu par AUCUN canal, alors que la cloche et le push l'auraient
        // atteint. C'est broadcast() qui décide, canal par canal, ce qui est
        // joignable.
        $recipient = $leave->employee->user ?? $leave->requester;
        if (! $recipient) {
            return;
        }

        $emp   = $leave->employee;
        $start = $leave->start_date->format('d/m/Y');
        $end   = $leave->end_date->format('d/m/Y');

        $message = "❌ *CONGÉ REFUSÉ*\n\n"
            . "Bonjour {$emp->first_name},\n\n"
            . "Votre demande de congé n'a pas été acceptée.\n"
            . "Période demandée : {$start} → {$end} ({$leave->days_count} j)\n"
            . ($leave->rejection_reason ? "Motif : *{$leave->rejection_reason}*\n" : '')
            . "\nContactez votre responsable RH pour plus d'informations.";

        $this->broadcast('alert_leave', $message, 'Congé refusé', 'normal', null, null, collect([$recipient]));
    }

    /**
     * Notifie le récepteur désigné d'une expédition qu'une marchandise arrive
     * et qu'il devra en valider la réception dans l'ERP.
     */
    public function notifyDispatchReceiver(\App\Models\Dispatch $dispatch): void
    {
        // Le garde portait sur le NUMÉRO WhatsApp : un récepteur désigné qui n'en
        // a pas ne recevait RIEN — pas même la cloche. Or il est le seul à pouvoir
        // valider la réception, et sans elle le contrôle des écarts ne se déclenche
        // jamais. La marchandise arrivait donc sans que personne ne soit prévenu.
        // C'est le même défaut que celui corrigé sur les congés (#206).
        $receiver = $dispatch->intendedReceiver;

        if (! $receiver) {
            return;
        }

        $date = $dispatch->dispatch_date?->format('d/m/Y') ?? '';

        $message = "📦 *EXPÉDITION À RÉCEPTIONNER*\n\n"
            . "Réf : *{$dispatch->dispatch_number}*\n"
            . "Destination : {$dispatch->destination}\n"
            . "Chauffeur : {$dispatch->driver_name}"
            . ($dispatch->driver_phone ? " ({$dispatch->driver_phone})" : '') . "\n"
            . "Départ : {$date}" . ($dispatch->dispatch_time ? " {$dispatch->dispatch_time}" : '') . "\n\n"
            . "Vous êtes le récepteur désigné. À l'arrivée, validez la réception dans l'ERP "
            . "(Logistique › Expéditions) pour déclencher le contrôle des écarts.";

        // Audience IMPOSÉE : cette alerte s'adresse à une FONCTION — le récepteur
        // désigné — et non à qui a coché une case. Elle ne doit donc pas dépendre
        // des abonnements.
        $this->broadcast(
            'alert_dispatch',
            $message,
            "Réception {$dispatch->dispatch_number}",
            'normal',
            null,
            null,
            collect([$receiver])
        );
    }

    /**
     * Rappel du calendrier cultural : cycles de culture arrivant à maturité
     * (récolte prévue dans les `$daysAhead` jours, retards compris).
     *
     * Diffusé aux abonnés du résumé quotidien (réutilise l'opt-in existant,
     * pas de nouvelle préférence à gérer). Renvoie le nombre de cycles signalés.
     */
    public function notifyHarvestsDue(int $daysAhead = 7): int
    {
        $cycles = CropCycle::query()
            ->dueForHarvest($daysAhead)
            ->with('plot:id,name')
            ->orderBy('expected_harvest_date')
            ->get();

        if ($cycles->isEmpty()) {
            return 0;
        }

        $farmName = Setting::companyName();
        $lines = ["🌾 *{$farmName} — Calendrier cultural*", ''];

        foreach ($cycles as $cycle) {
            $date = $cycle->expected_harvest_date;
            $today = now()->startOfDay();
            $diff = (int) $today->diffInDays($date->copy()->startOfDay(), false);

            if ($diff < 0) {
                $when = "⚠️ en retard de " . abs($diff) . " j";
            } elseif ($diff === 0) {
                $when = "📍 aujourd'hui";
            } else {
                $when = "dans {$diff} j";
            }

            $plot = $cycle->plot?->name ? " ({$cycle->plot->name})" : '';
            $lines[] = "• *{$cycle->crop_name}*{$plot} — récolte prévue {$cycle->expected_harvest_date->format('d/m')} — {$when}";
        }

        $lines[] = '';
        $lines[] = "Préparez la main d'œuvre et la logistique de récolte.";

        $this->broadcast('daily_summary', implode("\n", $lines), 'Calendrier cultural');

        return $cycles->count();
    }

    /**
     * Dosage d'aliment recommandé par bâtiment — envoyé aux éleveurs chaque matin.
     *
     * Pour chaque lot actif, calcule le dosage via BatchAdvisorService et regroupe
     * les résultats par bâtiment. Un seul message par ferme est diffusé aux abonnés
     * du résumé quotidien (réutilise l'opt-in existant).
     *
     * @return int Nombre d'envois réussis.
     */
    public function sendFeedingDosage(): int
    {
        $batches = Batch::active()->live()
            ->with(['building', 'productionType', 'species', 'dailyChecks'])
            ->get();

        if ($batches->isEmpty()) {
            return 0;
        }

        $advisor   = new \App\Services\BatchAdvisorService();
        $farmName  = Setting::companyName();
        $date      = now()->translatedFormat('l d F Y');

        $byBuilding = $batches->groupBy(fn($b) => $b->building?->name ?? 'Sans bâtiment');

        $lines   = ["🌾 *{$farmName} — Dosage Aliment {$date}*", ''];
        $hasData = false;

        foreach ($byBuilding as $buildingName => $buildingBatches) {
            $lines[] = "🏠 *{$buildingName}*";
            foreach ($buildingBatches as $batch) {
                $reco = $advisor->recommendation($batch);
                if ($reco === null) {
                    $lines[] = "  • {$batch->code} — _barème non disponible_";
                    continue;
                }
                $hasData   = true;
                $heatFlag  = $reco['environment']['heat_stress'] ? ' 🌡️ THI ' . $reco['environment']['thi'] : '';
                $lines[]   = "  • *{$batch->code}* — S{$reco['week']} {$reco['phase']}{$heatFlag}";
                $lines[]   = "    🌾 *{$reco['total']['feed_kg']} kg* aliment ({$reco['per_subject']['feed_g']} g/sujet)";
                $lines[]   = "    💧 *{$reco['total']['water_l']} L* eau ({$reco['per_subject']['water_ml']} ml/sujet)";

                // Autonomie aliment
                $auto = $advisor->feedAutonomy($batch);
                if ($auto !== null) {
                    $autoEmoji = $auto['is_critical'] ? '🔴' : ($auto['is_warning'] ? '⚠️' : '✅');
                    $lines[]   = "    {$autoEmoji} Stock : {$auto['days']}j d'autonomie ({$auto['stock_kg']} kg)";
                }
            }
            $lines[] = '';
        }

        if (! $hasData) {
            return 0;
        }

        $lines[] = "— {$farmName} ERP 🇬🇳";
        $message  = implode("\n", $lines);

        // Par broadcast() : envoyé en direct, ce dosage n'existait que sur WhatsApp.
        // C'est la consigne d'aliment du jour, destinée aux techniciens — ceux-là
        // même qui n'ont pas forcément de numéro renseigné, et qui travaillent
        // depuis la PWA où la cloche et le push les atteignent.
        $sent = $this->broadcast('daily_summary', $message, 'Dosage Aliment');

        Log::info("NotificationHub: dosage aliment remis à {$sent} destinataire(s), tous canaux.");

        return $sent;
    }

    /**
     * Alertes agronomiques quotidiennes : pour chaque cycle de culture en cours,
     * compile les risques semis/récolte et les alertes météo de sévérité élevée
     * (critique / attention) produits par CropAdvisorService, et diffuse un
     * message de synthèse par cycle concerné.
     *
     * Diffusé aux abonnés du résumé quotidien (réutilise l'opt-in existant, comme
     * notifyHarvestsDue). Renvoie le nombre de cycles signalés.
     */
    /**
     * Alertes météo prédictives (J+1→J+N) par ferme active : fortes pluies,
     * canicule, vent fort annoncés. Diffuse une fois par ferme concernée.
     * S'appuie sur les prévisions Open-Meteo (WeatherService::forecastAlerts).
     */
    public function notifyWeatherForecast(int $days = 2): int
    {
        $weather  = app(\App\Services\WeatherService::class);
        if (! $weather->enabled()) {
            return 0;
        }

        $farmName = Setting::companyName();
        $utility  = app(\App\Services\UtilityService::class);
        $signaled = 0;

        foreach (\App\Models\Farm::active()->get() as $farm) {
            session(['current_farm_id' => $farm->id]); // contexte ferme pour les modèles énergie

            $alerts = $weather->forecastAlerts($farm, $days);

            // Alerte composite chaleur × dépendance groupe : on extrait le pic de
            // température prévu et on le croise avec la sollicitation du parc groupe.
            $peakTemp = collect($weather->forecast($farm, $days))
                ->pluck('t_max')->filter()->max();
            if ($risk = $utility->ventilationRisk($peakTemp !== null ? (float) $peakTemp : null)) {
                $alerts[] = $risk;
            }

            if (empty($alerts)) {
                continue;
            }

            $lines = ["🛰️ *{$farmName} — Alerte météo (prévisions)*", ''];
            foreach ($alerts as $a) {
                $emoji = $a['severity'] === 'critique' ? '🔴' : '⚠️';
                $lines[] = "{$emoji} *{$a['title']}*";
                $lines[] = "  {$a['message']}";
            }

            $hasCritical = collect($alerts)->contains(fn ($a) => $a['severity'] === 'critique');

            $this->broadcast('daily_summary', implode("\n", $lines), 'Météo ' . $farm->name, $hasCritical ? 'critique' : 'normal');
            $signaled++;
        }

        return $signaled;
    }

    public function notifyAgronomicRisks(): int
    {
        $cycles = CropCycle::query()
            ->inProgress()
            ->with('plot')
            ->orderBy('planting_date')
            ->get();

        if ($cycles->isEmpty()) {
            return 0;
        }

        $advisor = new \App\Services\CropAdvisorService();
        $protocolService = new \App\Services\CropProtocolAlertService();
        $farmName = Setting::companyName();
        $signaled = 0;

        foreach ($cycles as $cycle) {
            $advisories = array_merge(
                $advisor->cycleRisks($cycle),
                $cycle->plot ? $advisor->weatherAlerts($cycle->plot) : [],
                $cycle->crop_protocol_id ? $protocolService->getCycleAlerts($cycle) : []
            );

            $alerts = array_filter(
                $advisories,
                fn ($a) => in_array($a['severity'], ['critique', 'attention'], true)
            );

            if (empty($alerts)) {
                continue;
            }

            $plot = $cycle->plot?->name ? " ({$cycle->plot->name})" : '';
            $lines = ["🌾 *{$farmName} — Alerte agronomique*", '', "• *{$cycle->crop_name}*{$plot}", ''];

            foreach ($alerts as $a) {
                $emoji = $a['severity'] === 'critique' ? '🔴' : '⚠️';
                $lines[] = "{$emoji} *{$a['title']}*";
                $lines[] = "  {$a['message']}";
            }

            $hasCritical = collect($alerts)->contains(fn ($a) => $a['severity'] === 'critique');

            $this->broadcast(
                'daily_summary',
                implode("\n", $lines),
                'Agronomie ' . $cycle->crop_name,
                $hasCritical ? 'critique' : 'normal'
            );

            $signaled++;
        }

        return $signaled;
    }

    /**
     * Alerte anti-fraude (écart détecté).
     */
    public function alertFraud(DiscrepancyReport $report): void
    {
        $dispatch = $report->dispatch;
        $emoji = $report->severity === 'critique' ? '🚨' : '⚠️';

        $message = "{$emoji} *ÉCART DÉTECTÉ — ANTI-FRAUDE*\n\n"
            . "Expédition : *{$dispatch->dispatch_number}*\n"
            . "Destination : {$dispatch->destination}\n"
            . "Chauffeur : {$dispatch->driver_name}\n"
            . "Taux d'écart : *{$report->discrepancy_rate}%*\n"
            . "Manquant : *{$report->total_missing}*\n"
            . "Sévérité : *{$report->severity}*\n\n"
            . "Investigation requise.";

        $this->broadcast('alert_fraud', $message, 'Écart ' . $dispatch->dispatch_number, $report->severity);
    }

    /**
     * ÉCART DE CAISSE À LA CLÔTURE — le signal anti-fraude le plus direct.
     *
     * Il n'était annoncé À PERSONNE : un message à l'écran, pour celui-là même qui
     * venait de clôturer la caisse — c'est-à-dire, le cas échéant, la personne en
     * cause. Le promoteur, qui vit à l'étranger, n'en savait rien.
     *
     * POURQUOI TOUT ÉCART ALERTE, sans seuil de tolérance : l'espèce attendue est
     * calculée à partir des paiements RÉELLEMENT enregistrés (fond d'ouverture +
     * encaissements espèces nets). Un ticket arrondi l'est déjà à l'enregistrement
     * du paiement — cash_round porte sur le total de la vente, pas sur le comptage.
     * L'écart attendu est donc exactement ZÉRO, et inventer une tolérance
     * reviendrait à laisser passer les petits détournements réguliers, qui sont les
     * plus fréquents.
     *
     * DEUX SÉVÉRITÉS, et la distinction compte :
     *   • MANQUANT → critique. C'est de l'argent qui n'est pas là ; l'alerte doit
     *     passer les heures silencieuses et déclencher le filet admin ;
     *   • EXCÉDENT → normal. C'est presque toujours une erreur de saisie (vente non
     *     enregistrée, rendu de monnaie faux). Il faut le savoir, pas s'en alarmer.
     */
    public function alertCashDiscrepancy(\App\Models\CashRegisterSession $session): int
    {
        $ecart = (float) $session->difference;

        if (abs($ecart) < 0.01) {
            return 0;
        }

        $manquant = $ecart < 0;
        $emoji = $manquant ? '🚨' : '⚠️';
        $sens = $manquant ? 'MANQUANT' : 'EXCÉDENT';

        // `user_id` est le compte qui a OUVERT la session (seule colonne de
        // rattachement de la table). On nomme donc le tenant de la caisse, sans
        // prétendre désigner qui l'a clôturée.
        $caissier = $session->user?->name ?? 'inconnu';

        $message = "{$emoji} *ÉCART DE CAISSE — {$sens}*\n\n"
            . 'Montant : *' . money(abs($ecart)) . "*\n"
            . 'Attendu : ' . money((float) $session->expected_cash) . "\n"
            . 'Compté : ' . money((float) $session->counted_cash) . "\n"
            . "Caisse tenue par : {$caissier}\n"
            . 'Le ' . ($session->closed_at?->format('d/m/Y à H:i') ?? now()->format('d/m/Y à H:i'));

        /*
         * DESTINATION EXPLICITE. Le type `alert_fraud` mène par défaut à l'écran des
         * écarts d'EXPÉDITION — la bonne destination pour son usage d'origine, la
         * mauvaise ici. Une alerte qui dit « écart de caisse » et ouvre les
         * expéditions fait chercher au mauvais endroit.
         *
         * On garde le type (c'est bien le canal anti-fraude, et son abonnement doit
         * continuer de gouverner cette alerte), et on précise où l'on agit.
         */
        return $this->broadcast(
            'alert_fraud',
            $message,
            'Écart de caisse',
            $manquant ? 'critique' : 'normal',
            route('cash-register.index', absolute: false),
            '/commerce/journal'
        );
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────

    /**
     * Indique si l'instant courant tombe HORS des heures ouvrées de la ferme
     * (paramètres whatsapp.business_hours_start / business_hours_end). Sert à
     * escalader en critique une activité financière nocturne (signal de
     * détournement). Plage vide ou invalide = détection désactivée (false).
     */
    private function isAfterHours(): bool
    {
        // Les deux bornes passent par la MÊME lecture d'heure que le planificateur
        // (Setting::hour) : c'était la seconde déclaration divergente de « ce
        // qu'est une heure valide ». Ici la conséquence d'une saisie fautive était
        // douce — détection désactivée — mais opposée à celle de routes/console.php,
        // où elle arrêtait toutes les tâches planifiées. Une règle, une déclaration.
        //
        // Le repli VIDE reste distinct du repli d'une valeur illisible : plage vide
        // = surveillance volontairement éteinte, et ce choix doit être respecté.
        if (trim((string) setting('whatsapp.business_hours_start', '')) === ''
            || trim((string) setting('whatsapp.business_hours_end', '')) === '') {
            return false;
        }

        $start = Setting::hour('whatsapp.business_hours_start', '06:00');
        $end   = Setting::hour('whatsapp.business_hours_end', '20:00');

        try {
            $now = now();
            $startAt = $now->copy()->setTimeFromTimeString($start);
            $endAt   = $now->copy()->setTimeFromTimeString($end);
        } catch (\Throwable $e) {
            return false;
        }

        // Plage normale (ex. 06:00 → 20:00) : hors plage = avant début OU après fin.
        if ($startAt->lessThanOrEqualTo($endAt)) {
            return $now->lessThan($startAt) || $now->greaterThan($endAt);
        }

        // Plage traversant minuit (ex. 20:00 → 06:00) : hors plage = entre fin et début.
        return $now->lessThan($startAt) && $now->greaterThan($endAt);
    }

    /**
     * QUALITÉ DE L'EAU CRITIQUE — le risque le plus RAPIDE de l'exploitation.
     *
     * Une chute d'oxygène tue un bassin en quelques heures. L'évaluation existait et
     * était juste (DailyCheckExtension::getWaterAlerts, seuils réglables), mais elle
     * n'était consommée que par TROIS ÉCRANS : le tableau de bord, un rapport, et la
     * fiche du lot. Aucune notification.
     *
     * Autrement dit : l'alerte n'atteignait que la personne qui ouvrait la page. Le
     * promoteur, à l'étranger, n'en savait rien — et le technicien qui venait de
     * saisir le relevé non plus, sauf à revenir sur la fiche.
     *
     * SEULS LES SEUILS « CRITIQUES » ALERTENT. Un avertissement à chaque dérive de pH
     * de 0,2 apprendrait à ignorer le canal, et c'est l'asphyxie qu'on veut voir
     * passer. Même raisonnement que l'excédent de caisse (#229) et la sauvegarde
     * saine (#226) : ce qui crie tout le temps ne se lit plus.
     *
     * @param \App\Models\DailyCheckExtension $extension
     * @param array<int, array{level: string, metric: string, value: float, message: string}> $critiques
     */
    public function alertWaterQuality($extension, array $critiques): int
    {
        if ($critiques === []) {
            return 0;
        }

        $check = $extension->dailyCheck;
        $batch = $check?->batch;
        $code  = $batch?->code ?? '—';

        $lignes = collect($critiques)->map(fn ($a) => "• {$a['message']}")->implode("\n");

        $message = "🚨 *EAU CRITIQUE — {$code}*\n\n"
            . $lignes . "\n\n"
            . 'Relevé du ' . ($check?->check_date?->format('d/m/Y') ?? now()->format('d/m/Y')) . ".\n"
            . 'Intervention immédiate : aération, renouvellement d’eau.';

        return $this->broadcast(
            'alert_water',
            $message,
            "Eau critique {$code}",
            'critique',
            $batch ? route('batches.show', $batch->id, absolute: false) : null,
            $batch ? "/lot/{$batch->id}" : null
        );
    }

    /**
     * SAUVEGARDE EN DÉFAUT — alerte les administrateurs, tous canaux.
     *
     * Passe par broadcast() et non par un envoi direct : sur cette installation le
     * canal WhatsApp est en mode « journal », et une alerte qui n'existerait que là
     * n'atteindrait personne (cf. #216). Sur le seul incident irréversible de
     * l'exploitation, c'est le silence qui coûte le plus cher.
     *
     * @param \Illuminate\Support\Collection<int, \App\Models\User> $admins
     */
    public function alertBackupFailure(string $message, $admins): int
    {
        return $this->broadcast(
            'alert_backup',
            $message,
            'Sauvegarde en défaut',
            'critique',
            null,
            null,
            $admins
        );
    }

    /**
     * Envoie à tous les abonnés d'un type de notification.
     */
    /**
     * @param string|null $url  Où mène le clic. Voir DESTINATIONS pour le repli.
     */
    /**
     * @param  \Illuminate\Support\Collection<int, User>|null  $audience
     *        Destinataires IMPOSÉS, au lieu des abonnés au type. Sert aux alertes
     *        adressées à une fonction et non à un abonnement — une demande de
     *        congé va aux valideurs, pas à qui a coché une case. Ces alertes
     *        envoyaient jusqu'ici un WhatsApp EN DIRECT, contournant toute la
     *        chaîne : ni cloche, ni push, ni e-mail, et aucune destination. Le
     *        WhatsApp étant en mode journal chez l'exploitant, elles n'atteignaient
     *        donc personne.
     */
    private function broadcast(string $type, string $message, string $title, string $severity = 'normal', ?string $url = null, ?string $mobile = null, ?\Illuminate\Support\Collection $audience = null): int
    {
        // Nombre de personnes touchées sur AU MOINS un canal. Les appelants qui
        // rendaient « envoyé à N » comptaient les seuls envois WhatsApp : chez une
        // exploitation dont le WhatsApp est en mode journal, ils annonçaient donc 0
        // alors que la cloche avait bien reçu — ou l'inverse, 1 alors que rien
        // n'était parti. On compte les personnes atteintes, tous canaux confondus.
        $reached = [];
        // Une alerte dit qu'il y a QUELQUE CHOSE À FAIRE ; sans destination, elle
        // laisse chercher où. Le mécanisme existait de bout en bout — la cloche
        // redirige vers data['url'], le push l'ouvre, l'e-mail en fait un bouton —
        // mais AUCUNE alerte ne le renseignait. Des lecteurs, pas de rédacteur.
        $url = $url ?: static::destinationFor($type);

        // Adresse du TERRAIN : le push est délivré à la PWA, qui a ses propres
        // routes. Une alerte peut désigner une fiche au bureau sans que le
        // terrain ait l'écran correspondant — il retombe alors sur son centre.
        $mobileUrl = $mobile ?: static::mobileDestinationFor($type);

        $recipients = $audience
            ? $audience->filter(fn ($u) => filled($u->whatsapp_phone))->values()
            : $this->getSubscribers($type);

        foreach ($recipients as $user) {
            $prefs = NotificationPreference::where('user_id', $user->id)->first();

            // Respecter les heures silencieuses (sauf critique)
            if ($prefs && $prefs->isQuietHour() && $severity !== 'critique') {
                continue;
            }

            if ($this->whatsapp->send($user->whatsapp_phone, $message, [
                'user_id' => $user->id,
                'type'    => $type,
                'title'   => $title,
            ])) {
                $reached[$user->id] = true;
            }
        }

        // Filet de sécurité : les alertes critiques sont aussi envoyées au
        // numéro admin (whatsapp.admin_phone), même si l'admin n'est pas
        // explicitement abonné à ce type d'alerte.
        $adminPhone = (string) setting('whatsapp.admin_phone', '');
        if ($severity === 'critique' && $adminPhone !== '' && ! $recipients->contains('whatsapp_phone', $adminPhone)) {
            $this->whatsapp->send($adminPhone, $message, [
                'type'  => $type,
                'title' => $title,
            ]);
        }

        // Filet E-MAIL admin : pendant du filet WhatsApp ci-dessus. Sur une
        // alerte critique, on prévient aussi l'adresse admin (whatsapp.admin_email)
        // par e-mail, même si personne n'est abonné à ce type. Vide = inactif.
        $adminEmail = (string) setting('whatsapp.admin_email', '');
        if ($severity === 'critique' && $adminEmail !== '') {
            \Illuminate\Support\Facades\Notification::route('mail', $adminEmail)
                ->notify(new \App\Notifications\AlertNotification(
                    ['type' => $type, 'title' => $title, 'message' => $message, 'severity' => $severity, 'url' => $url, 'mobile_url' => $mobileUrl],
                    ['mail']
                ));
        }

        // ─── Canaux IN-APP (cloche) + E-MAIL (file d'attente) ───
        // Même alerte, autres canaux : on touche aussi les abonnés sans WhatsApp.
        // Les canaux retenus dépendent des préférences de chaque destinataire ;
        // la décision est centralisée ici, AlertNotification ne fait que les porter.
        foreach ($audience ?? $this->typeRecipients($type) as $user) {
            // Préférences EFFECTIVES : la ligne enregistrée, ou les valeurs
            // livrées si l'utilisateur n'a jamais ouvert l'écran des réglages.
            // Cette boucle écartait auparavant tout compte sans ligne — le même
            // trou que dans typeRecipients().
            $prefs = NotificationPreference::resolveFor($user);
            if (! $prefs->is_active) {
                continue;
            }

            $channels = [];

            // In-app : notification silencieuse → on ignore les heures calmes.
            if ($prefs->channel_database) {
                $channels[] = 'database';
            }

            // E-mail : intrusif comme le WhatsApp → on respecte les heures
            // silencieuses (sauf alerte critique).
            $emailAllowedNow = $severity === 'critique' || ! $prefs->isQuietHour();
            if ($prefs->channel_email && $user->email && $emailAllowedNow) {
                $channels[] = 'mail';
            }

            // PUSH : c'est ce qui fait sonner le téléphone application FERMÉE —
            // le seul canal qui atteint le terrain sans qu'on ouvre l'app. Il
            // respecte les heures silencieuses comme l'e-mail : une bannière à
            // 3 h du matin pour un registre incomplet ferait couper les
            // notifications, et on perdrait aussi les alertes qui comptent.
            if ($prefs->channel_push && $emailAllowedNow && $this->push->isConfigured()) {
                $channels[] = 'webpush';
            }

            if ($channels !== []) {
                $user->notify(new \App\Notifications\AlertNotification(
                    ['type' => $type, 'title' => $title, 'message' => $message, 'severity' => $severity, 'url' => $url, 'mobile_url' => $mobileUrl],
                    $channels
                ));

                $reached[$user->id] = true;
            }
        }

        return count($reached);
    }

    /**
     * Destinataires d'un type d'alerte pour les canaux in-app / e-mail.
     *
     * ATTENTION, c'était le trou : la requête exigeait une ligne de préférences
     * ACTIVE (`whereHas`). Or cette ligne n'était créée qu'en ouvrant l'écran des
     * réglages de notification. Un compte qui n'y était jamais allé recevait donc
     * ZÉRO alerte in-app — ni cloche web, ni centre d'alertes mobile — sans
     * qu'aucun message ne le signale. Le promoteur, qui avait visité l'écran,
     * recevait tout ; ses techniciens, rien.
     *
     * Une préférence ABSENTE vaut désormais les valeurs livrées
     * (NotificationPreference::DEFAULTS), qui activent la cloche. Le silence par
     * omission est le pire des défauts pour un système d'alerte : ne rien recevoir
     * est indistinguable de « tout va bien ».
     *
     * Le canal WhatsApp, lui, reste sur consentement EXPLICITE (cf.
     * getSubscribers) : un message sur le téléphone de quelqu'un coûte de l'argent
     * et s'impose à lui.
     */
    /**
     * COLONNE DE SOUSCRIPTION D'UN TYPE D'ALERTE — déclaration UNIQUE.
     *
     * Cette correspondance était écrite DEUX fois, et les deux divergeaient.
     * getSubscribers() (canal WhatsApp) rattachait `alert_hr_contract` et
     * `alert_hr_attendance` à la souscription « anti-fraude » ; typeRecipients()
     * (cloche, e-mail, push) ne les connaissait pas et retombait sur « aucun
     * filtre », c'est-à-dire TOUT LE MONDE.
     *
     * « Alertes anti-fraude » est la SEULE case que l'écran des préférences offre
     * pour ces deux alertes — il n'existe pas de case « RH ». La décocher
     * arrêtait donc les WhatsApp, et laissait la cloche, le push et l'e-mail
     * continuer d'arroser. Un interrupteur qui n'éteint que la moitié des lampes
     * est pire qu'un interrupteur absent : on croit avoir coupé.
     *
     * @return string|null  colonne de préférence, ou null si le type n'est
     *                      gouverné par aucune souscription (alerte structurelle
     *                      que l'on ne se désabonne pas).
     */
    private function subscriptionColumnFor(string $type): ?string
    {
        return match ($type) {
            'daily_summary', 'alert_mortality', 'alert_stock',
            'alert_energy', 'alert_sales', 'alert_fraud' => $type,

            // Dépassement budgétaire = contrôle financier : on réutilise la
            // souscription « fraude/anomalies » plutôt qu'une colonne de plus.
            'alert_budget' => 'alert_fraud',

            // Contrat à terme non décidé, et pointage manquant : anomalies
            // administratives à portée financière (des jours non pointés payés en
            // entier). Même canal que leur jumeau ci-dessus.
            'alert_hr_contract', 'alert_hr_attendance' => 'alert_fraud',

            // Digest d'activité de fin de journée : il a TOUJOURS été adressé aux
            // abonnés du résumé quotidien (getSubscribers('daily_summary')). Le
            // rattachement est explicité ici plutôt que laissé au défaut `null`,
            // qui vaut « aucun filtre » — c'est-à-dire un WhatsApp à TOUS les
            // comptes ayant un numéro. Élargir une audience payante par omission
            // serait le contraire d'une correction.
            'activity_digest' => 'daily_summary',

            // Expédition à réceptionner : adressée à une FONCTION — le récepteur
            // désigné, passé en audience imposée — et non à qui a coché une case.
            // Le rattachement à « anti-fraude » ne sert donc pas à choisir les
            // destinataires mais à respecter un compte qui a tout coupé.
            'alert_dispatch' => 'alert_fraud',

            // Sauvegarde en défaut : même logique — audience imposée (les
            // administrateurs), le rattachement ne sert qu'à respecter un compte
            // qui a coupé ce canal.
            'alert_backup' => 'alert_fraud',

            // Qualité de l'eau : c'est le même enjeu que la mortalité — des animaux
            // en danger — et l'écran des préférences n'offre pas de case
            // « pisciculture ». On réutilise donc l'abonnement existant plutôt que
            // d'inventer un type sans correspondance : l'absence de correspondance
            // vaut « aucun filtre », c'est-à-dire un WhatsApp à TOUT LE MONDE (#216).
            'alert_water' => 'alert_mortality',

            default => null,
        };
    }

    private function typeRecipients(string $type)
    {
        $column = $this->subscriptionColumnFor($type);

        $defaultAllows = $column === null || (NotificationPreference::DEFAULTS[$column] ?? false);

        return User::where(function ($query) use ($column, $defaultAllows) {
            // Préférence explicite : elle fait foi.
            $query->whereHas('notificationPreference', function ($q) use ($column) {
                $q->where('is_active', true);
                if ($column) {
                    $q->where($column, true);
                }
            });

            // Aucune préférence enregistrée : on applique les valeurs livrées.
            if ($defaultAllows) {
                $query->orWhereDoesntHave('notificationPreference');
            }
        })->get();
    }

    /**
     * Récupère les utilisateurs abonnés à un type de notification.
     */
    private function getSubscribers(string $type)
    {
        // Même déclaration que les autres canaux : c'est la divergence entre les
        // deux copies de cette carte qui laissait la cloche et le push ignorer
        // une case décochée.
        $column = $this->subscriptionColumnFor($type);

        $query = User::whereNotNull('whatsapp_phone')
            ->whereHas('notificationPreference', function ($q) use ($column) {
                $q->where('is_active', true)
                  ->where('channel_whatsapp', true);

                if ($column) {
                    $q->where($column, true);
                }
            });

        return $query->get();
    }
}
