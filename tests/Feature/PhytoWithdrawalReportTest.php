<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Harvest;
use App\Models\Plot;
use App\Services\PhytoWithdrawalService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CONFRONTATION DÉLAI AVANT RÉCOLTE ↔ RÉCOLTE.
 *
 * `preharvest_days` était validé à trois points d'entrée puis jeté par
 * RecordCropInput : la garde de délai avant récolte n'avait jamais rien bloqué.
 * Le stockage est corrigé, mais l'historique n'a AUCUN délai en base et rien ne
 * peut le reconstituer.
 *
 * Ces tests verrouillent la seule chose que le rapport peut honnêtement dire :
 * distinguer le constat ÉTABLI (délai connu et dépassé) de l'inconnu (délai
 * absent → à confronter à la notice), sans jamais présenter le second comme
 * une conformité.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function phytoCycle(): CropCycle
{
    // setUpRbac renseigne la ferme courante ; les helpers de module ne peuvent
    // pas lire $this->farm (propriété protégée), d'où la session.
    $farmId = session('current_farm_id');

    $plot = Plot::create([
        'farm_id' => $farmId, 'name' => 'Parcelle phyto',
        'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE,
    ]);

    return CropCycle::create([
        'farm_id'       => $farmId,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Gombo',
        'area_used_ha'  => 1,
        'planting_date' => now()->subDays(120)->toDateString(),
        'status'        => CropCycle::STATUS_RECOLTE,
    ]);
}

function phytoTreatment(CropCycle $cycle, string $date, ?int $dar): CropInput
{
    return CropInput::create([
        'farm_id'         => $cycle->farm_id,
        'crop_cycle_id'   => $cycle->id,
        'type'            => 'phyto',
        'name'            => 'Insecticide X',
        'quantity'        => 1,
        'unit'            => 'L',
        'unit_cost'       => 10000,
        'total_cost'      => 10000,
        'input_date'      => $date,
        'preharvest_days' => $dar,
    ]);
}

function phytoHarvest(CropCycle $cycle, string $date): Harvest
{
    return Harvest::create([
        'farm_id'       => $cycle->farm_id,
        'crop_cycle_id' => $cycle->id,
        'harvest_date'  => $date,
        'quantity'      => 50,
        'unit'          => 'kg',
        'quality'       => 'bonne',
        'destination'   => 'vente',
    ]);
}

test('un délai connu et dépassé est un constat établi', function () {
    $cycle = phytoCycle();
    // 14 jours de délai, récolte 5 jours après : les résidus y sont.
    phytoTreatment($cycle, now()->subDays(20)->toDateString(), 14);
    phytoHarvest($cycle, now()->subDays(15)->toDateString());

    $report = app(PhytoWithdrawalService::class)->confrontations();

    expect($report['counts']['depasse'])->toBe(1);
    expect($report['counts']['a_verifier'])->toBe(0);
    expect($report['rows']->first()['verdict'])->toBe('depasse');
    expect($report['rows']->first()['gap_days'])->toBe(5);
});

test('un délai connu et respecté est conforme', function () {
    $cycle = phytoCycle();
    phytoTreatment($cycle, now()->subDays(30)->toDateString(), 7);
    phytoHarvest($cycle, now()->subDays(20)->toDateString());

    $report = app(PhytoWithdrawalService::class)->confrontations();

    expect($report['counts']['conforme'])->toBe(1);
    expect($report['counts']['depasse'])->toBe(0);
});

test('un délai ABSENT n’est jamais présenté comme conforme', function () {
    // Le cas de tout l'historique : le délai a été saisi puis jeté. Le déclarer
    // conforme serait affirmer une conformité qu'on ne peut pas vérifier.
    $cycle = phytoCycle();
    phytoTreatment($cycle, now()->subDays(20)->toDateString(), null);
    phytoHarvest($cycle, now()->subDays(18)->toDateString());

    $report = app(PhytoWithdrawalService::class)->confrontations();

    expect($report['counts']['a_verifier'])->toBe(1);
    expect($report['counts']['conforme'])->toBe(0);
    expect($report['counts']['depasse'])->toBe(0);
    expect($report['rows']->first()['dar'])->toBeNull();
});

test('une récolte ANTÉRIEURE au traitement n’est pas concernée', function () {
    // Elle ne porte pas les résidus d'un traitement qui n'avait pas eu lieu :
    // la retenir gonflerait le rapport de faux cas et le rendrait illisible.
    $cycle = phytoCycle();
    phytoTreatment($cycle, now()->subDays(10)->toDateString(), 14);
    phytoHarvest($cycle, now()->subDays(25)->toDateString());

    $report = app(PhytoWithdrawalService::class)->confrontations();

    expect($report['rows'])->toHaveCount(0);
    expect($report['treatments'])->toBe(1);
});

test('une récolte au-delà de la fenêtre sort du rapport', function () {
    $cycle = phytoCycle();
    phytoTreatment($cycle, now()->subDays(90)->toDateString(), 7);
    phytoHarvest($cycle, now()->subDays(30)->toDateString()); // 60 jours après

    $report = app(PhytoWithdrawalService::class)->confrontations(now()->subMonths(6), 30);

    expect($report['rows'])->toHaveCount(0);
});

test('un intrant non phytosanitaire est ignoré', function () {
    $cycle = phytoCycle();
    CropInput::create([
        'farm_id' => $cycle->farm_id, 'crop_cycle_id' => $cycle->id,
        'type' => 'engrais', 'name' => 'NPK', 'quantity' => 50, 'unit' => 'kg',
        'unit_cost' => 500, 'total_cost' => 25000,
        'input_date' => now()->subDays(10)->toDateString(),
    ]);
    phytoHarvest($cycle, now()->subDays(5)->toDateString());

    $report = app(PhytoWithdrawalService::class)->confrontations();

    expect($report['treatments'])->toBe(0);
    expect($report['rows'])->toHaveCount(0);
});

test('le plus grave passe en tête du tableau', function () {
    $cycle = phytoCycle();
    phytoTreatment($cycle, now()->subDays(40)->toDateString(), 3);   // conforme
    phytoHarvest($cycle, now()->subDays(30)->toDateString());
    phytoTreatment($cycle, now()->subDays(20)->toDateString(), null); // à vérifier
    phytoHarvest($cycle, now()->subDays(19)->toDateString());
    phytoTreatment($cycle, now()->subDays(10)->toDateString(), 21);  // dépassé
    phytoHarvest($cycle, now()->subDays(8)->toDateString());

    $verdicts = app(PhytoWithdrawalService::class)->confrontations()['rows']->pluck('verdict')->all();

    // L'ordre porte l'urgence : établi, puis incertain, puis conforme.
    expect($verdicts[0])->toBe('depasse');
    expect(array_search('conforme', $verdicts, true))->toBeGreaterThan(array_search('a_verifier', $verdicts, true));
});

test('l’écran avertit sur son niveau de garantie avant d’afficher des chiffres', function () {
    $cycle = phytoCycle();
    phytoTreatment($cycle, now()->subDays(20)->toDateString(), null);
    phytoHarvest($cycle, now()->subDays(18)->toDateString());

    $response = $this->actingAs($this->adminUser)->get(route('crop-reports.withdrawal'))->assertOk();

    // Sans cet avertissement, « 0 dépassé » se lirait comme une conformité
    // prouvée de l'historique, ce qu'il n'est pas.
    // Blade échappe l'apostrophe en &#039; : on assure sur un fragment sans elle.
    $response->assertSee('pas enregistré avant la correction', false);
    $response->assertSee('À vérifier', false);
    $response->assertSee('non enregistré', false);
});

test('la tuile du centre de rapports mène au rapport', function () {
    $this->actingAs($this->adminUser)
        ->get(route('crop-reports.index'))
        ->assertOk()
        ->assertSee(route('crop-reports.withdrawal'), false);
});

test('un compte sans droit cultures ne voit pas le rapport', function () {
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

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-reports.withdrawal'))
        ->assertRedirect();
});
