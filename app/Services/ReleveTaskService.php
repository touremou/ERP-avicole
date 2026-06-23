<?php

namespace App\Services;

use App\Models\TaskAssignment;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-complète la tâche planifiée de relevé Ressources (eau / énergie) du jour
 * dès qu'un relevé correspondant est saisi — sur le même principe que
 * DailyCheck::autoCompleteTasks pour le pointage volaille.
 *
 * La saisie du relevé COUVRE déjà la tâche : inutile de la pointer deux fois.
 * Chaque type a sa propre catégorie (releve_eau / releve_energie) pour qu'un
 * relevé d'eau ne ferme pas par erreur la tâche « Relevé énergie », et qu'un
 * pointage volaille (catégorie 'controle') n'y touche pas du tout.
 */
class ReleveTaskService
{
    public function complete(?int $farmId, $date, string $category): void
    {
        if (! $farmId || ! $date) {
            return;
        }

        TaskAssignment::query()
            ->withoutGlobalScopes() // on cible la ferme explicitement
            ->where('farm_id', $farmId)
            ->whereNull('building_id') // tâches au niveau ferme (target_type=farm)
            ->where('category', $category)
            ->whereDate('scheduled_date', $date)
            ->whereIn('status', ['a_faire', 'en_retard'])
            ->get()
            ->each(function (TaskAssignment $task) use ($category) {
                $task->update([
                    'status'           => 'fait',
                    'completed_at'     => now(),
                    'completed_by'     => Auth::id(),
                    'completion_notes' => "Auto-complétée via le relevé ({$category}).",
                ]);
            });
    }
}
