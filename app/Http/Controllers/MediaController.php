<?php

namespace App\Http\Controllers;

use App\Support\PrivateUpload;
use Illuminate\Support\Facades\Auth;

/**
 * Sert les fichiers téléversés directement via PHP, sans dépendre du lien
 * symbolique public/storage. Cela garantit l'affichage des images sur tous les
 * environnements (y compris quand `php artisan storage:link` n'a pas été lancé).
 *
 * DEUX RÉGIMES, DEPUIS QUE LES PIÈCES SENSIBLES ONT QUITTÉ LE DISQUE SERVI EN
 * STATIQUE (#248 pour les justificatifs, celui-ci pour le reste) :
 *
 *   • chemin PRIVÉ (photo ou CV d'employé, autopsie, nettoyage, réception,
 *     cliché du champ) : session obligatoire. Ces fichiers vivent désormais hors
 *     racine web, donc cette route est leur SEULE porte — et elle est gardée ;
 *   • chemin public (logo, avatar, imagerie de catalogue) : inchangé, servi sans
 *     compte. La page de connexion affiche le logo avant toute session, et
 *     l'application terrain affiche les avatars par une balise <img> qui
 *     n'emporte pas son jeton.
 */
class MediaController extends Controller
{
    public function show(string $path)
    {
        // Anti path-traversal : pas de remontée de répertoire.
        abort_if(str_contains($path, '..'), 404);

        // Un fichier privé ne sort que pour un compte connecté. On répond 404 et
        // non 403 : révéler qu'un chemin EXISTE renseignerait déjà sur les
        // pièces détenues.
        if (PrivateUpload::isPrivatePath($path) && ! Auth::check()) {
            abort(404);
        }

        // Le fichier peut être sur le disque privé (désormais) ou sur l'ancien,
        // si la migration de déplacement n'a pas pu tout traiter.
        $disk = PrivateUpload::diskFor($path);

        abort_unless($disk, 404);

        return $disk->response($path);
    }
}
