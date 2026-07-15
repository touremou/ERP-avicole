<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Relances automatiques des ventes échues impayées. Anti-doublon : on ne
 * relance pas une vente déjà relancée dans les derniers `cooldown` jours
 * (paramètre ventes.reminder_cooldown_days, défaut 7).
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'sales:payment-reminders';

    protected $description = 'Relance automatiquement les clients en retard de paiement';

    public function handle(NotificationHub $hub): int
    {
        $cooldown = (int) setting('ventes.reminder_cooldown_days', 7);

        $sales = Sale::overdue()->validated()
            ->whereDoesntHave('reminders', fn ($q) => $q->where('sent_at', '>=', now()->subDays($cooldown)))
            ->with('client')
            ->get();

        $sent = 0;
        foreach ($sales as $sale) {
            if ($hub->remindClientPayment($sale)) {
                $sent++;
            }
        }

        $this->info("{$sent} relance(s) envoyée(s) sur {$sales->count()} vente(s) échue(s).");

        return self::SUCCESS;
    }
}
