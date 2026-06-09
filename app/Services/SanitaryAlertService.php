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

        // Fonction de normalisation pour la comparaison de chaînes
        $sanitize = fn($text) => strtolower(trim(preg_replace('/\s+/', '', $text ?? '')));

        foreach ($activeBatches as $batch) {
            if (!$batch->protocol || !$batch->arrival_date) continue;

            $arrivalDate = Carbon::parse($batch->arrival_date)->startOfDay();
            
            // Pré-calcul des produits déjà administrés
            $doneProducts = $batch->healthChecks->map(fn($c) => $sanitize($c->product_name))->toArray();

            foreach ($batch->protocol->steps as $step) {
                $targetDate = $arrivalDate->copy()->addDays($step->day_number);
                
                // Si la date prévue est passée ou c'est aujourd'hui
                if ($targetDate->lte($today)) {
                    $expected = $sanitize($step->action_name ?? $step->name);
                    
                    // Vérification si l'action a été faite
                    $isStepDone = false;
                    foreach ($doneProducts as $recorded) {
                        if (str_contains($recorded, $expected) || str_contains($expected, $recorded)) {
                            $isStepDone = true;
                            break;
                        }
                    }

                    if (!$isStepDone) {
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