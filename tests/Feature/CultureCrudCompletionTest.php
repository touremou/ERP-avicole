<?php

use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropSpecies;
use App\Models\CropTransformation;
use App\Models\CropVariety;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\WeatherReading;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function freshCycle(int $farmId): CropCycle
{
    $plot = Plot::create(['farm_id' => $farmId, 'name' => 'Parcelle test', 'area_ha' => 2, 'status' => Plot::STATUS_EN_CULTURE]);

    return CropCycle::create([
        'farm_id'       => $farmId,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Maïs',
        'area_used_ha'  => 2,
        'planting_date' => now()->subMonths(2)->toDateString(),
    ]);
}

// ─── PARCELLES : show + edit dédiés ───

test('la fiche parcelle et son formulaire d\'édition répondent', function () {
    $plot = Plot::create(['farm_id' => $this->farm->id, 'name' => 'Parcelle Nord', 'area_ha' => 3]);

    $this->actingAs($this->readonlyUser)->get(route('plots.show', $plot))->assertOk()->assertSee('Parcelle Nord');
    $this->actingAs($this->managerUser)->get(route('plots.edit', $plot))->assertOk();
});

test('un manager peut mettre à jour une parcelle et est redirigé vers sa fiche', function () {
    $plot = Plot::create(['farm_id' => $this->farm->id, 'name' => 'P', 'area_ha' => 1, 'status' => Plot::STATUS_DISPONIBLE]);

    $this->actingAs($this->managerUser)
        ->put(route('plots.update', $plot), [
            'name'    => 'Parcelle Rénovée',
            'area_ha' => 4.5,
            'status'  => Plot::STATUS_JACHERE,
        ])
        ->assertRedirect(route('plots.show', $plot));

    expect($plot->fresh()->name)->toBe('Parcelle Rénovée')
        ->and((float) $plot->fresh()->area_ha)->toBe(4.5);
});

// ─── CYCLE : édition dédiée ───

test('le formulaire d\'édition du cycle répond et l\'update fonctionne', function () {
    $cycle = freshCycle($this->farm->id);

    $this->actingAs($this->managerUser)->get(route('crop-cycles.edit', $cycle))->assertOk();

    $this->actingAs($this->managerUser)
        ->put(route('crop-cycles.update', $cycle), [
            'crop_name' => 'Maïs jaune',
            'status'    => CropCycle::STATUS_EN_COURS,
        ])
        ->assertRedirect();

    expect($cycle->fresh()->crop_name)->toBe('Maïs jaune');
});

// ─── RÉCOLTES : create / edit / update ───

test('une récolte peut être créée puis éditée via les pages dédiées', function () {
    $cycle = freshCycle($this->farm->id);

    $this->actingAs($this->operatorUser)->get(route('crop-cycles.harvests.create', $cycle))->assertOk();

    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.harvests.store', $cycle), [
            'harvest_date' => now()->toDateString(),
            'quantity'     => 500,
            'unit'         => 'kg',
        ])
        ->assertRedirect(route('crop-cycles.show', $cycle));

    $harvest = Harvest::where('crop_cycle_id', $cycle->id)->firstOrFail();

    $this->actingAs($this->managerUser)->get(route('crop-cycles.harvests.edit', [$cycle, $harvest]))->assertOk();

    $this->actingAs($this->managerUser)
        ->put(route('crop-cycles.harvests.update', [$cycle, $harvest]), [
            'harvest_date' => now()->toDateString(),
            'quantity'     => 750,
            'unit'         => 'kg',
            'quality'      => 'moyen',
        ])
        ->assertRedirect(route('crop-cycles.show', $cycle));

    expect((float) $harvest->fresh()->quantity)->toBe(750.0)
        ->and($harvest->fresh()->quality)->toBe('moyen');
});

// ─── INTRANTS : create / edit / update ───

test('un intrant peut être créé puis édité via les pages dédiées', function () {
    $cycle = freshCycle($this->farm->id);

    $this->actingAs($this->operatorUser)->get(route('crop-cycles.inputs.create', $cycle))->assertOk();

    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.inputs.store', $cycle), [
            'type'       => 'engrais',
            'name'       => 'NPK',
            'quantity'   => 50,
            'unit_cost'  => 1000,
            'input_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('crop-cycles.show', $cycle));

    $input = CropInput::where('crop_cycle_id', $cycle->id)->firstOrFail();

    $this->actingAs($this->managerUser)
        ->put(route('crop-cycles.inputs.update', [$cycle, $input]), [
            'type'       => 'engrais',
            'name'       => 'Urée',
            'total_cost' => 80000,
            'input_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('crop-cycles.show', $cycle));

    expect($input->fresh()->name)->toBe('Urée')
        ->and((float) $input->fresh()->total_cost)->toBe(80000.0);
});

// ─── CAMPAGNE : édition dédiée ───

test('une campagne dispose d\'une page d\'édition fonctionnelle', function () {
    $campaign = CropCampaign::create([
        'farm_id'    => $this->farm->id,
        'name'       => 'Campagne 2026',
        'year'       => 2026,
        'season'     => 'saison_seche',
        'start_date' => now()->toDateString(),
    ]);

    $this->actingAs($this->managerUser)->get(route('crop-campaigns.edit', $campaign))->assertOk();

    $this->actingAs($this->managerUser)
        ->put(route('crop-campaigns.update', $campaign), [
            'name'       => 'Campagne révisée',
            'year'       => 2026,
            'season'     => 'grande_saison_pluies',
            'start_date' => now()->toDateString(),
            'status'     => 'en_cours',
        ])
        ->assertRedirect(route('crop-campaigns.show', $campaign));

    expect($campaign->fresh()->name)->toBe('Campagne révisée');
});

// ─── CATALOGUE : édition espèce + variété ───

test('une espèce et ses variétés sont éditables', function () {
    $species = CropSpecies::create(['type' => 'cereale', 'name' => 'Mil']);
    $variety = $species->varieties()->create(['name' => 'Souna 3']);

    $this->actingAs($this->managerUser)->get(route('crop-catalogue.edit', $species))->assertOk();

    $this->actingAs($this->managerUser)
        ->put(route('crop-catalogue.update', $species), ['type' => 'cereale', 'name' => 'Mil rouge'])
        ->assertRedirect(route('crop-catalogue.show', $species));

    $this->actingAs($this->managerUser)
        ->put(route('crop-catalogue.varieties.update', $variety), ['name' => 'Souna 3 améliorée', 'cycle_days' => 95])
        ->assertRedirect();

    expect($species->fresh()->name)->toBe('Mil rouge')
        ->and($variety->fresh()->name)->toBe('Souna 3 améliorée');
});

// ─── TRANSFORMATION : édition + recalcul rendement ───

test('une transformation est éditable et recalcule son rendement', function () {
    $transformation = CropTransformation::create([
        'farm_id'             => $this->farm->id,
        'batch_number'        => 'TRV-TEST-1',
        'input_product'       => 'Manioc',
        'output_product'      => 'Gari',
        'transformation_type' => 'sechage',
        'input_quantity'      => 100,
        'output_quantity'     => 30,
        'yield_percent'       => 30,
        'production_date'     => now()->toDateString(),
    ]);

    $this->actingAs($this->managerUser)->get(route('crop-transformations.edit', $transformation))->assertOk();

    $this->actingAs($this->managerUser)
        ->put(route('crop-transformations.update', $transformation), [
            'input_product'       => 'Manioc frais',
            'output_product'      => 'Gari',
            'transformation_type' => 'sechage',
            'input_quantity'      => 200,
            'output_quantity'     => 80,
            'production_date'     => now()->toDateString(),
        ])
        ->assertRedirect(route('crop-transformations.show', $transformation));

    expect((float) $transformation->fresh()->yield_percent)->toBe(40.0);
});

// ─── MÉTÉO : édition dédiée ───

test('un relevé météo est éditable via sa page dédiée', function () {
    $reading = WeatherReading::create([
        'farm_id'      => $this->farm->id,
        'reading_date' => now()->toDateString(),
        'rainfall_mm'  => 12,
    ]);

    $this->actingAs($this->managerUser)->get(route('weather.edit', $reading))->assertOk();

    $this->actingAs($this->managerUser)
        ->put(route('weather.update', $reading), [
            'reading_date' => now()->toDateString(),
            'rainfall_mm'  => 30,
            'temperature_max' => 34,
        ])
        ->assertRedirect();

    expect((float) $reading->fresh()->rainfall_mm)->toBe(30.0);
});

// ─── PERMISSIONS : un lecteur seul n'accède pas aux pages d'édition ───

test('un lecteur seul ne peut pas ouvrir le formulaire d\'édition d\'un cycle', function () {
    $cycle = freshCycle($this->farm->id);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-cycles.edit', $cycle))
        ->assertRedirect();
});

// ─── RÉGRESSIONS : dashboard catalogue + cycle sans coûts ───

test('le tableau de bord cultures affiche l\'onglet catalogue sans erreur de clé', function () {
    CropSpecies::create(['type' => 'cereale', 'name' => 'Sorgho'])->varieties()->create(['name' => 'Local']);

    $this->actingAs($this->readonlyUser)
        ->get(route('cultures.dashboard', ['tab' => 'catalogue']))
        ->assertOk();
});

test('un cycle peut être créé sans renseigner les coûts (défaut 0)', function () {
    $plot = Plot::create(['farm_id' => $this->farm->id, 'name' => 'P sans coût', 'area_ha' => 1, 'status' => Plot::STATUS_DISPONIBLE]);

    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.store'), [
            'plot_id'       => $plot->id,
            'crop_name'     => 'Sorgho',
            'area_used_ha'  => 1,
            'planting_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    $cycle = CropCycle::where('crop_name', 'Sorgho')->firstOrFail();
    expect((float) $cycle->total_acquisition_cost)->toBe(0.0)
        ->and((float) $cycle->additional_costs)->toBe(0.0);
});
