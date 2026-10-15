<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * UNE DATE DE PÉREMPTION EST LE DERNIER JOUR D'USAGE.
 *
 * « À consommer jusqu'au 15 » : le produit est bon le 15, périmé le 16. C'est
 * la règle du scope `Stock::expired()` et de la tâche quotidienne
 * `stocks:check-expiry`. Les accesseurs `is_expired` (magasin, produits finis,
 * transformations végétales et de l'abattoir) testaient `isPast()` : minuit
 * passé, le produit était périmé LE JOUR MÊME. Le 15, un article figurait donc
 * parmi les « bientôt périmés » du tableau de bord et s'affichait « périmé »
 * sur sa ligne.
 */
final class Peremption
{
    public static function estPerime(DateTimeInterface|string|null $date): bool
    {
        return $date !== null && Carbon::parse($date)->startOfDay()->lt(today());
    }
}
