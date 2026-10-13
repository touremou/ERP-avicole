<?php

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE BANDE CRÉÉE AU TERRAIN SUIT LES RÈGLES DU WEB.
 *
 * La synchronisation créait la bande à part (`updateOrCreate`), sans passer
 * par l'action du web (CreateBatch). Une bande mise en place depuis le
 * téléphone échappait donc :
 *   • au contrôle de CAPACITÉ du bâtiment (sous verrou) ;
 *   • au passage du bâtiment « Occupé » — il restait offert à une 2e bande ;
 *   • à la COMPATIBILITÉ bâtiment / espèce (des moutons dans un poulailler) ;
 *   • au refus d'une arrivée FUTURE ;
 *   • à l'unicité du CODE : un doublon butait sur l'index unique, l'erreur
 *     était « interne », et le terrain retentait indéfiniment.
 * Et son effectif initial comptait les morts du transport, quand le web ne
 * compte que les vivants : la même bande avait deux effectifs selon la porte.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    Sanctum::actingAs($this->adminUser);
    // L'API résout la ferme par le pivot farm_user (SetApiFarmContext).
    Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_owner' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
    );
    $this->poulailler = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-CHAIR', 'type' => 'chair',
        'status' => Building::STATUS_VIDE, 'capacity' => 500,
    ]);
});

function arriveeTerrain(Tests\TestCase $test, int $ferme, int $batiment, array $champs = []): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'batch.upsert',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(), 'code' => 'TER-' . Str::random(5), 'type' => 'chair',
            'building_id' => $batiment, 'initial_quantity' => 300, 'current_quantity' => 300,
            'arrival_date' => today()->toDateString(), 'buy_price_per_unit' => 5000,
            'updated_at' => now()->toIso8601String(),
        ], $champs),
    ]]], ['X-Farm-Id' => (string) $ferme])->assertOk()->json('results.0');
}

test('le bâtiment passe « Occupé », comme au web', function () {
    expect(arriveeTerrain($this, $this->farm->id, $this->poulailler->id)['status'])->toBe('success')
        ->and($this->poulailler->fresh()->status)->not->toBe(Building::STATUS_VIDE);
});

test('la capacité du bâtiment est contrôlée — refus en « À corriger », pas en erreur', function () {
    $resultat = arriveeTerrain($this, $this->farm->id, $this->poulailler->id, ['initial_quantity' => 800, 'current_quantity' => 800]);

    expect($resultat['status'])->toBe('conflict')
        ->and($resultat['message'])->toContain('Capacité insuffisante')
        ->and(Batch::withoutGlobalScopes()->count())->toBe(0);
});

test('une arrivée future est refusée, comme au web', function () {
    expect(arriveeTerrain($this, $this->farm->id, $this->poulailler->id, ['arrival_date' => today()->addDay()->toDateString()])['status'])
        ->toBe('validation_failed');
});

test('un code déjà pris est refusé proprement — et le rejeu du même lot reste accepté', function () {
    $uuid = (string) Str::uuid();
    expect(arriveeTerrain($this, $this->farm->id, $this->poulailler->id, ['uuid' => $uuid, 'code' => 'TER-UNIQUE'])['status'])->toBe('success');

    // Rejeu du MÊME lot : son propre code ne le bloque pas.
    expect(arriveeTerrain($this, $this->farm->id, $this->poulailler->id, ['uuid' => $uuid, 'code' => 'TER-UNIQUE'])['status'])->toBe('success')
        // Un AUTRE lot sur le même code : refus de validation, pas « erreur interne » rejouée sans fin.
        ->and(arriveeTerrain($this, $this->farm->id, $this->poulailler->id, ['code' => 'TER-UNIQUE'])['status'])->toBe('validation_failed');
});

test('des moutons ne se logent pas dans un poulailler', function () {
    $typeOvin = App\Models\ProductionType::where('slug', 'engraissement')
        ->whereHas('species', fn ($q) => $q->where('slug', 'mouton'))->value('id');

    $resultat = arriveeTerrain($this, $this->farm->id, $this->poulailler->id, ['type' => 'engraissement', 'production_type_id' => $typeOvin]);

    expect($resultat['status'])->toBe('validation_failed')
        ->and($resultat['errors'])->toHaveKey('building_id');
});
