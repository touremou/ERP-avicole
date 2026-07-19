<?php

namespace App\Console\Commands;

use App\Models\TaskAssignment;
use Illuminate\Console\Command;

/**
 * Libération automatique des prises de tâche expirées (verrou anti-doublon).
 *
 * Un ouvrier qui « prend » une tâche (en_cours) puis abandonne sans la
 * clôturer la laisserait verrouillée pour les autres. Au-delà du délai de
 * grâce (TaskAssignment::CLAIM_TIMEOUT_MINUTES), on la remet « à faire » et on
 * lève la prise — la tâche redevient disponible. Idempotent, sûr à rejouer.
 */
class ReleaseStaleTaskClaims extends Command
{
    protected $signature   = 'tasks:release-stale';
    protected $description = 'Réarme les tâches « prises » (en_cours) mais abandonnées au-delà du délai de grâce.';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(TaskAssignment::CLAIM_TIMEOUT_MINUTES);

        $stale = fn () => TaskAssignment::withoutGlobalScopes()
            ->where('status', 'en_cours')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('started_at')->orWhere('started_at', '<', $cutoff);
            });

        // Tâches de POOL expirées : retour au libre-service (sans titulaire).
        $released = (clone $stale())->where('is_pool', true)->update([
            'status' => 'a_faire', 'started_at' => null, 'claimed_by' => null, 'employee_id' => null,
        ]);

        // Tâches assignées expirées : rendues « à faire », titulaire conservé.
        $released += (clone $stale())->where('is_pool', false)->update([
            'status' => 'a_faire', 'started_at' => null, 'claimed_by' => null,
        ]);

        if ($released > 0) {
            $this->info("Prises de tâche expirées libérées : {$released}.");
        }

        return self::SUCCESS;
    }
}
