<?php

namespace App\Support;

/**
 * ÉCRITURE CSV NEUTRALISÉE.
 *
 * Sept écrans exportent en CSV, et ces fichiers finissent dans un tableur — le
 * promoteur est à l'étranger, c'est par là qu'il regarde ses chiffres.
 *
 * Or un tableur INTERPRÈTE toute cellule qui commence par `=`, `+`, `-`, `@`,
 * ou par une tabulation / un retour chariot, comme une FORMULE. Les exports
 * recopiaient tels quels des libellés saisis dans l'application : nom d'article,
 * motif d'ajustement, description de mouvement, nom d'employé, note de retour.
 * Il suffit qu'un de ces libellés commence par `=` pour que le tableur exécute
 * son contenu à l'ouverture, sans rien demander pour certaines fonctions —
 * `=HYPERLINK(…)` en tête, qui transforme une cellule en lien piégé.
 *
 * Ce n'est pas un défaut de saisie : un nom d'article commençant par `-`
 * (« -20% remise ») est parfaitement légitime et déclenche la même chose.
 *
 * LA NEUTRALISATION EST STANDARD : on préfixe la cellule d'une apostrophe, que
 * le tableur consomme en affichant le texte tel quel. La valeur reste lisible
 * par un humain ; seule l'exécution est empêchée.
 *
 * On neutralise à l'ÉCRITURE, en un seul endroit, plutôt qu'à chaque colonne de
 * chaque export : c'est la leçon de tous les lots précédents — une règle
 * recopiée sept fois est une règle qui diverge.
 */
class CsvExport
{
    /** Caractères qui font d'une cellule une formule dans un tableur. */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Écrit une ligne CSV en neutralisant chaque cellule.
     *
     * @param resource               $handle
     * @param array<int, mixed>      $row
     */
    public static function putRow($handle, array $row, string $separator = ';'): void
    {
        fputcsv($handle, array_map([self::class, 'neutralize'], $row), $separator);
    }

    /**
     * Rend une valeur inoffensive pour un tableur, sans la déformer.
     *
     * Les valeurs non textuelles (nombres, null, booléens) passent telles
     * quelles : préfixer un nombre en ferait du texte et casserait les totaux
     * de la feuille, ce qui serait une régression pour l'utilisateur.
     */
    public static function neutralize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'" . $value;
            }
        }

        return $value;
    }
}
