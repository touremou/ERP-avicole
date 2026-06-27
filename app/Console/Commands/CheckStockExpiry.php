<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Alerte quotidienne sur les consommables périmés ou périmant dans la fenêtre
 * configurée (stocks.expiry_alert_days). Réutilise les modèles de message
 * éditables (NotificationTemplate) via NotificationHub.
 */
class CheckStockExpiry extends Command
{
    protected $signature = 'stock:check-expiry';

    protected $description = 'Alerte sur les consommables périmés ou périmant bientôt';

    public function handle(NotificationHub $hub): int
    {
        $days = (int) setting('stocks.expiry_alert_days', 30);

        $items = Stock::where(function ($q) use ($days) {
                $q->expired()->orWhere(fn ($q2) => $q2->expiringSoon($days));
            })
            ->orderBy('expiry_date')
            ->get();

        $hub->alertStockExpiry($items);

        $this->info("{$items->count()} article(s) en péremption signalé(s).");

        return self::SUCCESS;
    }
}
