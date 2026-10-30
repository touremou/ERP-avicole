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
}
