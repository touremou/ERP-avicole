<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\DailyCheck;
use App\Models\DiscrepancyReport;
use App\Models\EmployeeLeave;
use App\Models\EnergySource;
use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\NotificationPreference;
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
    public function __construct(
        private WhatsAppService $whatsapp
    ) {}

    // ──────────────────────────────────────────────
    // RÉSUMÉ QUOTIDIEN (7H)
    // ──────────────────────────────────────────────

    /**
     * Construit et envoie le résumé du matin à tous les abonnés.
     */
    public function sendDailySummary(): int
    {
        $message = $this->buildDailySummary();
        $recipients = $this->getSubscribers('daily_summary');
        $sent = 0;

        foreach ($recipients as $user) {
            $result = $this->whatsapp->send($user->whatsapp_phone, $message, [
                'user_id' => $user->id,
                'type'    => 'daily_summary',
                'title'   => 'Résumé Quotidien',
            ]);
            if ($result) $sent++;
        }

        Log::info("NotificationHub: résumé quotidien envoyé à {$sent}/{$recipients->count()} destinataires.");

        return $sent;
    }

    /**
     * Compile le résumé du matin.
     */
    private function buildDailySummary(): string
    {
        $farmName = config('whatsapp.farm_name', 'AviSmart');
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

        // CA de la veille
        $yesterdaySales = Sale::whereDate('sale_date', yesterday())
            ->whereNotIn('status', ['annule', 'brouillon']);
        $yesterdayCA = $yesterdaySales->sum('total_amount');
        $yesterdayCount = $yesterdaySales->count();

        // Paiements reçus hier
        $yesterdayPayments = Payment::whereDate('payment_date', yesterday())->sum('amount');

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
            $emoji = $rate > 0.5 ? '🔴' : '⚠️';
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

        $recipients = $this->getSubscribers('daily_summary');
        $sent = 0;
        $servedPhones = [];

        foreach ($recipients as $user) {
            $servedPhones[] = $user->whatsapp_phone;
            if ($this->whatsapp->send($user->whatsapp_phone, $message, [
                'user_id' => $user->id,
                'type'    => 'activity_digest',
                'title'   => 'Activité du jour',
            ])) {
                $sent++;
            }
        }

        // Filet de sécurité : toujours au propriétaire hors site.
        $adminPhone = (string) setting('whatsapp.admin_phone', '');
        if ($adminPhone !== '' && ! in_array($adminPhone, $servedPhones, true)) {
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
        $farmName = config('whatsapp.farm_name', 'AviSmart');
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

        $this->broadcast('alert_mortality', $message, 'Mortalité ' . $batch->code, 'critique');
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

        $this->broadcast('alert_mortality', $message, 'Pic mortalité ' . $batch->code, 'critique');
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

        $this->broadcast('alert_stock', $message, 'Stock ' . $stock->item_name, 'critique');
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
            'farm'      => config('whatsapp.farm_name', 'AviSmart'),
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
            'farm'  => config('whatsapp.farm_name', 'AviSmart'),
            'count' => $items->count(),
            'items' => $lines,
        ]);

        $this->broadcast('alert_stock', $message, 'Péremption consommables', $hasExpired ? 'critique' : 'attention');
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

        $this->broadcast('alert_sales', $message, 'Vente ' . $sale->reference, ($isLarge || $afterHours) ? 'critique' : 'normal');
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

        $this->broadcast('alert_fraud', $message, 'Annulation ' . $sale->reference, $wasCommitted ? 'critique' : 'normal');
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

        $this->broadcast('alert_fraud', $message, 'Ajustement ' . $stock->item_name, $isDecrease ? 'critique' : 'normal');
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

        $this->broadcast('alert_sales', $message, 'Paiement ' . $sale->reference, $afterHours ? 'critique' : 'normal');
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

        $approvers = User::whereIn('role_id', $approverRoleIds)
            ->whereNotNull('whatsapp_phone')
            ->get();

        foreach ($approvers as $approver) {
            $this->whatsapp->send($approver->whatsapp_phone, $message, [
                'user_id' => $approver->id,
                'type'    => 'alert_leave',
                'title'   => "Congé {$emp->first_name}",
            ]);
        }

        Log::info("NotificationHub: demande de congé #{$leave->id} notifiée à {$approvers->count()} responsable(s).");
    }

    /**
     * Notifie l'employé que sa demande de congé a été approuvée.
     */
    public function notifyLeaveApproved(EmployeeLeave $leave): void
    {
        $recipient = $leave->employee->user ?? $leave->requester;
        if (! $recipient?->whatsapp_phone) {
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

        $this->whatsapp->send($recipient->whatsapp_phone, $message, [
            'user_id' => $recipient->id,
            'type'    => 'alert_leave',
            'title'   => 'Congé approuvé',
        ]);
    }

    /**
     * Notifie l'employé que sa demande de congé a été refusée.
     */
    public function notifyLeaveRejected(EmployeeLeave $leave): void
    {
        $recipient = $leave->employee->user ?? $leave->requester;
        if (! $recipient?->whatsapp_phone) {
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

        $this->whatsapp->send($recipient->whatsapp_phone, $message, [
            'user_id' => $recipient->id,
            'type'    => 'alert_leave',
            'title'   => 'Congé refusé',
        ]);
    }

    /**
     * Notifie le récepteur désigné d'une expédition qu'une marchandise arrive
     * et qu'il devra en valider la réception dans l'ERP.
     */
    public function notifyDispatchReceiver(\App\Models\Dispatch $dispatch): void
    {
        $receiver = $dispatch->intendedReceiver;
        if (! $receiver?->whatsapp_phone) {
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

        $this->whatsapp->send($receiver->whatsapp_phone, $message, [
            'user_id' => $receiver->id,
            'type'    => 'alert_dispatch',
            'title'   => "Réception {$dispatch->dispatch_number}",
        ]);
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

        $farmName = config('whatsapp.farm_name', 'AviSmart');
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
        $farmName  = config('whatsapp.farm_name', 'AviSmart');
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

        $recipients = $this->getSubscribers('daily_summary');
        $sent       = 0;

        foreach ($recipients as $user) {
            if ($this->whatsapp->send($user->whatsapp_phone, $message, [
                'user_id' => $user->id,
                'type'    => 'daily_summary',
                'title'   => 'Dosage Aliment',
            ])) {
                $sent++;
            }
        }

        Log::info("NotificationHub: dosage aliment envoyé à {$sent} destinataire(s).");

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

        $farmName = config('whatsapp.farm_name', 'AviSmart');
        $utility  = app(\App\Services\UtilityService::class);
        $signaled = 0;

        foreach (\App\Models\Farm::where('is_active', true)->get() as $farm) {
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
        $farmName = config('whatsapp.farm_name', 'AviSmart');
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
        $start = trim((string) setting('whatsapp.business_hours_start', ''));
        $end   = trim((string) setting('whatsapp.business_hours_end', ''));

        if ($start === '' || $end === '') {
            return false;
        }

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
     * Envoie à tous les abonnés d'un type de notification.
     */
    private function broadcast(string $type, string $message, string $title, string $severity = 'normal'): void
    {
        $recipients = $this->getSubscribers($type);

        foreach ($recipients as $user) {
            $prefs = NotificationPreference::where('user_id', $user->id)->first();

            // Respecter les heures silencieuses (sauf critique)
            if ($prefs && $prefs->isQuietHour() && $severity !== 'critique') {
                continue;
            }

            $this->whatsapp->send($user->whatsapp_phone, $message, [
                'user_id' => $user->id,
                'type'    => $type,
                'title'   => $title,
            ]);
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
                    ['type' => $type, 'title' => $title, 'message' => $message, 'severity' => $severity],
                    ['mail']
                ));
        }

        // ─── Canaux IN-APP (cloche) + E-MAIL (file d'attente) ───
        // Même alerte, autres canaux : on touche aussi les abonnés sans WhatsApp.
        // Les canaux retenus dépendent des préférences de chaque destinataire ;
        // la décision est centralisée ici, AlertNotification ne fait que les porter.
        foreach ($this->typeRecipients($type) as $user) {
            $prefs = $user->notificationPreference;
            if (! $prefs || ! $prefs->is_active) {
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

            if ($channels !== []) {
                $user->notify(new \App\Notifications\AlertNotification(
                    ['type' => $type, 'title' => $title, 'message' => $message, 'severity' => $severity],
                    $channels
                ));
            }
        }
    }

    /**
     * Destinataires d'un type d'alerte pour les canaux in-app / e-mail :
     * tout utilisateur dont les préférences sont actives et qui n'a pas
     * désactivé ce type — indépendamment du canal WhatsApp (on peut ne
     * recevoir que la cloche et/ou l'e-mail).
     */
    private function typeRecipients(string $type)
    {
        $column = match ($type) {
            'daily_summary', 'alert_mortality', 'alert_stock',
            'alert_energy', 'alert_sales', 'alert_fraud' => $type,
            'alert_budget' => 'alert_fraud', // contrôle financier (cf. getSubscribers)
            default => null,
        };

        return User::whereHas('notificationPreference', function ($q) use ($column) {
            $q->where('is_active', true);
            if ($column) {
                $q->where($column, true);
            }
        })->get();
    }

    /**
     * Récupère les utilisateurs abonnés à un type de notification.
     */
    private function getSubscribers(string $type)
    {
        $column = match ($type) {
            'daily_summary'              => 'daily_summary',
            'alert_mortality'            => 'alert_mortality',
            'alert_stock'                => 'alert_stock',
            'alert_energy'               => 'alert_energy',
            'alert_sales'                => 'alert_sales',
            'alert_fraud'                => 'alert_fraud',
            // Dépassement budgétaire = contrôle financier : on réutilise la
            // souscription « fraude/anomalies » plutôt qu'une nouvelle colonne.
            'alert_budget'               => 'alert_fraud',
            default                      => null,
        };

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
