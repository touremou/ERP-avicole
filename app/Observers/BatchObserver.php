<?php

namespace App\Observers;

use App\Models\Batch;
use App\Services\CumulativeMortalityAlert;
use Illuminate\Support\Facades\Log;

class BatchObserver
{
    /**
     * Après mise à jour : vérifier la mortalité cumulée et alerter si la ligne
     * rouge vient d'être franchie.
     *
     * La règle elle-même vit dans CumulativeMortalityAlert : le pointage
     * journalier écrit l'effectif par requête directe, sans passer par Eloquent,
     * et devait donc pouvoir l'appeler lui aussi. Tant qu'elle vivait ici, le
     * geste quotidien — celui par lequel la mortalité arrive réellement — ne
     * déclenchait rien.
     */
    public function updated(Batch $batch): void
    {
        if (! $batch->wasChanged('current_quantity')) {
            return;
        }

        app(CumulativeMortalityAlert::class)
            ->evaluate($batch, (int) $batch->getOriginal('current_quantity'));
    }

    /**
     * Avant suppression soft-delete : cascade les enfants.
     */
    public function deleting(Batch $batch): void
    {
        if ($batch->isForceDeleting()) {
            return;
        }

        // Exécution sécurisée si les relations existent
        $batch->dailyChecks()->delete();
        $batch->healthChecks()->delete();
        $batch->feedPurchases()->delete();
        $batch->eggProductions()->delete();
        $batch->tasks()->delete();
    }

    /**
     * Restauration : rétablir les enfants soft-deleted.
     */
    public function restoring(Batch $batch): void
    {
        // Vérification explicite de la présence de la macro withTrashed (sécurité anti-crash)
        if (method_exists($batch->dailyChecks(), 'withTrashed')) {
            $batch->dailyChecks()->withTrashed()->restore();
        }
        if (method_exists($batch->healthChecks(), 'withTrashed')) {
            $batch->healthChecks()->withTrashed()->restore();
        }
    }
}
