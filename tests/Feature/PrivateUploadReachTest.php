<?php

use App\Models\Employee;
use App\Support\PrivateUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RESTE DES PIÈCES TÉLÉVERSÉES ÉTAIT SERVI EN STATIQUE.
 *
 * #248 avait sorti les justificatifs de dépense du disque « public » — celui que
 * le serveur web sert lui-même, sans PHP, sans session, sans droit, parce que le
 * déploiement crée le lien `storage:link`. La PR le disait franchement : les
 * autres téléversements restaient exposés. Ce lot les traite.
 *
 * CE QUI PART EN PRIVÉ : photos et CV d'employés, photos d'autopsie, relevés de
 * nettoyage, documents de réception, clichés pris au champ. Un CV et un visage
 * sont des données personnelles ; une autopsie et un document de réception sont
 * des pièces d'exploitation. Aucun n'a de raison d'être lisible par un inconnu.
 *
 * CE QUI RESTE PUBLIC, ET POURQUOI — c'est un ARBITRAGE, pas un oubli :
 *
 *   • `logos/` : la page de CONNEXION l'affiche, donc avant toute session ;
 *   • `avatars/` : l'application terrain les affiche par une balise <img>, qui
 *     n'emporte PAS le jeton de la PWA. Les rendre privés remplacerait le visage
 *     de chaque technicien par une silhouette ;
 *   • imagerie de catalogue (photos d'articles, logos fournisseurs) : ni donnée
 *     personnelle, ni valeur documentaire.
 *
 * LA MÊME LISTE SERT DEUX FOIS : elle décide de l'accès (MediaController) ET de
 * ce que la migration déplace. Deux listes auraient divergé — c'est le défaut
 * que cet audit poursuit depuis le début.
 *
 * ON RÉPOND 404, PAS 403 : dire « interdit » révélerait qu'un chemin EXISTE, et
 * renseignerait déjà sur les pièces détenues.
 */

beforeEach(function () {
    $this->setUpRbac();

    Storage::fake(PrivateUpload::DISK);
    Storage::fake(PrivateUpload::LEGACY_DISK);
});

/** Employé créé par l'écran, avec photo et CV. */
function employeAvecPieces(\App\Models\User $auteur): Employee
{
    test()->actingAs($auteur)->post(route('employees.store'), [
        'first_name'    => 'Sékou',
        'last_name'     => 'Camara',
        'gender'        => 'M',
        'phone'         => '628110022',
        'job_title'     => 'Technicien',
        'department'    => 'Élevage',
        'contract_type' => 'CDI',
        'hire_date'     => now()->subYear()->toDateString(),
        'salary'        => 1500000,
        'photo'         => UploadedFile::fake()->image('visage.jpg'),
        'cv'            => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    return Employee::latest('id')->first();
}

test('la photo et le CV d’un employé quittent le disque servi en statique', function () {
    // LE défaut : un visage et un CV lisibles sans compte.
    $employe = employeAvecPieces($this->adminUser);

    expect($employe->photo_path)->not->toBeNull()
        ->and($employe->cv_path)->not->toBeNull();

    Storage::disk(PrivateUpload::LEGACY_DISK)->assertMissing($employe->photo_path);
    Storage::disk(PrivateUpload::LEGACY_DISK)->assertMissing($employe->cv_path);
    Storage::disk(PrivateUpload::DISK)->assertExists($employe->photo_path);
    Storage::disk(PrivateUpload::DISK)->assertExists($employe->cv_path);
});

test('sans session, le CV d’un employé n’est plus servi', function () {
    $employe = employeAvecPieces($this->adminUser);

    auth()->logout();

    $this->get('/media/' . $employe->cv_path)->assertNotFound();
});

test('avec une session, il l’est', function () {
    // On ferme la porte anonyme, pas l'usage : la fiche employé doit continuer
    // d'afficher la photo et de proposer le CV.
    $employe = employeAvecPieces($this->adminUser);

    $this->actingAs($this->adminUser)
        ->get('/media/' . $employe->photo_path)
        ->assertOk();
});

test('le logo reste servi SANS compte — la page de connexion en dépend', function () {
    // L'arbitrage, vérifié : le rendre privé viderait l'écran d'accueil.
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('logos/entreprise.png', 'PNG');

    auth()->logout();

    $this->get('/media/logos/entreprise.png')->assertOk();
});

test('l’avatar reste servi SANS compte — le terrain l’affiche sans jeton', function () {
    // Une balise <img> de la PWA n'emporte pas le jeton d'authentification :
    // rendre les avatars privés afficherait des silhouettes à tous.
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('avatars/x.jpg', 'JPG');

    auth()->logout();

    $this->get('/media/avatars/x.jpg')->assertOk();
});

test('un cliché du CHAMP est privé', function () {
    // Autopsie, preuve de tâche, reçu photographié : tous sous `field/`.
    Storage::disk(PrivateUpload::DISK)->put('field/incident/photo.jpg', 'JPG');

    auth()->logout();
    $this->get('/media/field/incident/photo.jpg')->assertNotFound();

    $this->actingAs($this->adminUser)->get('/media/field/incident/photo.jpg')->assertOk();
});

test('une pièce restée à l’ANCIEN emplacement demeure lisible', function () {
    /*
     * Le repli. Une migration de fichiers peut échouer à mi-chemin sur un
     * hébergement mutualisé (quota, droits) : une photo d'autopsie ou un CV
     * introuvable serait une perte sèche. La lecture regarde les deux disques —
     * et la garde d'accès s'applique quand même.
     */
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('employees/cvs/ancien.pdf', 'PDF');

    auth()->logout();
    $this->get('/media/employees/cvs/ancien.pdf')->assertNotFound();

    $this->actingAs($this->adminUser)->get('/media/employees/cvs/ancien.pdf')->assertOk();
});

test('la migration déplace les pièces déjà déposées', function () {
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('employees/photos/visage.jpg', 'JPG');
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('autopsies/lot.jpg', 'JPG');
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('logos/entreprise.png', 'PNG');

    $migration = require database_path('migrations/2026_08_15_200000_move_sensitive_uploads_to_private_disk.php');
    $migration->up();

    Storage::disk(PrivateUpload::DISK)->assertExists('employees/photos/visage.jpg');
    Storage::disk(PrivateUpload::DISK)->assertExists('autopsies/lot.jpg');
    Storage::disk(PrivateUpload::LEGACY_DISK)->assertMissing('employees/photos/visage.jpg');

    // Le logo NE bouge PAS : il doit rester servi sans session.
    Storage::disk(PrivateUpload::LEGACY_DISK)->assertExists('logos/entreprise.png');
});

test('la migration est idempotente', function () {
    // Elle passe à chaque déploiement.
    Storage::disk(PrivateUpload::LEGACY_DISK)->put('employees/cvs/cv.pdf', 'PDF');

    $migration = require database_path('migrations/2026_08_15_200000_move_sensitive_uploads_to_private_disk.php');
    $migration->up();
    $migration->up();

    expect(Storage::disk(PrivateUpload::DISK)->get('employees/cvs/cv.pdf'))->toBe('PDF');
});

test('remplacer une photo purge AUSSI l’ancien emplacement', function () {
    $employe = employeAvecPieces($this->adminUser);
    $ancienne = $employe->photo_path;

    // On rejoue une copie héritée restée sur le disque servi en statique.
    Storage::disk(PrivateUpload::LEGACY_DISK)->put($ancienne, 'JPG hérité');

    $this->actingAs($this->adminUser)->put(route('employees.update', $employe), [
        'first_name' => 'Sékou', 'last_name' => 'Camara', 'gender' => 'M',
        'phone' => '628110022', 'job_title' => 'Technicien', 'department' => 'Élevage',
        'contract_type' => 'CDI', 'hire_date' => now()->subYear()->toDateString(),
        'salary' => 1500000, 'status' => 'Actif',
        'photo' => UploadedFile::fake()->image('nouveau.jpg'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($employe->fresh()->photo_path)->not->toBe($ancienne);

    Storage::disk(PrivateUpload::LEGACY_DISK)->assertMissing($ancienne);
    Storage::disk(PrivateUpload::DISK)->assertMissing($ancienne);
});

test('AUCUN téléversement sensible ne repart sur le disque servi en statique', function () {
    /*
     * Garde de forme, dérivée de la liste des dossiers privés : elle cherche un
     * `store(..., 'public')` visant l'un d'eux. Huit points de téléversement ont
     * dû être repris ; un neuvième rouvrirait le trou en silence.
     */
    $coupables = [];

    foreach (array_merge(glob(app_path('Actions/*/*.php')), glob(app_path('Http/Controllers/*.php')), glob(app_path('Http/Controllers/*/*.php'))) as $fichier) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents($fichier));

        foreach (PrivateUpload::PRIVATE_PREFIXES as $prefixe) {
            $dossier = rtrim($prefixe, '/');

            if (preg_match('#store\(\s*[\'"]' . preg_quote($dossier, '#') . '[^\'"]*[\'"]\s*,\s*[\'"]public[\'"]#', $code)) {
                $coupables[] = basename($fichier) . " range « {$dossier} » en public";
            }
        }
    }

    expect($coupables)->toBe([]);
});
