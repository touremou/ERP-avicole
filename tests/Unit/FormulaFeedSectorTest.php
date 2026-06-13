<?php

use App\Actions\Provenderie\NormalizeFormulaNameAction;
use App\Models\Formula;
use App\Models\ProductionType;
use App\Models\Species;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
});

function makeFormula(string $name, string $slug, ?int $speciesId): Formula
{
    $pt = ProductionType::resolveOrCreate($slug, $speciesId);

    return Formula::factory()->create([
        'name'               => $name,
        'species_id'         => $pt->species_id,
        'production_type_id' => $pt->id,
        'target_type'        => $slug,
    ]);
}

test('Formula::feedSector dérive le secteur du type de production', function () {
    $chevreId = Species::where('slug', 'chevre')->value('id');
    $tilapiaId = Species::where('slug', 'tilapia')->value('id');

    expect(makeFormula('Chair Finition', 'chair', null)->feedSector())->toBe('Chair')
        ->and(makeFormula('Poule Pondeuse', 'ponte', null)->feedSector())->toBe('Ponte')
        ->and(makeFormula('Chèvre Laitière', 'laitiere', $chevreId)->feedSector())->toBe('Laitière')
        ->and(makeFormula('Tilapia Grossissement', 'grossissement', $tilapiaId)->feedSector())->toBe('Grossissement');
});

test('la normalisation cible la phase volaille exacte (rétrocompat)', function () {
    $action = new NormalizeFormulaNameAction();

    $chair = makeFormula('CHAIR FINITION', 'chair', null);
    $ponte = makeFormula('PONTE 1', 'ponte', null);

    expect($action->execute($chair->name, $chair))->toBe('Chair Finition')
        ->and($action->execute($ponte->name, $ponte))->toBe('Ponte 1 (Pic de ponte)');
});

test('la normalisation cible la bonne phase pour une espèce non-volaille', function () {
    $action = new NormalizeFormulaNameAction();
    $chevreId = Species::where('slug', 'chevre')->value('id');
    $tilapiaId = Species::where('slug', 'tilapia')->value('id');

    $laitiere = makeFormula('CHÈVRE LAITIÈRE PRODUCTION', 'laitiere', $chevreId);
    $alevinage = makeFormula('TILAPIA ALEVINAGE CROISSANCE', 'alevinage', $tilapiaId);

    expect($action->execute($laitiere->name, $laitiere))->toBe('Laitière Production')
        ->and($action->execute($alevinage->name, $alevinage))->toBe('Alevinage Croissance');
});

test('une formule non-volaille sans phase reconnue retombe sur la 1re phase de son secteur', function () {
    $action = new NormalizeFormulaNameAction();
    $moutonId = Species::where('slug', 'mouton')->value('id');

    // « Engraissement » sans descripteur de phase reconnaissable.
    $formula = makeFormula('OVIN ENGRAISSEMENT', 'engraissement', $moutonId);

    expect($action->execute($formula->name, $formula))
        ->toBe(\App\Models\Batch::FEED_PHASES['Engraissement'][0]);
});
