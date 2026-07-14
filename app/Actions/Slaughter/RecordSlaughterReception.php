<?php

namespace App\Actions\Slaughter;

use App\Models\SlaughterReception;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Réception du vif (CCP 1) — enregistrement unique et immuable : la
 * validation est posée à la création (validated_at), aucune route de
 * modification n'existe. Un refus ou un état sanitaire non conforme
 * déclenche l'alerte qualité (inspection ante-mortem).
 */
class RecordSlaughterReception
{
    public function execute(array $data): SlaughterReception
    {
        return DB::transaction(function () use ($data) {
            $reception = SlaughterReception::create(array_merge($data, [
                'validated_at' => now(),
                'releve_at'    => $data['releve_at'] ?? now(),
            ]));

            if ($reception->decision !== 'accepte' || $reception->sanitary_state !== 'conforme') {
                $this->alert($reception);
            }

            return $reception;
        });
    }

    private function alert(SlaughterReception $reception): void
    {
        try {
            $provider = $reception->provider?->name ?? '—';
            $decision = str_replace('_', ' ', $reception->decision);

            app(NotificationHub::class)->alertHaccp(
                "🚨 Réception vif {$decision} — éleveur {$provider} : "
                . "{$reception->received_quantity} sujets, état {$reception->sanitary_state}. "
                . "Motif : " . ($reception->decision_reason ?: 'non précisé'),
                "Réception vif — {$decision}",
                $reception->decision === 'refuse' ? 'critique' : 'normal',
            );
        } catch (\Throwable $e) {
            Log::warning("Réception {$reception->id}: alerte non envoyée : {$e->getMessage()}");
        }
    }
}
