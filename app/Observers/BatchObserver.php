<?php

namespace App\Observers;

use App\Models\Batch;
use App\Models\User;
use App\Notifications\IndustrialAlert;
use App\Services\NotificationHub;
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
        // de l'index (BatchController), le scope Batch::critical() et le tableau de
        // bord, pour éviter qu'une alerte se déclenche à un taux différent de celui
        // affiché — et pour que le réglage éditable « mortalité cumulée » pilote tout.
        $threshold = Batch::cumulativeMortalityThreshold();

        // Calcul du taux de mortalité ACTUEL
        $currentMortality = (($batch->initial_quantity - $batch->current_quantity) / $batch->initial_quantity) * 100;

        // Calcul du taux de mortalité PRÉCÉDENT (grâce à getOriginal)
        $previousQuantity = $batch->getOriginal('current_quantity');
        $previousMortality = (($batch->initial_quantity - $previousQuantity) / $batch->initial_quantity) * 100;

        // Condition stricte : on ne notifie QUE si on vient de franchir la ligne rouge
        if ($currentMortality > $threshold && $previousMortality <= $threshold) {
            $rate = round($currentMortality, 2);
            $this->notifyAdmins($batch, $rate);

            // Canal WhatsApp (NotificationHub) : préférences utilisateur +
            // filet de secours admin, en plus de la notification DB/SMS ci-dessus.
            $totalDead = max(0, $batch->initial_quantity - $batch->current_quantity);
            app(NotificationHub::class)->alertMortality($batch, $totalDead, $rate);
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
            // Requête unique : utilisateurs ayant le rôle nommé 'admin'.
            // Correction : la relation est `userRole` (et non `role`) — l'ancien
            // `whereHas('role')` levait une BadMethodCallException avalée par le
            // catch, si bien que l'alerte de surmortalité n'était JAMAIS envoyée.
            $admins = User::whereHas('userRole', function ($query) {
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

