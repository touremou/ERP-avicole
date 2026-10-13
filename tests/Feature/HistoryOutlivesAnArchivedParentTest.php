<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « OUPS ! » SUR LA FICHE D'UN LOT ANCIEN.
 *
 * Rejoué sur une copie locale : un bâtiment, un employé ou un fournisseur ne
 * peut être mis à la corbeille tant qu'un lot ACTIF l'utilise — mais un lot
 * CLÔTURÉ, si. Sa fiche lisait alors `$batch->building->name` : la relation
 * excluait le bâtiment archivé, rendait `null`, et la page tombait en erreur
 * 500 — l'écran « Oups ! ». Archiver un bâtiment rendait illisible tout
 * l'historique des bandes qu'il avait abritées.
 *
 * Règle, déclarée UNE fois dans les relations (`withTrashed()` sur les 97
 * `belongsTo` vers un modèle archivable) : un enregistrement garde son parent,
 * même archivé. Une vente reste celle de son client, un pointage celui de son
 * lot, un relevé celui de son bâtiment.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
});

test('la fiche d’un lot clôturé reste lisible quand son bâtiment et son fournisseur sont archivés', function () {
    $batiment = Building::factory()->create(['name' => 'BAT-ANCIEN', 'type' => 'mixte']);
    $fournisseur = Provider::factory()->create(['name' => 'Couvoir disparu']);

    $lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $batiment->id, 'provider_id' => $fournisseur->id,
        'arrival_date' => today()->subDays(60), 'status' => 'Terminé', 'closing_date' => today()->subDays(5),
    ]);

    // Autorisé : aucun lot ACTIF ne les utilise.
    $batiment->delete();
    $fournisseur->delete();

    $this->get(route('batches.show', $lot))
        ->assertOk()
        ->assertSee('BAT-ANCIEN');

    $this->get(route('batches.archives'))->assertOk();

    expect($lot->fresh()->building?->name)->toBe('BAT-ANCIEN')
        ->and($lot->fresh()->provider?->name)->toBe('Couvoir disparu');
});

test('une vente garde son client archivé', function () {
    $client = \App\Models\Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-PARTI', 'name' => 'Client parti',
        'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif', 'credit_limit' => 0,
    ]);
    $vente = \App\Models\Sale::create([
        'farm_id' => $this->farm->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'reference' => 'FA-PARTI',
        'client_id' => $client->id, 'sale_date' => today()->toDateString(), 'type' => 'facture_tva',
        'status' => 'valide', 'subtotal' => 10000, 'total_amount' => 10000, 'paid_amount' => 10000,
        'payment_status' => 'solde', 'user_id' => auth()->id(),
    ]);

    $client->delete();

    expect($vente->fresh()->client?->name)->toBe('Client parti');
    $this->get(route('sales.show', $vente))->assertOk()->assertSee('Client parti');
});

test('toutes les relations vers un modèle archivable gardent leur parent', function () {
    $archivables = collect(glob(app_path('Models/*.php')))
        ->filter(fn ($f) => str_contains(file_get_contents($f), 'use SoftDeletes'))
        ->map(fn ($f) => basename($f, '.php'))
        ->implode('|');

    $sansHistorique = [];
    foreach (glob(app_path('Models/*.php')) as $fichier) {
        preg_match_all("/belongsTo\\(\\\\?(?:App\\\\Models\\\\)?($archivables)::class[^;]*;/", file_get_contents($fichier), $relations);
        foreach ($relations[0] as $relation) {
            if (! str_contains($relation, 'withTrashed()')) {
                $sansHistorique[] = basename($fichier) . ' : ' . $relation;
            }
        }
    }

    expect($sansHistorique)->toBe([]);
});
