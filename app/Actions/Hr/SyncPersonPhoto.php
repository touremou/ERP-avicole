<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\User;

/**
 * Aligner la photo du COMPTE et celle de la FICHE employé — même personne, même
 * visage.
 *
 * Les deux vivaient dans des champs indépendants (`users.avatar_path`,
 * `employees.photo_path`), sans aucun lien : on pouvait donc voir un visage sur
 * la fiche RH et un autre sur le téléphone du même agent. Les accesseurs se
 * replient désormais l'un sur l'autre quand l'un manque ; cette action traite
 * l'autre moitié, celle où les deux existent et divergent.
 *
 * RÈGLE, volontairement simple à énoncer :
 *
 *   À L'ENVOI d'une photo, on aligne les deux. Celui qui téléverse est soit la
 *   personne elle-même (profil), soit son responsable RH (fiche) : dans les deux
 *   cas, l'intention est « voici son visage », pas « voici son visage ici
 *   seulement ».
 *
 *   À LA SUPPRESSION, on ne vide l'autre côté QUE s'il désignait le MÊME
 *   fichier. Sinon on effacerait une photo distincte que quelqu'un a choisie —
 *   et surtout on laisserait une référence morte vers un fichier supprimé, donc
 *   une image cassée.
 */
class SyncPersonPhoto
{
    /** Photo choisie depuis le COMPTE (profil web ou mobile) → vers la fiche. */
    public function fromAccount(User $user, ?string $path, ?string $previousPath = null): void
    {
        $employee = Employee::withoutGlobalScopes()->where('user_id', $user->id)->first();

        if (! $employee) {
            return;
        }

        if ($path === null && $employee->photo_path !== $previousPath) {
            return; // la fiche porte une AUTRE photo : on n'y touche pas
        }

        $employee->forceFill(['photo_path' => $path])->save();
    }

    /** Photo choisie depuis la FICHE (responsable RH) → vers le compte. */
    public function fromEmployee(Employee $employee, ?string $path, ?string $previousPath = null): void
    {
        if (! $employee->user_id) {
            return;
        }

        $user = User::find($employee->user_id);

        if (! $user) {
            return;
        }

        if ($path === null && $user->avatar_path !== $previousPath) {
            return; // le compte porte un AUTRE avatar : on n'y touche pas
        }

        $user->forceFill(['avatar_path' => $path])->save();
    }
}
