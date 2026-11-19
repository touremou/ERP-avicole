<?php

use App\Actions\Dispatch\CreateDispatch;
use App\Models\Batch;
use App\Models\BatchCostAllocation;
use App\Models\Building;
use App\Models\CropRecipe;
use App\Models\CropRecipeItem;
use App\Models\Employee;
use App\Models\GrazingSession;
use App\Models\HealthCheck;
use App\Models\ProductionType;
use App\Models\RawFeeding;
use App\Models\Role;
use App\Models\SlaughterByproduct;
use App\Models\SlaughterOrder;
use App\Models\Species;
use App\Models\User;
use App\Services\SlaughterService;
use App\Support\ProfilsMetier;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ALIMENTATION NATURELLE ET PÂTURAGE — ET LES LISTES QUI SUIVENT L'ESPÈCE.
 *
 * Demande de l'exploitation :
 *   • « Nourrir (brut) » réservé aux herbivores, et saisissable au bureau ;
 *   • tracer les ruminants laissés dehors « pour qu'ils mangent en autonomie » ;
 *   • en tenir compte dans le suivi quotidien et les indicateurs ;
 *   • sous-produits, gammes de carcasse, expédition sur pied et recettes de
 *     transformation cohérents avec l'espèce / la culture, au bureau comme au terrain.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();

    $ferme = (int) \App\Models\Farm::where('code', 'FT-001')->value('id');
    $this->mouton = Species::where('slug', 'mouton')->firstOrFail();
    $engraissement = ProductionType::where('species_id', $this->mouton->id)->where('slug', 'engraissement')->firstOrFail();
    $bergerie = Building::factory()->create(['farm_id' => $ferme, 'name' => 'Bergerie', 'type' => 'mixte', 'capacity' => 200]);
    $this->moutons = Batch::factory()->create(['farm_id' => $ferme, 'building_id' => $bergerie->id, 'status' => 'Actif',
        'species_id' => $this->mouton->id, 'production_type_id' => $engraissement->id,
        'initial_quantity' => 20, 'current_quantity' => 20, 'arrival_date' => now()->subDays(9)->toDateString()]);
    $this->poulets = Batch::factory()->create(['farm_id' => $ferme, 'building_id' => $this->building->id, 'status' => 'Actif',
        'species_id' => Species::where('slug', 'poulet')->value('id'), 'type' => 'chair']);

    // Un berger avec une fiche : 2 000 GNF de l'heure.
    $this->berger = User::factory()->create(['role_id' => Role::where('name', 'profil-agent-terrain')->value('id')]);
    SitesDuCompte::rattacher($this->berger, [$ferme]);
    Employee::factory()->create(['farm_id' => $ferme, 'user_id' => $this->berger->id, 'status' => 'Actif',
        'first_name' => 'Alpha', 'last_name' => 'Barry', 'hourly_cost_gnf' => 2000]);
    SitesDuCompte::rattacher($this->adminUser, [$ferme]);
});

function operationDuBerger(Tests\TestCase $test, string $type, array $payload): array
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($test->berger);

    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => $type, 'payload' => ['uuid' => (string) Str::uuid()] + $payload,
    ]]])->assertOk()->json('results.0');
}

function sortieDuJour(array $champs = []): array
{
    return array_merge([
        'left_at' => today()->setTime(8, 0)->toIso8601String(), 'returned_at' => today()->setTime(14, 0)->toIso8601String(),
        'mode' => 'autonomie', 'heads_out' => 20, 'heads_back' => 20, 'zone' => 'Jachère nord',
    ], $champs);
}

// ── 1. ALIMENTATION BRUTE : HERBIVORES SEULEMENT ────────────────────────

test('on ne nourrit pas des poulets au brut — ni au terrain ni au bureau', function () {
    $herbe = ['fed_at' => now()->subHour()->toIso8601String(), 'resource' => 'Herbe locale', 'minutes_spent' => 30];

    expect(operationDuBerger($this, 'raw_feeding.create', $herbe + ['batch_id' => $this->poulets->id])['status'])->not->toBe('success')
        ->and(operationDuBerger($this, 'raw_feeding.create', $herbe + ['batch_id' => $this->moutons->id])['status'])->toBe('success');

    $this->actingAs($this->adminUser)->post(route('batches.raw_feedings.store', $this->poulets), [
        'fed_at' => now()->subHour()->format('Y-m-d H:i'), 'resource' => 'Herbe locale', 'minutes_spent' => 30,
    ])->assertSessionHasErrors('batch_id');
    $this->actingAs($this->adminUser)->post(route('batches.raw_feedings.store', $this->moutons), [
        'fed_at' => now()->subHour()->format('Y-m-d H:i'), 'resource' => 'Fanes de maïs', 'minutes_spent' => 20,
    ])->assertSessionHasNoErrors();

    expect(RawFeeding::where('batch_id', $this->moutons->id)->count())->toBe(2)
        ->and(RawFeeding::where('batch_id', $this->poulets->id)->count())->toBe(0);
});

// ── 2. PÂTURAGE ─────────────────────────────────────────────────────────

test('une sortie en autonomie impute le temps de sortir et ramener ; gardée, toute la sortie', function () {
    expect(operationDuBerger($this, 'grazing.create', sortieDuJour() + ['batch_id' => $this->moutons->id])['status'])->toBe('success');
    $autonomie = GrazingSession::latest('id')->first();
    expect($autonomie->duration_minutes)->toBe(360)
        ->and($autonomie->herder_minutes)->toBe(30)
        ->and((float) $autonomie->labor_cost_gnf)->toEqualWithDelta(1000, 0.01);

    expect(operationDuBerger($this, 'grazing.create', sortieDuJour(['mode' => 'garde']) + ['batch_id' => $this->moutons->id])['status'])->toBe('success');
    $gardee = GrazingSession::latest('id')->first();
    expect($gardee->herder_minutes)->toBe(360)
        ->and((float) $gardee->labor_cost_gnf)->toEqualWithDelta(12000, 0.01)
        ->and((float) BatchCostAllocation::where('batch_id', $this->moutons->id)->sum('amount'))->toEqualWithDelta(13000, 0.01);
});

test('une tête manquante au retour est signalée, sans mortalité présumée ; les incohérences sont refusées', function () {
    operationDuBerger($this, 'grazing.create', sortieDuJour(['heads_back' => 18]) + ['batch_id' => $this->moutons->id]);
    expect(GrazingSession::first()->manquants)->toBe(2)
        ->and((int) $this->moutons->fresh()->current_quantity)->toBe(20)
        ->and($this->moutons->fresh()->alimentationNaturelle()['manquants'])->toBe(2);

    foreach ([
        sortieDuJour(['heads_back' => 21]),                                     // plus de rentrées que de sorties
        sortieDuJour(['returned_at' => today()->setTime(7, 0)->toIso8601String()]), // retour avant le départ
        sortieDuJour(['heads_out' => 25, 'heads_back' => 25]),                  // plus que l'effectif
    ] as $incoherente) {
        expect(operationDuBerger($this, 'grazing.create', $incoherente + ['batch_id' => $this->moutons->id])['status'])->not->toBe('success');
    }
    expect(operationDuBerger($this, 'grazing.create', sortieDuJour() + ['batch_id' => $this->poulets->id])['status'])->not->toBe('success')
        ->and(GrazingSession::count())->toBe(1);
});

test('le bureau note une sortie au pâturage avec la même règle', function () {
    $this->actingAs($this->adminUser)->post(route('batches.grazings.store', $this->moutons), [
        'grazed_on' => today()->toDateString(), 'left_time' => '09:00', 'returned_time' => '16:30',
        'mode' => 'garde', 'heads_out' => 20, 'heads_back' => 20, 'zone' => 'Bord du marigot',
    ])->assertSessionHasNoErrors();

    expect(GrazingSession::first())->duration_minutes->toBe(450)->zone->toBe('Bord du marigot');
});

// ── 3. SUIVI QUOTIDIEN ET INDICATEURS ───────────────────────────────────

test('le bilan d’alimentation naturelle compte les jours, les heures et le coût — sans montant au terrain', function () {
    operationDuBerger($this, 'grazing.create', sortieDuJour() + ['batch_id' => $this->moutons->id]);
    operationDuBerger($this, 'raw_feeding.create', ['batch_id' => $this->moutons->id, 'resource' => 'Herbe locale',
        'fed_at' => now()->subDay()->toIso8601String(), 'minutes_spent' => 60]);

    $bilan = $this->moutons->fresh()->alimentationNaturelle();
    expect($bilan)->toMatchArray(['sorties' => 1, 'distributions' => 1, 'jours_naturels' => 2, 'jours_suivis' => 10,
        'heures_paturage' => 6.0, 'cout_main_oeuvre' => 3000.0]);

    app('auth')->forgetGuards();
    Sanctum::actingAs($this->adminUser);
    $lot = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.batches.upserts'))
        ->firstWhere('id', $this->moutons->id);
    expect($lot['accepte_paturage'])->toBeTrue()
        ->and($lot['alimentation_naturelle_terrain'])->toHaveKey('heures_paturage')
        ->and($lot['alimentation_naturelle_terrain'])->not->toHaveKey('cout_main_oeuvre');

    $volaille = collect($this->getJson('/api/v1/sync/pull')->json('entities.batches.upserts'))->firstWhere('id', $this->poulets->id);
    expect($volaille['accepte_alimentation_brute'])->toBeFalse()->and($volaille['alimentation_naturelle_terrain'])->toBeNull();
});

test('le pointage du bureau montre ce que le lot a mangé hors stock aujourd’hui', function () {
    operationDuBerger($this, 'grazing.create', sortieDuJour() + ['batch_id' => $this->moutons->id]);

    $this->actingAs($this->adminUser)->get(route('daily-checks.create', ['batch_id' => $this->moutons->id]))
        ->assertOk()->assertSee('Jachère nord')->assertSee('20/20');
    $this->actingAs($this->adminUser)->get(route('batches.show', $this->moutons))
        ->assertOk()->assertSee('Sorties au pâturage');
});

// ── 4. ABATTOIR, EXPÉDITION, TRANSFORMATION ─────────────────────────────

test('pas de plumes ni de PAC pour un ovin ; ses peaux se collectent', function () {
    $ordre = SlaughterOrder::create(['order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->moutons->id,
        'planned_date' => today()->toDateString(), 'planned_quantity' => 5, 'status' => 'planifie',
        'requested_by' => $this->adminUser->id]);

    expect($ordre->byproductTypes())->toContain('peaux')->not->toContain('plumes')
        ->and(collect($ordre->presentations())->pluck('code')->all())->toBe(['brut'])
        ->and(SlaughterByproduct::refusPourLOrdre('plumes', $ordre->id))->not->toBeNull()
        ->and(SlaughterByproduct::refusPourLOrdre('peaux', $ordre->id))->toBeNull();

    expect(fn () => app(SlaughterService::class)->executeSlaughter($ordre, [
        'actual_quantity' => 5, 'total_live_weight_kg' => 150, 'total_carcass_weight_kg' => 70,
        'presentation' => 'pac', 'execution_date' => today()->toDateString(),
    ]))->toThrow(Exception::class, 'ne s’applique pas');
});

test('une expédition sur pied est refusée pendant le délai d’attente, comme la vente', function () {
    HealthCheck::create(['batch_id' => $this->moutons->id, 'type' => 'Traitement', 'product_name' => 'Ivermectine',
        'intervention_date' => today()->toDateString(), 'withdrawal_days' => 21, 'mode_administration' => 'Injection']);

    $this->actingAs($this->adminUser);
    expect(fn () => (new CreateDispatch())->execute([
        'driver_name' => 'Camara', 'dispatch_date' => today()->toDateString(), 'destination' => 'Marché',
        'items' => [['product_type' => 'animal_vif', 'product_name' => 'Mouton vif', 'batch_id' => $this->moutons->id,
            'quantity' => 2, 'unit' => 'tete']],
    ]))->toThrow(Exception::class, 'délai d’attente');
    expect((int) $this->moutons->fresh()->current_quantity)->toBe(20);
});

test('une recette ne se propose que pour la culture qu’elle transforme', function () {
    $gari = CropRecipe::create(['name' => 'Gari', 'output_product' => 'Gari', 'transformation_type' => 'sechage', 'is_active' => true]);
    CropRecipeItem::create(['crop_recipe_id' => $gari->id, 'input_product' => 'Manioc frais', 'quantity' => 100, 'unit' => 'kg']);
    $huile = CropRecipe::create(['name' => 'Huile rouge', 'output_product' => 'Huile rouge', 'transformation_type' => 'pressage', 'is_active' => true]);
    CropRecipeItem::create(['crop_recipe_id' => $huile->id, 'input_product' => 'Régimes de palme', 'quantity' => 100, 'unit' => 'kg']);
    $libre = CropRecipe::create(['name' => 'Séchage générique', 'output_product' => 'Produit séché', 'transformation_type' => 'sechage', 'is_active' => true]);

    expect($gari->convientA('Manioc'))->toBeTrue()
        ->and($gari->convientA('Maïs'))->toBeFalse()
        ->and($huile->convientA('Palmier à huile'))->toBeTrue()
        ->and($libre->convientA('Maïs'))->toBeTrue();
});
