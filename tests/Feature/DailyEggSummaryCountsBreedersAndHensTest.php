<?php

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\ProductionType;
use App\Models\Species;
use App\Services\EggAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE HDP DU MATIN N'ÉTAIT PAS CELUI DU TABLEAU DE BORD.
 *
 * Deux écrans calculent le taux de ponte de la ferme : le tableau de bord (le
 * jour même) et le résumé quotidien envoyé par WhatsApp (la veille). Ils ne
 * retenaient ni les mêmes lots ni le même dénominateur :
 *
 *   • LES LOTS. Le tableau de bord prend tout lot qui suit ses œufs, en âge
 *     de pondre ou ayant pondu. Le résumé ne prenait que le type « ponte » :
 *     une bande de REPRODUCTEURS, qui pond les œufs à couver, en était absente.
 *     Ses œufs manquaient au total, son absence de collecte ne sonnait jamais ;
 *   • LE DÉNOMINATEUR. Le HDP par lot du résumé, comme le tableau de bord,
 *     compte les poules (`Batch::effectifPondeur`). Le HDP GLOBAL du même
 *     résumé divisait par tout l'effectif, coqs compris.
 *
 * Mesuré — 500 pondeuses, 400 œufs ; 220 reproducteurs (20 coqs, 200 poules),
 * 120 œufs : le résumé annonçait 400 œufs et « HDP global 80 % (500 poules) »,
 * le tableau de bord 520 œufs et 74,3 %. La sélection vit désormais dans
 * `Batch::pondeusesSur()`, lue par les trois écrans de la ponte, et le dénominateur
 * global est celui de chaque lot.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $poulet = Species::where('slug', 'poulet')->value('id');
    $this->typePonte = ProductionType::where('species_id', $poulet)->where('slug', 'ponte')->firstOrFail();
    $this->typeRepro = ProductionType::where('species_id', $poulet)->where('slug', 'reproducteur')->firstOrFail();
    $this->lieu = [$this->farm->id, $this->building->id];
});

/** Un lot en âge de pondre, avec ses coqs et ses poules. */
function bandeEnPonte(array $lieu, ProductionType $type, int $coqs, int $poules): Batch
{
    return Batch::factory()->create([
        'farm_id' => $lieu[0], 'building_id' => $lieu[1],
        'production_type_id' => $type->id, 'status' => 'Actif',
        'arrival_date' => today()->subDays(200)->toDateString(),
        'birth_date'   => today()->subDays(200)->toDateString(),
        'initial_quantity' => $coqs + $poules, 'current_quantity' => $coqs + $poules,
        'qty_males' => $coqs, 'qty_females' => $poules,
    ]);
}

function collecteDHier(Batch $lot, int $oeufs): void
{
    EggProduction::create([
        'farm_id' => $lot->farm_id, 'batch_id' => $lot->id,
        'production_date' => today()->subDay()->toDateString(),
        'total_eggs_collected' => $oeufs,
    ]);
}

test('les REPRODUCTEURS comptent dans la ponte du matin, sur leurs seules poules', function () {
    $pondeuses = bandeEnPonte($this->lieu, $this->typePonte, 0, 500);
    $repro = bandeEnPonte($this->lieu, $this->typeRepro, 20, 200);

    expect($repro->fresh()->tracksEggs())->toBeTrue();

    collecteDHier($pondeuses, 400);
    collecteDHier($repro, 120);

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect($rapport['total_eggs'])->toEqual(520)
        ->and($rapport['total_hens'])->toBe(700)
        ->and($rapport['global_hdp'])->toBe(74.3)
        ->and(collect($rapport['batch_reports'])->firstWhere('batch.id', $repro->id)['hdp'])->toBe(60.0);
});

test('une bande de reproducteurs sans collecte est signalée', function () {
    bandeEnPonte($this->lieu, $this->typePonte, 0, 500);
    $repro = bandeEnPonte($this->lieu, $this->typeRepro, 20, 200);

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect(collect($rapport['irregularities'])
        ->where('type', 'missing_collection')
        ->pluck('batch'))->toContain($repro->code);
});

test('les coqs ne diluent plus le HDP global', function () {
    $repro = bandeEnPonte($this->lieu, $this->typeRepro, 20, 200);
    collecteDHier($repro, 160);

    $rapport = (new EggAnalysisService())->getDailyReport();

    // 160 œufs pour 200 poules = 80 % — et non 160 / 220 = 72,7 %.
    expect($rapport['total_hens'])->toBe(200)
        ->and($rapport['global_hdp'])->toBe(80.0);
});

test('le tableau de bord et le résumé retiennent les MÊMES lots', function () {
    $pondeuses = bandeEnPonte($this->lieu, $this->typePonte, 0, 500);
    $repro = bandeEnPonte($this->lieu, $this->typeRepro, 20, 200);
    bandeEnPonte($this->lieu, $this->typePonte, 0, 300)->update(['arrival_date' => today()->subDays(20), 'birth_date' => today()->subDays(20)]);

    $retenus = Batch::pondeusesSur(Batch::active()->get(), today()->subDay())->pluck('id')->sort()->values()->all();

    expect($retenus)->toBe(collect([$pondeuses->id, $repro->id])->sort()->values()->all());
});
