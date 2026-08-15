<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * JUSTIFICATIFS DE DÉPENSE — stockage PRIVÉ.
 *
 * Ces pièces (factures, reçus, notes de frais) vivaient sur le disque « public ».
 * Ce disque porte mal son nom : il n'est pas seulement lisible par
 * l'application, il est SERVI EN STATIQUE — le déploiement exécute
 * `php artisan storage:link`, si bien que tout fichier qui s'y trouve répond à
 * /storage/… sans aucun compte.
 *
 * Or l'application déclare une règle d'accès pour ces pièces :
 * ExpenseController::downloadJustificatif exige le droit `depenses.L`. La règle
 * existait, une autre porte l'annulait. Les noms de fichiers sont aléatoires,
 * donc non énumérables — mais une URL fuite (capture d'écran, historique,
 * message transféré) et rien ne la révoque.
 *
 * Le disque « local » pointe sur storage/app/private, hors racine web : ce qui
 * s'y trouve ne peut sortir que par une route de l'application, donc derrière
 * un droit.
 *
 * ─── LECTURE DE REPLI ───
 *
 * Les justificatifs déjà déposés sont déplacés par migration. La lecture
 * regarde tout de même l'ancien emplacement : une migration de fichiers peut
 * échouer à mi-chemin (droits, quota d'un hébergement mutualisé), et un
 * justificatif introuvable serait une pièce comptable perdue. Le repli coûte un
 * appel `exists()` ; la perte, elle, serait définitive.
 */
class ReceiptStorage
{
    /** Dossier commun aux deux disques (le chemin en base reste inchangé). */
    public const FOLDER = 'expenses/justificatifs';

    /** Disque privé, hors racine web. */
    public const DISK = 'local';

    /** Disque historique, servi en statique — conservé en LECTURE seule. */
    public const LEGACY_DISK = 'public';

    /** Range un justificatif téléversé et renvoie son chemin. */
    public static function store(UploadedFile $file): string
    {
        return $file->store(self::FOLDER, self::DISK);
    }

    /** Le disque où ce justificatif se trouve réellement — null s'il a disparu. */
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

    /** Le justificatif existe-t-il, d'un côté ou de l'autre ? */
    public static function exists(?string $path): bool
    {
        return self::diskFor($path) !== null;
    }

    /** Supprime le justificatif où qu'il soit (remplacement, suppression de dépense). */
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
