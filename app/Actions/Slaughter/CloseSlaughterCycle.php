<?php

namespace App\Actions\Slaughter;

use App\Models\SlaughterOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Clôture d'un cycle d'abattage (checklist HACCP / déchets de fin de cycle).
 *
 * N'est possible qu'après l'EXÉCUTION (statut « terminé »). Exige les
 * confirmations obligatoires (déchets évacués, zones nettoyées/désinfectées,
 * marche en avant respectée) ; enregistre en parallèle un INSTANTANÉ des
 * contrôles automatiques (CCP 3, sous-produits, températures) pour le dossier.
 * Idempotente : un cycle déjà clos n'est pas reclos.
 */
class CloseSlaughterCycle
{
    public function execute(SlaughterOrder $order, array $data): SlaughterOrder
    {
        if ($order->status !== 'termine') {
            throw ValidationException::withMessages([
                'status' => "Le cycle {$order->order_number} doit être exécuté (terminé) avant d'être clôturé.",
            ]);
        }

        if ($order->isClosed()) {
            return $order; // déjà clos → idempotent
        }

        // Toutes les confirmations obligatoires doivent être cochées.
        $missing = [];
        foreach (SlaughterOrder::CLOSURE_CONFIRMATIONS as $key) {
            if (empty($data[$key])) {
                $missing[$key] = 'Confirmation requise pour clôturer.';
            }
        }
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        $checklist = [
            'confirmations' => [
                'waste_evacuated' => true,
                'zone_cleaned'    => true,
                'marche_avant'    => true,
            ],
            'auto_checks'      => $order->closureAutoChecks(),
            'waste_destination' => $data['waste_destination'] ?? null,
            'notes'            => $data['notes'] ?? null,
        ];

        $order->forceFill([
            'closed_at'         => now(),
            'closed_by'         => Auth::id(),
            'closure_checklist' => $checklist,
        ])->save();

        return $order;
    }
}
