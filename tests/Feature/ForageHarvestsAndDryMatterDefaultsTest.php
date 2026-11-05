<?php

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\Plot;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Stock;
use App\Services\BatchAdvisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * AUTONOMIE FOURRAGÈRE : TENEURS PAR NATURE, RÉCOLTES À VENIR.
 *
 * Décisions de l'exploitation :
 *   • sans teneur saisie, un aliment prend la teneur en MS de sa NATURE
 *     (ensilage 33 %, enrubannage 50 %, foin 85 %, paille et concentrés 88 %),
 *     réglable — il comptait à 100 % ;
 *   • un cycle « culture fourragère » porte sa date de récolte et son
 *     rendement en kg de MS : il prolonge l'autonomie s'il arrive avant la
 *     rupture, et l'alerte prévient d'une rupture avant lui.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);

    $type = ProductionType::where('slug', 'reproducteur')
        ->whereHas('species', fn ($q) => $q->where('slug', 'mouton'))->firstOrFail();
    $this->beliers = Batch::factory()->create([
        'code' => 'BEL-F', 'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'production_type_id' => $type->id, 'type' => 'reproducteur', 'model_name' => 'Bélier Djallonké',
        'arrival_date' => today()->subDays(3), 'birth_date' => today()->subYears(2),
        'status' => 'Actif', 'initial_quantity' => 40, 'current_quantity' => 40,
    ]);
    $this->besoin = (float) app(BatchAdvisorService::class)->recommendation($this->beliers)['total']['feed_kg'];
});

function articleFourrager(int $ferme, string $nom, float $kg, array $meta = []): Stock
{
    return Stock::create([
        'item_name' => $nom, 'feed_type' => $nom, 'category' => 'conso', 'unit' => 'KG',
        'current_quantity' => $kg, 'alert_threshold' => 0, 'farm_id' => $ferme, 'metadata' => $meta,
    ]);
}

function cultureFourragere(int $ferme, int $dansJours, ?float $msKg, bool $fourragere = true): CropCycle
{
    $parcelle = Plot::create(['uuid' => (string) Str::uuid(), 'farm_id' => $ferme, 'code' => 'P-' . Str::random(4),
        'name' => 'Parcelle', 'area_ha' => 5, 'status' => 'active']);

    return CropCycle::create([
        'uuid' => (string) Str::uuid(), 'farm_id' => $ferme, 'plot_id' => $parcelle->id,
        'code' => 'CY-' . Str::random(4), 'crop_name' => 'Brachiaria', 'status' => 'en_cours',
        'planting_date' => today()->subMonths(2)->toDateString(),
        'expected_harvest_date' => today()->addDays($dansJours)->toDateString(),
        'is_forage' => $fourragere, 'expected_dm_yield_kg' => $msKg,
    ]);
}

function autonomieFourragere(): ?array
{
    return app(BatchAdvisorService::class)->autonomieDuTroupeau();
}

// ── TENEURS PAR NATURE ───────────────────────────────────────────────────

test('la nature se lit dans le nom à défaut d’être déclarée : un ensilage compte à 33 %', function () {
    articleFourrager($this->farm->id, 'Ensilage de maïs', 1000);

    expect(autonomieFourragere()['stock_kg'])->toEqual(330.0);
});

test('la nature déclarée l’emporte sur le nom, la teneur saisie sur la nature', function () {
    $balles = articleFourrager($this->farm->id, 'Balles rondes', 1000, ['forage_nature' => 'foin']);
    expect($balles->teneurMatiereSeche())->toEqual(85.0);

    $balles->update(['metadata' => ['forage_nature' => 'foin', 'dry_matter_pct' => 80]]);
    expect($balles->fresh()->teneurMatiereSeche())->toEqual(80.0);
});

test('la ferme règle la teneur par défaut d’une nature', function () {
    Setting::set('stocks.dm_default_ensilage', '30');
    articleFourrager($this->farm->id, 'Ensilage de sorgho', 1000);

    expect(autonomieFourragere()['stock_kg'])->toEqual(300.0);
});

// ── RÉCOLTES FOURRAGÈRES À VENIR ─────────────────────────────────────────

test('une récolte avant la rupture prolonge l’autonomie de son rendement en MS', function () {
    articleFourrager($this->farm->id, 'Foin de brachiaria', $this->besoin * 10.5 / 0.85);   // 10 jours
    cultureFourragere($this->farm->id, 5, $this->besoin * 20.6);                            // +20 jours

    $autonomie = autonomieFourragere();

    expect($autonomie['jours_stock_seul'])->toBe(10)
        ->and($autonomie['days'])->toBe(31)              // floor(10,5 + 20,6)
        ->and($autonomie['recoltes_comptees'])->toHaveCount(1)
        ->and($autonomie['rupture_avant_recolte'])->toBeFalse();
});

test('rupture avant la prochaine récolte : l’alerte le dit, même au-dessus du seuil', function () {
    articleFourrager($this->farm->id, 'Foin de brachiaria', $this->besoin * 40.5 / 0.85);   // 40 jours
    cultureFourragere($this->farm->id, 60, $this->besoin * 100);

    $autonomie = autonomieFourragere();

    expect($autonomie['days'])->toBe(40)
        ->and($autonomie['rupture_avant_recolte'])->toBeTrue()
        ->and($autonomie['jours_de_soudure'])->toBe(20)
        ->and($autonomie['niveau'])->toBe('attention');

    // Niveau « attention » : le panneau Silos du tableau de bord (le bandeau
    // n'escalade que le critique).
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Rupture 20 j avant la récolte de Brachiaria', false);
});

test('une culture qui n’est pas fourragère, ou déjà récoltée, ne compte pas', function () {
    articleFourrager($this->farm->id, 'Foin de brachiaria', $this->besoin * 10.5 / 0.85);
    cultureFourragere($this->farm->id, 5, $this->besoin * 20, fourragere: false);
    cultureFourragere($this->farm->id, -3, $this->besoin * 20);

    $autonomie = autonomieFourragere();

    expect($autonomie['days'])->toBe(10)
        ->and($autonomie['recoltes_comptees'])->toBe([])
        ->and($autonomie['rupture_avant_recolte'])->toBeFalse();
});

test('le cycle de culture enregistre sa case fourragère et son rendement en MS', function () {
    $cycle = cultureFourragere($this->farm->id, 30, null, fourragere: false);

    $this->put(route('crop-cycles.update', $cycle), [
        'plot_id' => $cycle->plot_id, 'crop_name' => 'Brachiaria', 'area_used_ha' => 1,
        'planting_date' => $cycle->planting_date->toDateString(),
        'expected_harvest_date' => $cycle->expected_harvest_date->toDateString(),
        'status' => 'en_cours', 'is_forage' => '1', 'expected_dm_yield_kg' => 12000,
    ])->assertSessionHasNoErrors();

    expect($cycle->fresh()->is_forage)->toBeTrue()
        ->and((float) $cycle->fresh()->expected_dm_yield_kg)->toEqual(12000.0);
});
