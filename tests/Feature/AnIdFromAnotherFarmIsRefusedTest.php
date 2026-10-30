<?php

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN IDENTIFIANT D'UN AUTRE SITE EST REFUSÉ — AU BUREAU COMME AU TERRAIN.
 *
 * La synchronisation bornait ses identifiants à la ferme courante
 * (farmScopedExists). Les formulaires du bureau validaient par
 * `exists:batches,id` — une requête SANS la portée de ferme : l'identifiant
 * d'un lot de n'importe quel site passait. Une dépense s'y rattachait
 * (CreateExpense enregistre l'identifiant tel quel) ; les autres saisies
 * échouaient en erreur serveur au lieu d'un message. Une règle :
 * App\Support\Ferme::existe, pour les deux portes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->autreSite = Farm::create(['code' => 'SITE-B', 'name' => 'Site B', 'is_active' => true]);
    $this->lotAilleurs = Batch::withoutEvents(fn () => Batch::factory()->create(['farm_id' => $this->autreSite->id]));
    $this->lotIci = Batch::factory()->create(['farm_id' => $this->farm->id]);
});

function depenseSurLot(array $champs): array
{
    return array_merge(['category' => array_key_first(Expense::CATEGORIES), 'label' => 'Transport',
        'amount' => 5000, 'expense_date' => today()->toDateString(),
        'payment_method' => array_key_first(Expense::PAYMENT_METHODS)], $champs);
}

test('une dépense ne se rattache pas au lot d’un autre site', function () {
    $this->post(route('expenses.store'), depenseSurLot(['batch_id' => $this->lotAilleurs->id]))
        ->assertSessionHasErrors('batch_id');

    expect(Expense::withoutGlobalScopes()->where('batch_id', $this->lotAilleurs->id)->exists())->toBeFalse();
});

test('le lot du site courant, lui, est accepté', function () {
    $this->post(route('expenses.store'), depenseSurLot(['batch_id' => $this->lotIci->id]))
        ->assertSessionHasNoErrors();

    expect(Expense::where('batch_id', $this->lotIci->id)->exists())->toBeTrue();
});

test('aucune table que le terrain borne à la ferme n’est validée sans borne au bureau', function () {
    // Les tables bornées par la synchronisation : la référence, lue dans son code.
    preg_match_all("/farmScopedExists\\('([a-z_]+)'\\)/", file_get_contents(app_path('Services/Sync/SyncService.php')), $m);
    $tables = array_unique($m[1]);
    expect($tables)->not->toBeEmpty();

    $ecarts = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http'))) as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }
        $contenu = file_get_contents($fichier->getPathname());
        foreach ($tables as $table) {
            if (str_contains($contenu, "exists:{$table},")) {
                $ecarts[] = str_replace(base_path() . '/', '', $fichier->getPathname()) . " → exists:{$table}";
            }
        }
    }

    expect($ecarts)->toBe([]);
});
