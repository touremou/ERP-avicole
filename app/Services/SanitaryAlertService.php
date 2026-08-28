<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\Carbon;

class SanitaryAlertService
{
    /**
     * Calcule les alertes de protocoles sanitaires en retard pour les lots actifs.
     */
    public function getActiveAlerts(): array
    {
        $alerts = [];
        $today = now()->startOfDay();

        // Eager loading optimisé
        $activeBatches = Batch::with(['protocol.steps', 'healthChecks:id,batch_id,product_name'])
            ->where('status', 'Actif')
            ->get();

        foreach ($activeBatches as $batch) {
            if (!$batch->protocol || !$batch->arrival_date) continue;

            foreach ($batch->protocol->steps as $step) {
                /*
                 * Échéance et responsabilité, en une seule déclaration
                 * (Batch::protocolStepDue). Les day_number sont des ÂGES, donc
                 * l'échéance part de la naissance — et une étape due AVANT
                 * l'arrivée du lot relevait de son détenteur d'alors.
                 *
                 * Sans cette seconde moitié, ce service — qui n'a aucune fenêtre —
                 * aurait réclamé à un lot acheté à 16 semaines son J7, son J14 et
                 * son J21, faits chez l'éleveur précédent et jamais inscrits à
                 * notre registre : des alertes impossibles à solder.
                 */
                $targetDate = $batch->protocolStepDue((int) $step->day_number);

                if ($targetDate === null) {
                    continue;
                }

                // Si la date prévue est passée ou c'est aujourd'hui
                if ($targetDate->lte($today)) {
                    // Déclaration UNIQUE (cf. Batch::protocolStepDone) : cette
                    // question se posait ici, au tableau de bord et sur la fiche
                    // lot, avec trois réponses différentes sur la même donnée.
                    if (! $batch->protocolStepDone($step, $batch->healthChecks)) {
                        $alerts[] = [
                            'batch_id'   => $batch->id,
                            'batch_code' => $batch->code,
                            'step_name'  => $step->action_name ?? $step->name,
                            'step_type'  => $step->type ?? 'Vaccin',
                            'due_date'   => $targetDate,
                            'delay'      => (int) $targetDate->diffInDays($today),
                        ];
                    }
                }
            }
        }

        // Trier par délai (les plus en retard en premier)
        usort($alerts, fn($a, $b) => $b['delay'] <=> $a['delay']);

        return $alerts;
    }
}