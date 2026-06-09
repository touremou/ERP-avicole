<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SanitarySchedulerService — Aligne le calendrier sanitaire sur le protocole.
 *
 * BUG CORRIGÉ (B-24) :
 * Avant : tasks()->where('is_completed', false)->delete() → hard-delete
 * On perdait la trace de la re-planification (pas d'historique d'audit).
 *
 * Correction : Les tâches non complétées sont ANNULÉES (marquées via
 * deleted_at si SoftDeletes activé, ou via un champ is_cancelled).
 * Si le modèle Task n'a ni SoftDeletes ni is_cancelled, on fait un
 * hard-delete avec log explicite (fallback documenté).
 */
class SanitarySchedulerService
{
    /**
     * Re-synchronise le calendrier sanitaire d'un lot avec son protocole.
     *
     * Appelé après :
     * - Changement de protocole
     * - Transfert de bâtiment (la date de référence change)
     */
    public function syncSchedule(Batch $batch): void
    {
        $protocol = $batch->currentProtocol ?? $batch->protocol;

        if (! $protocol) {
            Log::warning("SanitaryScheduler: pas de protocole pour le lot {$batch->code} (id={$batch->id})");
            return;
        }

        $referenceDate = Carbon::parse(
            $batch->transfer_date ?? $batch->arrival_date ?? $batch->created_at
        );

        // ─── 1. ANNULER LES TÂCHES FUTURES NON COMPLÉTÉES ───
        $cancelledCount = $this->cancelFutureTasks($batch);

        if ($cancelledCount > 0) {
            Log::info("SanitaryScheduler: {$cancelledCount} tâche(s) annulée(s) pour re-planification du lot {$batch->code}");
        }

        // ─── 2. GÉNÉRER LES NOUVELLES ÉCHÉANCES ───
        $createdCount = 0;
        foreach ($protocol->steps as $step) {
            $plannedDate = $referenceDate->copy()->addDays($step->day_number);

            // Ne planifier que les tâches futures (aujourd'hui inclus)
            if ($plannedDate->isToday() || $plannedDate->isFuture()) {
                $batch->tasks()->create([
                    'action_name'  => $step->action_name ?? $step->name,
                    'type'         => $step->type ?? 'Vaccin',
                    'method'       => $step->method ?? 'Eau de boisson',
                    'day_number'   => $step->day_number,
                    'planned_date' => $plannedDate,
                    'is_completed' => false,
                ]);
                $createdCount++;
            }
        }

        Log::info("SanitaryScheduler: {$createdCount} tâche(s) planifiée(s) pour le lot {$batch->code} (protocole: {$protocol->name})");
    }

    /**
     * Annule les tâches futures non complétées.
     *
     * B-24 corrigé : utilise soft-delete si disponible, sinon hard-delete avec log.
     */
    private function cancelFutureTasks(Batch $batch): int
    {
        $query = $batch->tasks()->where('is_completed', false);

        // Vérifier si le modèle Task supporte SoftDeletes
        $taskModel = $batch->tasks()->getRelated();
        $usesSoftDeletes = in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($taskModel)
        );

        if ($usesSoftDeletes) {
            // Soft-delete : la tâche reste en base avec deleted_at renseigné
            $count = $query->count();
            $query->delete(); // SoftDeletes::delete() met à jour deleted_at
            return $count;
        }

        // Vérifier si le modèle a une colonne is_cancelled
        $hasIsCancelled = \Illuminate\Support\Facades\Schema::hasColumn(
            $taskModel->getTable(),
            'is_cancelled'
        );

        if ($hasIsCancelled) {
            return $query->update(['is_cancelled' => true]);
        }

        // Fallback : hard-delete avec log d'audit
        $tasks = $query->get(['id', 'action_name', 'planned_date']);
        $count = $tasks->count();

        if ($count > 0) {
            Log::warning(
                "SanitaryScheduler: hard-delete de {$count} tâche(s) pour lot {$batch->code}. " .
                "Détail : " . $tasks->pluck('action_name')->implode(', ') . ". " .
                "Recommandation : ajouter SoftDeletes au modèle Task."
            );
            $query->delete();
        }

        return $count;
    }
}
