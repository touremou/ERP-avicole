<?php

namespace App\Traits;

use App\Models\Employee;
use App\Scopes\FarmScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENREGISTREMENT QUI DÉSIGNE UN EMPLOYÉ (tâche, récolte, présence, fiche de paie…).
 *
 * Le lien porte déjà l'identifiant de l'employé désigné : le relire à travers le
 * filtre de ferme ne PROTÈGE rien — l'identifiant est écrit en base, la requête
 * ne peut que renvoyer cette ligne-là ou NULL. Le filtre n'ajoute donc aucune
 * sécurité ; il ne produit que des trous.
 *
 * Et ces trous étaient des pannes. Un agent PRÊTÉ — dossier à Kérouané, compte
 * ayant accès à Kindia — est proposé par `Employee::assignableInCurrentFarm()`
 * dans tous les sélecteurs, mais `belongsTo(Employee::class)` lui réappliquait
 * le filtre de ferme et renvoyait NULL. Concrètement :
 *
 *   • assigner une tâche à un agent prêté ÉCRIVAIT l'affectation puis plantait
 *     en 500 sur le message de confirmation (« property first_name on null ») ;
 *   • la tâche, pourtant assignée, réaffichait « Assigner… » indéfiniment,
 *     puisque l'écran teste `@if($task->employee)`.
 *
 * C'est la TROISIÈME occurrence de la même règle écrite deux fois : la liste
 * proposait ce que la lecture refusait. Elle vit désormais à UN endroit.
 *
 * La portée des ÉCRANS reste inchangée : on n'atteint ces enregistrements qu'à
 * travers leur propre ferme, qui, elle, est bien filtrée. Les agrégats RH et la
 * paie ne passent pas par ce lien — ils interrogent `Employee` directement et
 * restent bornés au site d'origine, comme voulu.
 */
trait ReferencesEmployee
{
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope(FarmScope::class);
    }
}
