<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN RENDEMENT CARCASSE SE JUGE DANS LA BANDE DE SON ESPÈCE.
 *
 * Le message de fin d'abattage et les statistiques par famille lisaient la
 * bande de l'espèce (config butchery.carcass_yield). Le tableau de bord de
 * l'abattoir et SlaughterResult::yield_status jugeaient tout animal aux seuils
 * de la volaille (70 / 65 %) : un mouton à 48 %, normal, s'affichait en rouge.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
});

function abattageExecute(int $ferme, int $batiment, int $demandeur, string $espece, string $type, float $rendement): SlaughterOrder
{
    $sp = Species::where('slug', $espece)->first();
    $lot = Batch::factory()->create(['farm_id' => $ferme, 'building_id' => $batiment, 'species_id' => $sp->id, 'type' => $type,
        'production_type_id' => ProductionType::where('slug', $type)->where('species_id', $sp->id)->value('id')]);
    $ordre = SlaughterOrder::create(['order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $lot->id,
        'planned_date' => today()->toDateString(), 'planned_quantity' => 10, 'actual_quantity' => 10,
        'total_live_weight_kg' => 100, 'actual_date' => today()->toDateString(), 'status' => 'termine', 'requested_by' => $demandeur]);
    SlaughterResult::create(['slaughter_order_id' => $ordre->id, 'total_carcass_weight_kg' => $rendement,
        'carcass_yield_percent' => $rendement, 'presentation' => 'brut', 'condemned_count' => 0,
        'avg_live_weight_kg' => 10, 'avg_carcass_weight_kg' => $rendement / 10, 'execution_date' => today()->toDateString()]);

    return $ordre->fresh();
}

test('un mouton à 48 % est un BON rendement ; un poulet à 68 % est seulement acceptable', function () {
    $mouton = abattageExecute($this->farm->id, $this->building->id, $this->adminUser->id, 'mouton', 'engraissement', 48);
    $poulet = abattageExecute($this->farm->id, $this->building->id, $this->adminUser->id, 'poulet', 'chair', 68);

    expect($mouton->result->yield_status)->toBe('bon')
        ->and($poulet->result->yield_status)->toBe('acceptable');
});

test('le tableau de bord de l’abattoir colore le mouton en vert', function () {
    abattageExecute($this->farm->id, $this->building->id, $this->adminUser->id, 'mouton', 'engraissement', 48);

    $html = $this->get(route('slaughter.dashboard'))->assertOk()->getContent();
    expect($html)->toMatch('/bg-emerald-50 text-emerald-600[^>]*>\s*48(\.00)?%/');
});
