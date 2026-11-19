<?php

use App\Models\Batch;
use App\Models\CcpRecord;
use App\Models\Client;
use App\Models\DailyCheck;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SlaughterOrder;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SAISIE, DEUX PORTES, LES MÊMES RÈGLES.
 *
 * Audit : la même opération entre par le bureau (FormRequest, contrôleur) et
 * par la synchronisation du terrain (SyncService). Des règles n'étaient
 * tenues qu'à une porte : un type de lot inventé, un lot à 0 sujet, une vente
 * à prix nul ou une facture sans TVA passaient au terrain ; un CCP sans sa
 * mesure s'y inscrivait conforme ; le bureau écrasait un pointage du jour et
 * re-clôturait une tâche faite. Chaque règle est désormais déclarée une fois.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    SitesDuCompte::rattacher($this->adminUser, [$this->farm->id]);
    Sanctum::actingAs($this->adminUser);
    $this->actingAs($this->adminUser);
});

function parLeTerrain(Tests\TestCase $test, string $type, array $payload): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => $type,
        'payload' => ['uuid' => (string) Str::uuid()] + $payload,
    ]]])->assertOk()->json('results.0');
}

function lotDuTerrain(Tests\TestCase $test, array $champs): array
{
    return parLeTerrain($test, 'batch.upsert', array_merge([
        'code' => 'T-' . Str::random(5), 'type' => 'chair',
        'building_id' => \App\Models\Building::where('type', 'chair')->value('id'),
        'initial_quantity' => 100, 'current_quantity' => 100,
        'arrival_date' => today()->subDay()->toDateString(), 'updated_at' => now()->toIso8601String(),
    ], $champs));
}

test('un lot du terrain : type de la liste fermée, au moins un sujet vivant', function () {
    $types = ProductionType::count();

    expect(lotDuTerrain($this, ['type' => 'chiar'])['status'])->not->toBe('success')
        ->and(ProductionType::count())->toBe($types)
        ->and(lotDuTerrain($this, ['initial_quantity' => 0, 'current_quantity' => 0])['status'])->not->toBe('success')
        ->and(lotDuTerrain($this, [])['status'])->toBe('success');
});

test('une vente du terrain : pas de vente à prix nul, pas de facture sans TVA', function () {
    $client = Client::create(['client_id' => 'CLI-T01', 'name' => 'Client Terrain', 'type' => 'particulier',
        'category' => 'detaillant', 'status' => 'actif', 'farm_id' => $this->farm->id]);
    $vente = fn (array $champs) => parLeTerrain($this, 'sale.create', array_merge([
        'client_id' => $client->id, 'sale_date' => today()->toDateString(), 'type' => 'bon_livraison',
        'items' => [['product_type' => 'oeufs', 'product_name' => 'Œufs', 'quantity' => 2, 'unit' => 'Plateau', 'unit_price' => 30000]],
    ], $champs));

    expect($vente(['items' => [['product_type' => 'oeufs', 'product_name' => 'Œufs', 'quantity' => 2, 'unit' => 'Plateau', 'unit_price' => 0]]])['status'])->not->toBe('success')
        ->and($vente(['type' => 'facture', 'tax_rate' => 0])['status'])->not->toBe('success')
        ->and(Sale::count())->toBe(0)
        ->and($vente([])['status'])->toBe('success');
});

test('un magasinier réceptionne l’aliment au bureau comme au terrain', function () {
    \App\Support\ProfilsMetier::installer();
    $magasinier = User::factory()->create(['role_id' => Role::where('name', 'profil-magasinier')->value('id')]);
    expect(\Illuminate\Support\Facades\Gate::forUser($magasinier)->allows('provenderie.C'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($magasinier)->allows('aliment.receptionner'))->toBeTrue();

    $route = collect(\Illuminate\Support\Facades\Route::getRoutes())->first(fn ($r) => $r->getName() === 'feed-purchases.store');
    expect($route->middleware())->toContain('can:aliment.receptionner');
});

test('le pointage : une seconde saisie du jour n’écrase pas la première ; pas de fumier sans litière', function () {
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'arrival_date' => today()->subDays(10)->toDateString()]);
    $saisie = ['batch_id' => $lot->id, 'check_date' => today()->subDay()->toDateString(), 'mortality' => 1, 'feed_consumed' => 0];

    $this->post(route('daily-checks.store'), $saisie)->assertSessionHasNoErrors();
    $this->post(route('daily-checks.store'), ['mortality' => 9] + $saisie)->assertSessionHasErrors('check_date');
    expect(DailyCheck::where('batch_id', $lot->id)->value('mortality'))->toBe(1);

    expect(parLeTerrain($this, 'daily_check.create', ['batch_id' => $lot->id, 'check_date' => today()->toDateString(),
        'mortality' => 0, 'feed_consumed' => 0, 'litter_changed' => false, 'manure_collected_kg' => 300])['status'])->toBe('success');
    expect((float) DailyCheck::where('batch_id', $lot->id)->whereDate('check_date', today())->value('manure_collected_kg'))->toBe(0.0);

    expect(parLeTerrain($this, 'daily_check.create', ['batch_id' => $lot->id, 'check_date' => today()->subDays(2)->toDateString(),
        'mortality' => 0, 'feed_consumed' => 25])['status'])->not->toBe('success');
});

test('un CCP du terrain sans sa mesure n’est pas inscrit conforme', function () {
    $sansTemperature = parLeTerrain($this, 'ccp_record.create', ['ccp' => CcpRecord::CCP3, 'mesures' => ['remarque' => 'ok'],
        'conforme' => true, 'releve_at' => now()->toIso8601String()]);
    $sansDeclaration = parLeTerrain($this, 'ccp_record.create', ['ccp' => CcpRecord::CCP1, 'mesures' => ['appreciation' => 'x'],
        'releve_at' => now()->toIso8601String()]);

    expect($sansTemperature['status'])->not->toBe('success')
        ->and($sansDeclaration['status'])->not->toBe('success')
        ->and(CcpRecord::count())->toBe(0);
});

test('une source d’eau désactivée ne se ravitaille plus, au bureau comme au terrain', function () {
    $source = WaterSource::create(['name' => 'Vieille citerne', 'type' => 'citerne', 'capacity_liters' => 5000,
        'current_level_liters' => 1000, 'status' => 'operationnel', 'is_active' => false]);

    $this->post(route('utilities.water.sources.refill', $source), [
        'volume_added_liters' => 500, 'refill_date' => today()->toDateString(),
    ])->assertSessionHasErrors('water_source_id');
    expect(parLeTerrain($this, 'water_refill.create', ['water_source_id' => $source->id, 'volume_added_liters' => 500,
        'refill_date' => today()->toDateString()])['status'])->not->toBe('success')
        ->and(WaterReading::count())->toBe(0);
});
