<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * TÉLÉVERSEMENTS PRIVÉS — hors racine web.
 *
 * Le disque « public » porte mal son nom : il n'est pas seulement lisible par
 * l'application, il est SERVI EN STATIQUE. Le déploiement exécute
 * `php artisan storage:link`, si bien que tout fichier qui s'y trouve répond à
 * /storage/… sans compte, sans session, sans droit — le serveur web répond
 * lui-même, PHP n'est jamais appelé.
 *
 * #248 a sorti les justificatifs de dépense de ce disque. Ce support généralise
 * la règle au reste des pièces qui n'ont rien à faire en libre accès : photos et
 * CV d'employés, photos d'autopsie, relevés de nettoyage, documents de réception,
 * clichés pris au champ.
 *
 * ─── CE QUI RESTE PUBLIC, ET POURQUOI ───
 *
 *   • `logos/` : la page de CONNEXION affiche le logo, donc avant toute session.
 *     Le rendre privé le ferait disparaître de l'écran d'accueil ;
 *   • `avatars/` : l'application terrain les affiche par une balise <img>, qui
 *     n'emporte PAS le jeton d'authentification de la PWA. Les rendre privés
 *     afficherait des silhouettes à tous les techniciens ;
 *   • `products/photos`, `providers/logos` : imagerie de catalogue, sans donnée
 *     personnelle ni valeur documentaire.
 *
 * Ce partage est un ARBITRAGE, pas un oubli : on protège ce qui expose une
 * personne ou un document, on laisse en libre accès ce dont l'affichage dépend
 * d'un contexte sans session. Le dire vaut mieux que de le taire.
 *
 * ─── LECTURE DE REPLI ───
 *
 * Comme pour les justificatifs : la lecture regarde AUSSI l'ancien emplacement.
 * Une migration de fichiers peut échouer à mi-chemin sur un hébergement
 * mutualisé, et une photo d'autopsie ou un CV introuvable serait une perte
 * sèche. Le repli coûte un `exists()`.
 */
class PrivateUpload
{
    /** Disque privé (storage/app/private), hors racine web. */
    public const DISK = 'local';

    /** Disque historique, servi en statique — conservé en LECTURE seule. */
    public const LEGACY_DISK = 'public';

    /**
     * Dossiers dont le contenu ne doit JAMAIS être servi sans authentification.
     *
     * Un chemin qui commence par l'un d'eux est privé ; tout le reste demeure
     * public (cf. l'arbitrage ci-dessus).
     *
     * @var array<int, string>
     */
    public const PRIVATE_PREFIXES = [
        'employees/photos',
        'employees/cvs',
        'expenses/justificatifs',
        'autopsies',
        'cleaning',
        'receptions',
        'field/',
    ];

    /** Ce chemin relève-t-il du stockage privé ? */
    public static function isPrivatePath(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with(ltrim($path, '/'), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Range un fichier dans un dossier privé et renvoie son chemin. */
    public static function store(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, self::DISK);
    }

    /** Le disque où ce fichier se trouve réellement — null s'il a disparu. */
    public static function diskFor(?string $path): ?Filesystem
    {
        if (! $path) {
            return null;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk);
            }
        }

        return null;
    }

    /** Le fichier existe-t-il, d'un côté ou de l'autre ? */
    public static function exists(?string $path): bool
    {
        return self::diskFor($path) !== null;
    }

    /** Supprime le fichier où qu'il soit (remplacement, suppression de fiche). */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }
}
