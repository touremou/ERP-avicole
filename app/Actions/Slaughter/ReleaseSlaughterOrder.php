<?php

namespace App\Actions\Slaughter;

use App\Models\SlaughterOrder;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\Log;

/**
 * Libération d'un ordre bloqué — réservée au niveau abattoir.S (le plus
 * élevé : « qualité »), motif obligatoire. L'ordre reprend son cours :
 * 'termine' si l'abattage avait déjà eu lieu, sinon 'planifie'.
 */
class ReleaseSlaughterOrder
{
    public function execute(SlaughterOrder $order, string $reason, int $userId): SlaughterOrder
    {
        $order->forceFill([
            'status'         => $order->result()->exists() ? 'termine' : 'planifie',
            'release_reason' => $reason,
            'released_by_id' => $userId,
            'released_at'    => now(),
        ])->save();

        try {
            app(NotificationHub::class)->alertHaccp(
                "✅ Ordre d'abattage {$order->order_number} LIBÉRÉ. Motif : {$reason}",
                "Lot libéré — {$order->order_number}",
                'normal',
            );
        } catch (\Throwable $e) {
            Log::warning("Libération {$order->id}: alerte non envoyée : {$e->getMessage()}");
        }

        return $order;
    }
}
