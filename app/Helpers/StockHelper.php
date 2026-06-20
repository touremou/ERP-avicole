<?php

namespace App\Helpers;

use App\Models\Batch;
use App\Models\Stock;
use App\Models\DailyCheck;
use Carbon\Carbon;

class StockHelper
{
    /**
     * Calcule l'autonomie restante en jours pour un type d'aliment donné.
     */
    public static function getFeedAutonomy(string $type): int
    {
        // 1. PROTECTION CENTRALISÉE (Plus de Try/Catch dupliqué)
        // On s'appuie sur la variable globale définie par le Middleware de résilience
        if (config('app.database_down', false)) {
            return 0; 
        }

        $name = trim($type);
        $stock = Stock::where('category', Stock::CAT_CONSO)
                      ->where(fn ($q) => $q->where('item_name', $name)->orWhere('feed_type', $name))
                      ->first();

        if (!$stock || (float)$stock->current_quantity <= 0) {
            return 0;
        }

        $totalQtyKg = ($stock->unit === 'Sac') ? (float)$stock->current_quantity * 50 : (float)$stock->current_quantity;

        // 3. CACHE MÉMOIRE POUR ÉVITER LE N+1
        static $latestChecks = null;

        if ($latestChecks === null) {
            $activeBatchIds = Batch::active()->pluck('id');
            
            $latestChecks = DailyCheck::whereIn('batch_id', $activeBatchIds)
                ->whereIn('check_date', function ($query) {
                    $query->selectRaw('MAX(check_date)')
                          ->from('daily_checks')
                          ->groupBy('batch_id');
                })
                ->get();
        }

        // 4. CORRESPONDANCE STRICTE DANS LA COLLECTION
        $dailyNeeds = $latestChecks->sum(function($check) use ($type) {
            $checkDate = Carbon::parse($check->check_date);
            
            if ($checkDate->diffInHours(now()) < 36) {
                // Utilisation de strcasecmp pour une égalité stricte insensible à la casse
                if (strcasecmp(trim($check->feed_type ?? ''), trim($type)) === 0) {
                    return (float) $check->feed_consumed;
                }
            }
            return 0;
        });

        if ($dailyNeeds <= 0) {
            return 15; // Stock de sécurité par défaut
        }

        $days = (int) floor($totalQtyKg / $dailyNeeds);

        return min($days, 30); // Syntaxe plus courte et propre pour le max 30 jours
    }
}