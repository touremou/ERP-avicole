<?php

use App\Models\Expense;
use App\Support\ReceiptStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE JUSTIFICATIF ÉTAIT PROTÉGÉ PAR UN DROIT — ET SERVI EN STATIQUE À CÔTÉ.
 *
 * `ExpenseController::downloadJustificatif` exige le droit `depenses.L` avant de
 * remettre une facture, un reçu ou une note de frais. La règle est claire.
 *
 * Mais le fichier était rangé sur le disque « public », qui porte mal son nom :
 * il n'est pas seulement lisible par l'application, il est SERVI EN STATIQUE.
 * Le déploiement exécute `php artisan storage:link` (workflow deploy.yml), donc
 * le serveur web répond lui-même à /storage/expenses/justificatifs/… sans
 * passer par PHP, sans session, sans droit. La règle existait ; une autre porte
 * l'annulait — le motif dominant de cet audit, appliqué cette fois à un fichier
 * plutôt qu'à une écriture.
 *
 * Les noms sont aléatoires, donc non énumérables : il faut connaître l'URL. Mais
 * une URL fuite — capture d'écran, historique de navigateur, message transféré —
 * et rien ne la révoque.
 *
 * ─── CE QUE CE LOT NE FAIT PAS, ET IL FAUT LE DIRE ───
 *
 * D'autres téléversements restent sur ce disque : CV et photos d'employés,
 * photos d'incidents et d'autopsies, preuves de tâches, reçus pris au champ
 * (field/…), logos fournisseurs. Ils sont exposés de la même façon. Ils ne sont
 * pas traités ici parce que chacun demande son propre point de lecture protégé —
 * et parce que les aperçus de l'application terrain s'appuient sur ces URL :
 * les couper sans les remplacer casserait le travail des techniciens. Le
 * justificatif de dépense, lui, a DÉJÀ sa route protégée : le corriger est
 * complet et sans effet de bord.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    Storage::fake(ReceiptStorage::DISK);
    Storage::fake(ReceiptStorage::LEGACY_DISK);
});

/** Crée une dépense avec un justificatif, par l'écran. */
function depenseAvecJustificatif(): Expense
{
    test()->post(route('expenses.store'), [
        'category'       => 'carburant',
        'label'          => 'Gasoil groupe',
        'amount'         => 250000,
        'expense_date'   => now()->toDateString(),
        'payment_method' => 'especes',
        'justificatif'   => UploadedFile::fake()->image('recu.jpg'),
    ])->assertRedirect();

    return Expense::latest('id')->first();
}

test('un justificatif n’atterrit PLUS sur le disque servi en statique', function () {
    // LE défaut : le fichier était lisible sans compte, à côté de la route qui
    // exige un droit.
    $depense = depenseAvecJustificatif();

    expect($depense->justificatif_path)->not->toBeNull();

    Storage::disk(ReceiptStorage::LEGACY_DISK)->assertMissing($depense->justificatif_path);
    Storage::disk(ReceiptStorage::DISK)->assertExists($depense->justificatif_path);
});

test('le justificatif ne s’obtient QUE par la route de l’application', function () {
    // Le cœur du lot : sans compte, plus rien. Auparavant le serveur web servait
    // le fichier lui-même, sans passer par PHP — donc sans cette porte.
    $depense = depenseAvecJustificatif();

    auth()->logout();
    $this->get(route('expenses.justificatif', $depense))->assertRedirect(route('login'));

    $this->actingAs($this->adminUser)
        ->get(route('expenses.justificatif', $depense))
        ->assertOk();
});

test('un justificatif ANCIEN reste téléchargeable (repli de lecture)', function () {
    /*
     * Le point de prudence. Une migration de fichiers peut échouer à mi-chemin
     * sur un hébergement mutualisé (quota, droits). Un justificatif introuvable
     * serait une pièce comptable perdue : la lecture regarde donc les deux
     * emplacements.
     */
    $depense = depenseAvecJustificatif();
    $chemin  = $depense->justificatif_path;

    // On rejoue l'état d'avant : le fichier est resté sur l'ancien disque.
    Storage::disk(ReceiptStorage::DISK)->delete($chemin);
    Storage::disk(ReceiptStorage::LEGACY_DISK)->put($chemin, 'contenu ancien');

    $this->get(route('expenses.justificatif', $depense))->assertOk();
});

test('remplacer un justificatif purge AUSSI l’ancien emplacement', function () {
    // Sinon on garderait publiquement lisible la pièce qu'on vient de remplacer.
    $depense = depenseAvecJustificatif();
    $ancien  = $depense->justificatif_path;

    Storage::disk(ReceiptStorage::LEGACY_DISK)->put($ancien, 'copie héritée');

    $this->put(route('expenses.update', $depense), [
        'category'       => 'carburant',
        'label'          => 'Gasoil groupe',
        'amount'         => 250000,
        'expense_date'   => now()->toDateString(),
        'payment_method' => 'especes',
        'justificatif'   => UploadedFile::fake()->image('recu2.jpg'),
    ])->assertRedirect();

    Storage::disk(ReceiptStorage::LEGACY_DISK)->assertMissing($ancien);
    Storage::disk(ReceiptStorage::DISK)->assertMissing($ancien);
});

test('la migration DÉPLACE les justificatifs déjà déposés', function () {
    $depense = depenseAvecJustificatif();
    $chemin  = $depense->justificatif_path;

    // État d'avant : le fichier sur l'ancien disque seulement.
    Storage::disk(ReceiptStorage::DISK)->delete($chemin);
    Storage::disk(ReceiptStorage::LEGACY_DISK)->put($chemin, 'pièce comptable');

    $migration = require database_path('migrations/2026_08_15_180000_move_expense_receipts_to_private_disk.php');
    $migration->up();

    Storage::disk(ReceiptStorage::DISK)->assertExists($chemin);
    Storage::disk(ReceiptStorage::LEGACY_DISK)->assertMissing($chemin);
    expect(Storage::disk(ReceiptStorage::DISK)->get($chemin))->toBe('pièce comptable');
});

test('la migration ne détruit rien quand il n’y a rien à déplacer', function () {
    // Idempotence : elle passe à chaque déploiement.
    $depense = depenseAvecJustificatif();

    $migration = require database_path('migrations/2026_08_15_180000_move_expense_receipts_to_private_disk.php');
    $migration->up();
    $migration->up();

    Storage::disk(ReceiptStorage::DISK)->assertExists($depense->justificatif_path);
});

test('le disque privé est hors racine web', function () {
    // Ce qui fonde tout le lot : le disque « local » pointe sur storage/app/private,
    // que le serveur web ne sert pas — contrairement à « public », relié par
    // storage:link.
    expect(config('filesystems.disks.' . ReceiptStorage::DISK . '.root'))
        ->toContain('app/private')
        ->and(config('filesystems.disks.' . ReceiptStorage::DISK . '.url'))
        ->toBeNull();
});

test('aucun chemin de justificatif n’est fabriqué hors du support dédié', function () {
    // Garde de forme : trois endroits écrivaient ce fichier. Qu'un quatrième
    // reprenne `store(..., 'public')` rouvrirait exactement le trou.
    foreach ([
        'Actions/Expense/CreateExpense.php',
        'Http/Controllers/ExpenseController.php',
    ] as $fichier) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path($fichier)));

        expect($code)->not->toContain("'expenses/justificatifs', 'public'")
            ->and($code)->toContain('ReceiptStorage');
    }
});
