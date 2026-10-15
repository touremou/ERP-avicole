<?php

use App\Models\CropCycle;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE CULTURE CLÔTURÉE COMPTE DANS LE RÉSULTAT DU MOIS — SUR LES DEUX ÉCRANS.
 *
 * Le compte de résultat ajoutait la recette des cycles de culture clôturés et
 * leur coût des marchandises vendues ; la synthèse du tableau de bord, qui
 * répond à la même question, les ignorait. Et le détail « marge par culture »
 * du compte de résultat prenait les cycles DATÉS dans la période, quand son
 * total ne retenait que les cycles réellement clôturés : un cycle rouvert
 * figurait au détail mais pas au total.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

function cycleDeCulture(int $ferme, string $culture, string $statut, float $recette): CropCycle
{
    $parcelle = Plot::create(['farm_id' => $ferme, 'name' => 'P-' . $culture, 'area_ha' => 1, 'status' => Plot::STATUS_DISPONIBLE]);

    return CropCycle::create([
        'farm_id' => $ferme, 'plot_id' => $parcelle->id, 'crop_name' => $culture, 'area_used_ha' => 1,
        'planting_date' => now()->subDays(120)->toDateString(), 'closing_date' => now()->toDateString(),
        'status' => $statut, 'total_acquisition_cost' => 200000, 'additional_costs' => 100000,
        'total_revenue' => $recette,
    ]);
}

test('le tableau de bord et le compte de résultat donnent le même résultat du mois', function () {
    cycleDeCulture($this->farm->id, 'Tomate', CropCycle::STATUS_TERMINE, 1_500_000);

    $synthese = $this->get(route('dashboard'))->assertOk()->viewData('financial');
    $compte = $this->get(route('reports.profit_loss', [
        'date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->endOfMonth()->toDateString(),
    ]))->assertOk();

    expect($synthese['ca_total'])->toEqual($compte->viewData('totalRevenue'))
        ->and($synthese['net_margin'])->toEqual($compte->viewData('netResult'))
        ->and($synthese['ca_total'])->toEqual(1_500_000);
});

test('un cycle rouvert ne figure ni au total ni au détail par culture', function () {
    cycleDeCulture($this->farm->id, 'Oignon', CropCycle::STATUS_EN_COURS, 800_000);

    $compte = $this->get(route('reports.profit_loss', [
        'date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->endOfMonth()->toDateString(),
    ]))->assertOk();

    expect(collect($compte->viewData('cropMargin'))->pluck('crop')->all())->not->toContain('Oignon')
        ->and($compte->viewData('totalRevenue'))->toEqual(0);
});

test('la marge par espèce suit les règles du compte de résultat : aliment consommé, incidents compris', function () {
    $this->setUpBaseData();
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $poulet = App\Models\Species::where('slug', 'poulet')->first();
    $lot = App\Models\Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'species_id' => $poulet->id, 'arrival_date' => now()->subYear()->toDateString(), 'total_acquisition_cost' => 0,
    ]);

    // 1 000 kg d'aliment achetés, 100 kg consommés à 400 : la charge est 40 000.
    App\Models\FeedPurchase::withoutEvents(fn () => App\Models\FeedPurchase::forceCreate([
        'farm_id' => $this->farm->id, 'batch_id' => $lot->id, 'feed_type' => 'Chair', 'quantity' => 1000, 'unit' => 'KG',
        'unit_price' => 400, 'total_price' => 400000, 'purchase_date' => now()->toDateString(),
    ]));
    Illuminate\Support\Facades\DB::table('daily_checks')->insert([
        'farm_id' => $this->farm->id, 'batch_id' => $lot->id, 'check_date' => now()->toDateString(),
        'feed_consumed' => 100, 'feed_unit_cost' => 400, 'mortality' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    App\Models\HealthIncident::create([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id, 'building_id' => $this->building->id,
        'batch_id' => $lot->id, 'incident_date' => now()->toDateString(), 'mortality_count' => 0, 'symptoms' => 'Toux',
        'status' => App\Models\HealthIncident::STATUS_DIAGNOSED, 'severity' => App\Models\HealthIncident::SEVERITY_MODERATE,
        'treatment_cost' => 25000,
    ]);

    $marge = collect($this->get(route('reports.profit_loss', [
        'date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->endOfMonth()->toDateString(),
    ]))->assertOk()->viewData('speciesMargin'))->firstWhere('species', $poulet->name_fr);

    expect($marge['cost'])->toEqual(65000);
});
