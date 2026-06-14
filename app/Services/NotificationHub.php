<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\DiscrepancyReport;
use App\Models\EnergySource;
use App\Models\NotificationPreference;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Stock;
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

        // Gasoil
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
                $lines[] = "  ⛽ {$g->name} : *{$g->fuel_autonomy_days}j* d'autonomie gasoil";
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
    // ALERTES TEMPS RÉEL
    // ──────────────────────────────────────────────

    /**
     * Alerte mortalité pic.
     */
    public function alertMortality(Batch $batch, int $mortality, float $rate): void
    {
        $emoji = $rate > 1 ? '🔴' : '⚠️';
        $message = "{$emoji} *ALERTE MORTALITÉ*\n\n"
            . "Lot : *{$batch->code}*\n"
            . "Bâtiment : {$batch->building->name}\n"
            . "Morts : *{$mortality}* ({$rate}%)\n"
            . "Effectif restant : {$batch->current_quantity}\n\n"
            . "Action requise immédiatement.";

        $this->broadcast('alert_mortality', $message, 'Mortalité ' . $batch->code, 'critique');
    }

    /**
     * Alerte stock critique.
     */
    public function alertStockCritical(Stock $stock): void
    {
        $message = "🔴 *RUPTURE STOCK*\n\n"
            . "Article : *{$stock->item_name}*\n"
            . "Catégorie : {$stock->category}\n"
            . "Restant : *{$stock->current_quantity} {$stock->unit}*\n"
            . "Seuil alerte : {$stock->alert_threshold} {$stock->unit}\n\n"
            . "Commander immédiatement.";

        $this->broadcast('alert_stock', $message, 'Stock ' . $stock->item_name, 'critique');
    }

    /**
     * Alerte gasoil bas.
     */
    public function alertFuelLow(EnergySource $source): void
    {
        $autonomyLabel = $source->fuel_autonomy_hours !== null
            ? "{$source->fuel_autonomy_hours}h de fonctionnement"
            : "{$source->fuel_autonomy_days} jour(s)";

        $message = "⛽ *GASOIL CRITIQUE*\n\n"
            . "Groupe : *{$source->name}*\n"
            . "Autonomie : *{$autonomyLabel}*\n"
            . "Niveau cuve : {$source->current_fuel_level}L / {$source->fuel_tank_capacity}L\n\n"
            . "Commander du gasoil AUJOURD'HUI.";

        $this->broadcast('alert_energy', $message, 'Gasoil ' . $source->name, 'critique');
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

        $message = ($isLarge ? "💰🔴 *GROSSE VENTE*" : "💰 *NOUVELLE VENTE*") . "\n\n"
            . "Réf : *{$sale->reference}*\n"
            . "Client : {$sale->client->name}\n"
            . "Total : *" . number_format($sale->total_amount, 0, ',', '.') . " GNF*\n"
            . "Statut : {$sale->payment_status}";

        if ($isLarge) {
            $message .= "\n\n⚠️ Montant au-delà du seuil de " . number_format($threshold, 0, ',', '.') . " GNF.";
        }
        if ($afterHours) {
            $message .= "\n\n🌙 Enregistrée HORS heures ouvrées (" . now()->format('H:i') . ").";
        }

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
            . "Client : " . ($sale->client->name ?? 'N/A') . "\n"
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

        $message = ($afterHours ? "🌙 *ENCAISSEMENT HORS HORAIRES*" : "✅ *PAIEMENT REÇU*") . "\n\n"
            . "Montant : *" . number_format($payment->amount, 0, ',', '.') . " GNF*\n"
            . "Mode : {$payment->method_label}\n"
            . "Vente : {$sale->reference}\n"
            . "Client : {$sale->client->name}\n"
            . "Reste dû : " . number_format($sale->remaining_amount, 0, ',', '.') . " GNF";

        if ($afterHours) {
            $message .= "\n\n⚠️ Enregistré à " . now()->format('H:i') . ", hors heures ouvrées — à vérifier.";
        }

        $this->broadcast('alert_sales', $message, 'Paiement ' . $sale->reference, $afterHours ? 'critique' : 'normal');
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
