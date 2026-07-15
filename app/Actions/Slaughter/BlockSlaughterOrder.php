<?php

namespace App\Actions\Slaughter;

use App\Models\SlaughterOrder;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\Log;

/**
 * Blocage qualité d'un ordre d'abattage (RG-02/RG-03) : le lot sort du
 * circuit (exécution, découpe, mise en stock refusées) jusqu'à libération
 * explicite — motif obligatoire dans les deux sens, auteurs tracés.
 */
class BlockSlaughterOrder
{
    public function execute(SlaughterOrder $order, string $reason, int $userId): SlaughterOrder
    {
        $order->forceFill([
            'status'         => 'bloque',
            'blocked_reason' => $reason,
            'blocked_by_id'  => $userId,
            'blocked_at'     => now(),
            'released_at'    => null,
            'release_reason' => null,
            'released_by_id' => null,
        ])->save();

        try {
            app(NotificationHub::class)->alertHaccp(
                "⛔ Ordre d'abattage {$order->order_number} BLOQUÉ. Motif : {$reason}",
                "Lot bloqué — {$order->order_number}",
                'critique',
            );
        } catch (\Throwable $e) {
            Log::warning("Blocage {$order->id}: alerte non envoyée : {$e->getMessage()}");
        }

        return $order;
    }
}
