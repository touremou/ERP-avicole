<?php

namespace App\Observers;

use App\Models\Batch;
use App\Models\User;
use App\Notifications\IndustrialAlert;
use Illuminate\Support\Facades\Log;

class BatchObserver
{
    /**
     * Après mise à jour : vérifier la mortalité et alerter si nécessaire.
     */
    public function updated(Batch $batch): void
    {
        if (! $batch->wasChanged('current_quantity') || $batch->status !== 'Actif' || $batch->initial_quantity <= 0) {
            return;
        }

        // Seuil unique d'alerte mortalité : même source que le filtre « surmortalité »
        // de l'index (BatchController) et le scope Batch::critical(), pour éviter
        // qu'une alerte se déclenche à un taux différent de celui affiché à l'écran.
        $threshold = (float) setting('elevage.mortality_alert', 5);

        // Calcul du taux de mortalité ACTUEL
        $currentMortality = (($batch->initial_quantity - $batch->current_quantity) / $batch->initial_quantity) * 100;

        // Calcul du taux de mortalité PRÉCÉDENT (grâce à getOriginal)
        $previousQuantity = $batch->getOriginal('current_quantity');
        $previousMortality = (($batch->initial_quantity - $previousQuantity) / $batch->initial_quantity) * 100;

        // Condition stricte : on ne notifie QUE si on vient de franchir la ligne rouge
        if ($currentMortality > $threshold && $previousMortality <= $threshold) {
            $this->notifyAdmins($batch, round($currentMortality, 2));
        }
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

    // ─────────────────────────────────────────────
    // MÉTHODES PRIVÉES
    // ─────────────────────────────────────────────

    /**
     * Envoie une notification d'alerte mortalité à tous les administrateurs.
     */
    private function notifyAdmins(Batch $batch, float $mortalityRate): void
    {
        try {
            // Requête unique et optimisée : cherche les utilisateurs ayant un rôle nommé 'admin'
            $admins = User::whereHas('role', function ($query) {
                $query->where('name', 'admin');
            })->get();

            if ($admins->isEmpty()) {
                Log::warning(
                    "[BatchObserver] Aucun utilisateur avec le rôle 'admin' trouvé. " .
                    "Alerte mortalité non envoyée pour lot {$batch->code}."
                );
                return;
            }

            $alertData = [
                'type' => 'high_mortality',
                'priority' => 'high',
                'title' => 'Alerte de Surmortalité',
                'message' => "Mortalité critique franchie sur le lot {$batch->code} : {$mortalityRate}% atteint " .
                             "(effectif : {$batch->current_quantity}/{$batch->initial_quantity}).",
                'id_reference' => $batch->uuid,
            ];

            foreach ($admins as $admin) {
                try {
                    $admin->notify(new IndustrialAlert($alertData));
                } catch (\Exception $e) {
                    Log::error(
                        "[BatchObserver] Échec notification admin #{$admin->id} " .
                        "pour lot {$batch->code}: {$e->getMessage()}"
                    );
                }
            }

        } catch (\Exception $e) {
            Log::error("[BatchObserver] Erreur globale alertes lot {$batch->code}: {$e->getMessage()}");
        }
    }
}

