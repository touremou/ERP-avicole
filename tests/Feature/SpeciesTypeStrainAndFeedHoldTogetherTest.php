<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\ProductionType;
use App\Models\RawMaterial;
use App\Models\Species;
use Database\Seeders\ProductionNormSeeder;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ESPÈCE, TYPE DE PRODUCTION, SOUCHE ET ALIMENT SE TIENNENT — AU SERVEUR.
 *
 * Les écrans filtraient ces listes par espèce ; le serveur ne vérifiait que
 * leur existence. Et la mise en lot du TERRAIN enregistrait un lot sans type
 * ni espèce : des pondeuses traitées en poulets de chair (aliment Chair, 42 j).
 * Sur les vrais semeurs.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
    $this->seed(ProductionNormSeeder::class);

    $this->poulet = Species::where('slug', 'poulet')->first();
    $this->dinde = Species::where('slug', 'dinde')->first();
    $this->chairPoulet = ProductionType::where('species_id', $this->poulet->id)->where('slug', 'chair')->first();
    $this->chairDinde = ProductionType::where('species_id', $this->dinde->id)->where('slug', 'chair')->first();
    $this->pontePoulet = ProductionType::where('species_id', $this->poulet->id)->where('slug', 'ponte')->first();
    $this->batiment = Building::factory()->create(['type' => 'chair', 'capacity' => 10_000, 'farm_id' => session('current_farm_id')]);
});

function creerBande(object $t, array $extra = [])
{
    return $t->post(route('batches.store'), array_merge([
        'code' => 'PC-'.Str::random(4), 'building_id' => $t->batiment->id, 'type' => 'chair',
        'species_id' => $t->poulet->id, 'production_type_id' => $t->chairPoulet->id,
        'arrival_date' => now()->toDateString(), 'buy_price_per_unit' => 500, 'qty_alive' => 100,
    ], $extra));
}

// ─── BANDE : TYPE ET SOUCHE DE L'ESPÈCE ───

test('un poulet ne peut pas porter le type « Dinde de chair »', function () {
    $this->actingAs($this->adminUser);
    creerBande($this, ['production_type_id' => $this->chairDinde->id])->assertSessionHasErrors('production_type_id');
});

test('un poulet ne peut pas porter la souche « Dinde BUT 6 » — une souche de poulet, ou libre, passe', function () {
    $this->actingAs($this->adminUser);

    creerBande($this, ['model_name' => 'Dinde BUT 6'])->assertSessionHasErrors('model_name');
    creerBande($this, ['model_name' => 'Ross 308'])->assertSessionHasNoErrors();
    creerBande($this, ['model_name' => 'Souche du village'])->assertSessionHasNoErrors();
});

test('la planification refuse aussi un type d’une autre espèce', function () {
    $this->actingAs($this->adminUser)->post(route('planning.store'), [
        'building_id' => $this->batiment->id, 'batch_type' => 'chair',
        'species_id' => $this->poulet->id, 'production_type_id' => $this->chairDinde->id,
        'planned_quantity' => 100, 'planned_arrival_date' => now()->addDays(10)->toDateString(),
    ])->assertSessionHasErrors('production_type_id');
});

// ─── MISE EN LOT DU TERRAIN ───

function mettreEnLotAuTerrain(object $t, \App\Models\User $user, array $payload)
{
    DB::table('farm_user')->insertOrIgnore(['farm_id' => session('current_farm_id'), 'user_id' => $user->id, 'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($user);

    return $t->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'batch.upsert',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(), 'code' => 'TERRAIN-'.Str::random(4), 'building_id' => $t->batiment->id,
            'initial_quantity' => 500, 'current_quantity' => 500,
            'arrival_date' => today()->toDateString(), 'updated_at' => now()->toISOString(),
        ], $payload),
    ]]]);
}

test('des PONDEUSES mises en lot au terrain gardent leur type et leur espèce', function () {
    mettreEnLotAuTerrain($this, $this->adminUser, ['code' => 'PONTE-T', 'type' => 'ponte', 'production_type_id' => $this->pontePoulet->id])
        ->assertOk()->assertJsonPath('results.0.status', 'success');

    $lot = Batch::where('code', 'PONTE-T')->first();

    expect($lot->production_type_id)->toBe($this->pontePoulet->id)
        ->and($lot->species_id)->toBe($this->poulet->id)
        ->and($lot->feedSector())->toBe('Ponte');
});

test('un ancien téléphone (slug seul) n’enregistre plus un lot SANS type', function () {
    mettreEnLotAuTerrain($this, $this->adminUser, ['code' => 'ANCIEN-T', 'type' => 'ponte'])->assertOk();

    $lot = Batch::where('code', 'ANCIEN-T')->first();

    // « ponte » existe pour plusieurs espèces : repli sur le poulet, jamais rien.
    expect($lot->production_type_id)->toBe($this->pontePoulet->id)
        ->and($lot->species_id)->toBe($this->poulet->id);
});

test('le terrain ne peut pas déclarer une espèce contraire à son type', function () {
    mettreEnLotAuTerrain($this, $this->adminUser, ['code' => 'MIX-T', 'type' => 'chair', 'production_type_id' => $this->chairDinde->id, 'species_id' => $this->poulet->id])
        ->assertOk()->assertJsonPath('results.0.status', 'validation_failed');

    expect(Batch::where('code', 'MIX-T')->exists())->toBeFalse();
});

// ─── ALIMENT DU POINTAGE ───

test('un pointage de poulets de chair refuse un aliment de PONTE — bureau et terrain', function () {
    $lot = Batch::factory()->create([
        'building_id' => $this->batiment->id, 'species_id' => $this->poulet->id,
        'production_type_id' => $this->chairPoulet->id, 'status' => 'Actif', 'current_quantity' => 500,
    ]);

    expect($lot->accepteAliment('Ponte 1 (Pic de ponte)'))->toBeFalse()
        ->and($lot->accepteAliment('Chair Croissance'))->toBeTrue()
        ->and($lot->accepteAliment('Provende maison 3B'))->toBeTrue();   // nom libre : accepté

    $this->actingAs($this->adminUser)->post(route('daily-checks.store'), [
        'batch_id' => $lot->id, 'check_date' => now()->toDateString(), 'mortality' => 0,
        'feed_consumed' => 0, 'feed_type' => 'Ponte 1 (Pic de ponte)',
    ])->assertSessionHasErrors('feed_type');

    DB::table('farm_user')->insertOrIgnore(['farm_id' => session('current_farm_id'), 'user_id' => $this->adminUser->id, 'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($this->adminUser);
    $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'daily_check.create',
        'payload' => ['uuid' => (string) Str::uuid(), 'batch_id' => $lot->id, 'check_date' => now()->toDateString(),
                      'mortality' => 0, 'feed_type' => 'Ponte 1 (Pic de ponte)'],
    ]]])->assertJsonPath('results.0.status', 'validation_failed');

    expect(DailyCheck::where('batch_id', $lot->id)->exists())->toBeFalse();
});

// ─── FORMULE D'ALIMENT ───

test('une formule « poulet » ne peut pas porter le type « Dinde de chair »', function () {
    $mp = RawMaterial::create(['farm_id' => session('current_farm_id'), 'name' => 'Maïs', 'unit' => 'kg', 'stock_qty' => 100, 'unit_cost' => 300, 'is_active' => true]);

    $this->actingAs($this->adminUser)->post(route('formulas.store'), [
        'name' => 'Démarrage', 'code' => 'F-MIX', 'target_type' => 'chair', 'total_batch_weight' => 1000,
        'species_id' => $this->poulet->id, 'production_type_id' => $this->chairDinde->id,
        'ingredients' => [['id' => $mp->id, 'percentage' => '100']],
    ])->assertSessionHasErrors('production_type_id');
});
