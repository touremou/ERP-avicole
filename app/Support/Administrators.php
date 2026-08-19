<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * QUI SONT LES ADMINISTRATEURS — déclaration UNIQUE.
 *
 * Trois endroits répondaient à cette question, et pas de la même façon :
 *
 *   • CheckBackupHealth      → rôle nommé, REPLI par role_id, comptes ACTIFS ;
 *   • ErrorAlertService      → rôle nommé, REPLI par role_id, tous les comptes ;
 *   • CumulativeMortalityAlert → rôle nommé SEULEMENT.
 *
 * Le troisième est celui qui prévient d'une surmortalité — l'alerte la plus
 * critique d'un élevage. Sur une base dont les rôles ne portent pas exactement
 * le nom « admin » — le cas même que les deux autres anticipent avec leur repli
 * — il ne trouvait personne, écrivait une ligne de journal que nul ne lit, et
 * repartait sans alerter. Les sauvegardes et les erreurs serveur, elles,
 * passaient.
 *
 * Son commentaire garde d'ailleurs la trace d'une réparation antérieure du même
 * ordre (`role` → `userRole`, dont l'exception était avalée par un catch) : cette
 * résolution a déjà échoué en silence une fois.
 *
 * ─── LES DEUX RÈGLES RETENUES ───
 *
 * REPLI PAR role_id : une base ancienne peut porter des rôles non nommés
 * « admin ». Deux des trois appelants le prévoyaient ; c'est la version qui
 * survit.
 *
 * COMPTES ACTIFS SEULEMENT : un compte désactivé est un compte dont on ne veut
 * plus. Lui adresser une alerte, c'est l'envoyer à quelqu'un qui est parti.
 * C'était déjà le choix de la surveillance des sauvegardes ; il devient celui de
 * tout le monde.
 */
class Administrators
{
    /**
     * Les comptes administrateurs actifs — vide si aucun.
     *
     * @return Collection<int, User>
     */
    public static function all(): Collection
    {
        try {
            // La relation est `userRole` (et non `role`) : l'ancien
            // `whereHas('role')` levait une BadMethodCallException avalée par un
            // catch, si bien que l'alerte n'était jamais envoyée.
            $admins = User::where('is_active', true)
                ->whereHas('userRole', fn ($q) => $q->where('name', 'admin'))
                ->get();

            if ($admins->isNotEmpty()) {
                return $admins;
            }

            $roleId = Role::where('name', 'admin')->value('id');

            return $roleId
                ? User::where('is_active', true)->where('role_id', $roleId)->get()
                : collect();
        } catch (\Throwable $e) {
            // On le DIT plutôt que de rendre une liste vide silencieuse : sans
            // destinataire, une alerte n'existe pas.
            Log::error('Administrators : impossible de lister les administrateurs — ' . $e->getMessage());

            return collect();
        }
    }
}
