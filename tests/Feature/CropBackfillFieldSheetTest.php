<?php

use App\Models\CropSpecies;
use App\Models\Employee;
use App\Services\Import\CropBackfillFieldSheet;
use App\Services\Import\CropBackfillTemplate;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * FICHE PAPIER DE REPRISE D'HISTORIQUE.
 *
 * Le technicien qui connaît l'historique est au champ, sans ordinateur. Lui
 * demander de remplir un classeur Excel revenait à faire remplir le classeur
 * par quelqu'un d'autre, de mémoire — donc à fabriquer des données fausses.
 *
 * La fiche n'a de valeur que si la transcription est MÉCANIQUE : mêmes colonnes,
 * même ordre, mêmes valeurs acceptées que le classeur. Une fiche recopiée à la
 * main divergerait au premier ajout de colonne, et le technicien remplirait un
 * formulaire qui ne correspond plus au fichier — l'erreur exacte qu'on a payée
 * sur les formulaires employé. Ces tests verrouillent l'alignement.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('la fiche porte les MÊMES colonnes, dans le MÊME ordre, que le classeur', function () {
    $definition = CropBackfillTemplate::definition();
    $sections = collect(app(CropBackfillFieldSheet::class)->data()['sections'])->keyBy('key');

    // Les quatre onglets de saisie, aucun de plus, aucun de moins.
    expect($sections->keys()->all())->toBe(CropBackfillTemplate::SHEETS);

    foreach ($definition as $sheet => $spec) {
        $expected = array_column($spec['columns'], 0);
        $actual = array_column($sections[$sheet]['columns'], 'name');

        expect($actual)->toBe(
            $expected,
            "Section « {$sheet} » : la fiche papier et le classeur ne portent pas les mêmes colonnes dans le même ordre. "
            . 'La transcription cesserait d\'être mécanique.'
        );
    }
});

test('les colonnes obligatoires du classeur sont marquées obligatoires sur la fiche', function () {
    $definition = CropBackfillTemplate::definition();
    $sections = collect(app(CropBackfillFieldSheet::class)->data()['sections'])->keyBy('key');

    foreach ($definition as $sheet => $spec) {
        foreach ($spec['columns'] as $index => $column) {
            expect($sections[$sheet]['columns'][$index]['required'])->toBe(
                $column[2],
                "Section « {$sheet} », colonne « {$column[0]} » : obligation divergente entre fiche et classeur."
            );
        }
    }
});

test('les valeurs acceptées sont imprimées, avec la distinction stricte / indicative', function () {
    CropSpecies::create(['type' => 'legume', 'name' => 'Gombo local']);

    $sections = collect(app(CropBackfillFieldSheet::class)->data()['sections'])->keyBy('key');

    // Ensemble VRAIMENT fermé → la fiche doit l'annoncer comme impératif.
    $destination = collect($sections['Recoltes']['choices'])->firstWhere('label', 'Destination');
    expect($destination)->not->toBeNull();
    expect($destination['strict'])->toBeTrue();
    expect($destination['values'])->toContain('vente');

    // Liste INDICATIVE : un producteur peut cultiver une espèce absente du
    // référentiel. La présenter comme fermée lui interdirait d'écrire le réel.
    $culture = collect($sections['Cycles']['choices'])->firstWhere('label', 'Culture');
    expect($culture['strict'])->toBeFalse();
    expect($culture['values'])->toContain('Gombo local');
});

test('une liste vide n’est pas imprimée du tout', function () {
    // Aucun employé actif : imprimer « Responsable — écrire l'un de : » suivi de
    // rien laisserait le technicien croire qu'il manque une information.
    Employee::query()->delete();

    $sections = collect(app(CropBackfillFieldSheet::class)->data()['sections'])->keyBy('key');

    expect(collect($sections['Cycles']['choices'])->firstWhere('label', 'Responsable'))->toBeNull();
});

test('chaque section offre des lignes vierges à remplir', function () {
    foreach (app(CropBackfillFieldSheet::class)->data()['sections'] as $section) {
        expect($section['rows'])->toBeGreaterThan(0);
        expect($section['columns'])->not->toBeEmpty();
    }
});

test('la fiche se télécharge en PDF', function () {
    $response = $this->actingAs($this->adminUser)
        ->get(route('crop-backfill.sheet'))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('fiche-reprise-cultures');
    // Un PDF vide signerait un rendu échoué que le navigateur afficherait sans
    // rien dire.
    expect(strlen($response->getContent()))->toBeGreaterThan(5000);
});

test('un simple lecteur cultures peut imprimer la fiche vierge', function () {
    // Elle ne contient aucune donnée de la ferme et ne modifie rien. Exiger le
    // droit de création pour imprimer un formulaire vide empêcherait justement
    // celui qui doit le remplir de l'obtenir.
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-backfill.sheet'))
        ->assertOk();
});

test('un compte sans droit cultures ne l’obtient pas', function () {
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('module_permissions')
        ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
        ->where('modules.slug', 'cultures')
        ->where('module_permissions.role_id', $this->readonlyUser->role_id)
        ->update(['can_read' => false]);
    \Illuminate\Support\Facades\Cache::forget("rbac_perms_{$this->readonlyUser->id}");

    // L'application intercepte le 403 et redirige plutôt que de rendre une
    // page d'erreur nue : ce qui compte est que la fiche ne soit pas servie.
    $this->actingAs($this->readonlyUser)
        ->get(route('crop-backfill.sheet'))
        ->assertRedirect();
});

test("l'écran de reprise propose la fiche à côté du modèle", function () {
    $this->actingAs($this->adminUser)
        ->get(route('crop-backfill.index'))
        ->assertOk()
        ->assertSee(route('crop-backfill.template'), false)
        ->assertSee(route('crop-backfill.sheet'), false);
});
