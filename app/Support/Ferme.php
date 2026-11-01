<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * « CET ENREGISTREMENT EXISTE — DANS LA FERME COURANTE. »
 *
 * La règle `exists:batches,id` interroge la table SANS la portée de ferme :
 * elle accepte l'identifiant d'un lot de n'importe quel site. La synchronisation
 * du terrain bornait déjà ses identifiants à la ferme courante ; quinze
 * formulaires du bureau ne le faisaient pas. Une dépense pouvait ainsi être
 * rattachée au lot d'un autre site (CreateExpense enregistre l'identifiant tel
 * quel), et les autres saisies échouaient en erreur serveur au lieu d'un
 * message de validation. Une règle, pour les deux portes.
 */
final class Ferme
{
    public static function existe(string $table, string $colonne = 'id'): Exists
    {
        $regle = Rule::exists($table, $colonne);
        $ferme = session('current_farm_id');

        if ($ferme) {
            $regle->where('farm_id', $ferme);
        }

        return $regle;
    }

    /**
     * « CET EMPLOYÉ TRAVAILLE ICI. »
     *
     * Les employés ne se bornent pas par `farm_id` : un agent PRÊTÉ travaille
     * sur un site sans y être rattaché. La règle est celle des sélecteurs,
     * `Employee::visibleInCurrentFarm()`. Le terrain la lisait déjà
     * (SyncService::employeeExists) ; dix-neuf validations du bureau lisaient
     * `exists:employees,id`, sans borne de site ni d'archive.
     */
    public static function employe(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! \App\Models\Employee::visibleInCurrentFarm()->whereKey($value)->exists()) {
                $fail(__('Cet employé ne travaille pas sur ce site.'));
            }
        };
    }
}
