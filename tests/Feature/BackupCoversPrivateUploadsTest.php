<?php

use App\Support\PrivateUpload;
use App\Support\ReceiptStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/*
 * LA SAUVEGARDE NE GARDAIT PLUS QUE LES LOGOS.
 *
 * Deux corrections de sécurité ont sorti du disque « public » les pièces qui
 * n'ont rien à faire en libre accès : justificatifs de dépense d'abord, puis
 * photos et CV d'employés, photos d'autopsie, relevés de nettoyage, documents de
 * réception, clichés pris au champ. Elles vivent maintenant dans
 * storage/app/private, hors racine web. C'était juste.
 *
 * Mais `config/backup.php` n'incluait qu'un seul dossier :
 *
 *     storage_path('app/public')
 *
 * Ce qui a été mis à l'abri des regards est donc sorti de la sauvegarde du même
 * mouvement. Il ne restait dans l'archive que ce qui est délibérément public —
 * logos, avatars, photos de catalogue.
 *
 * ─── CE QUI ÉTAIT PERDU EN CAS DE SINISTRE ───
 *
 *   • les justificatifs de dépense — des pièces COMPTABLES ;
 *   • les CV et photos des employés — des pièces RH ;
 *   • les photos d'autopsie, relevés de nettoyage et documents de réception —
 *     la traçabilité SANITAIRE, celle qu'un contrôle demande à voir.
 *
 * La base de données, elle, était bien sauvegardée : on aurait donc retrouvé la
 * LIGNE « justificatif : expenses/justificatifs/xY3.pdf » et pas le fichier.
 * Une comptabilité qui pointe vers des pièces disparues.
 *
 * ─── LE PROMOTEUR N'AURAIT RIEN VU ───
 *
 * Ses sauvegardes ne tournent pas encore (le cron de l'hébergeur n'appelle pas
 * `schedule:run`). Le jour où ce cron est réparé, il aurait obtenu une archive
 * quotidienne, un rapport de bonne santé — et une fausse sécurité.
 *
 * ─── POURQUOI CE TEST STOCKE VRAIMENT UN FICHIER ───
 *
 * Comparer deux listes de chaînes ne prouverait rien : il resterait à croire que
 * les téléversements atterrissent bien là où la liste le dit. On DÉPOSE donc un
 * fichier par les deux façades de téléversement, et on vérifie que son chemin
 * réel tombe sous un dossier sauvegardé. Un futur disque privé, ou un dossier
 * déplacé, fera tomber ce test sans que personne ait à y penser.
 */

/** Le chemin absolu tombe-t-il sous l'un des dossiers sauvegardés ? */
function dansLaSauvegarde(string $chemin): bool
{
    foreach (config('backup.backup.source.files.include', []) as $inclus) {
        if (str_starts_with(realpath($chemin) ?: $chemin, realpath($inclus) ?: $inclus)) {
            return true;
        }
    }

    return false;
}

test('un justificatif de dépense déposé est bien dans la sauvegarde', function () {
    /*
     * LE défaut : une pièce comptable hors archive.
     */
    $chemin = ReceiptStorage::store(UploadedFile::fake()->create('facture.pdf', 12));

    $absolu = Storage::disk(ReceiptStorage::DISK)->path($chemin);

    expect(dansLaSauvegarde($absolu))->toBeTrue();

    Storage::disk(ReceiptStorage::DISK)->delete($chemin);
});

test('chaque dossier privé du référentiel est couvert', function () {
    /*
     * Dérivé de PRIVATE_PREFIXES, et non d'une liste tenue à la main : un
     * dossier privé ajouté demain est couvert par ce test le jour même.
     *
     * On dépose réellement un fichier dans chacun — la traçabilité sanitaire
     * (autopsies, nettoyage, réceptions) et les pièces RH (CV, photos) valent
     * qu'on le vérifie plutôt qu'on le suppose.
     */
    foreach (PrivateUpload::PRIVATE_PREFIXES as $prefixe) {
        $dossier = rtrim($prefixe, '/');

        $chemin = PrivateUpload::store(
            UploadedFile::fake()->create('piece.pdf', 8),
            $dossier,
        );

        $absolu = Storage::disk(PrivateUpload::DISK)->path($chemin);

        expect(dansLaSauvegarde($absolu))->toBeTrue("Dossier privé hors sauvegarde : {$dossier}");

        Storage::disk(PrivateUpload::DISK)->delete($chemin);
    }
});

test('le disque PUBLIC reste sauvegardé, lui aussi', function () {
    /*
     * La borne : on ajoute un dossier, on n'en retire aucun. Les logos et les
     * avatars ont leur place dans l'archive — c'est l'identité visuelle de
     * l'exploitation et les visages de ses agents.
     */
    expect(dansLaSauvegarde(storage_path('app/public/logos')))->toBeTrue();
});

test('la base de données reste dans la sauvegarde', function () {
    // L'autre moitié de l'archive. Des fichiers sans la base ne valent pas plus
    // que la base sans les fichiers.
    expect(config('backup.backup.source.databases'))->not->toBeEmpty();
});

test('les archives de sauvegarde ne se sauvegardent pas elles-mêmes', function () {
    /*
     * Le piège du dossier ajouté trop large : storage/app/backups est le
     * DESTINATAIRE. L'inclure ferait grossir chaque archive de toutes les
     * précédentes — jusqu'à remplir le disque d'un hébergement mutualisé, et
     * l'exploitation n'a plus de sauvegarde du tout.
     */
    expect(dansLaSauvegarde(storage_path('app/backups')))->toBeFalse();
});
