<?php

use App\Models\AnimalBreed;
use App\Models\Batch;
use App\Models\BatchCostAllocation;
use App\Models\Building;
use App\Models\Employee;
use App\Models\ProductionType;
use App\Models\RawFeeding;
use App\Models\Role;
use App\Models\Species;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ProfilsMetier;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TAXONOMIE ANIMALE, ALIMENTATION BRUTE ET MAIN-D'ŒUVRE IMPUTÉE AU LOT.
 *
 * Demande de l'exploitation :
 *   • l'administrateur ajoute espèces et souches (moutons Djallonké, lapin
 *     local…) ; le terrain les reçoit pour créer un lot, même hors ligne ;
 *   • l'ouvrier enregistre l'herbe coupée, les fanes… sans formule ni stock,
 *     avec le temps passé ;
 *   • ce temps, valorisé au coût horaire de l'employé, entre dans le coût de
 *     revient du lot, sur sa propre ligne — sans passer par la trésorerie.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();

    $this->mouton = Species::where('slug', 'mouton')->firstOrFail();
    $this->engraissement = ProductionType::where('species_id', $this->mouton->id)->where('slug', 'engraissement')->firstOrFail();
    $this->bergerie = Building::factory()->create(['name' => 'Bergerie', 'type' => 'mixte', 'capacity' => 200,
        'status' => Building::STATUS_DISPONIBLE]);
});

/** La ferme de setUpRbac (propriété protégée, hors de portée des fonctions Pest). */
function fermeDuTest(): int
{
    return (int) \App\Models\Farm::where('code', 'FT-001')->value('id');
}

function loteDeMoutons(Tests\TestCase $test): Batch
{
    return Batch::factory()->create([
        'farm_id' => fermeDuTest(), 'building_id' => $test->bergerie->id, 'status' => 'Actif',
        'species_id' => $test->mouton->id, 'production_type_id' => $test->engraissement->id,
        'initial_quantity' => 20, 'current_quantity' => 20,
    ]);
}

function agentAvecFiche(Tests\TestCase $test, string $contrat, float $salaire, ?float $taux = null): User
{
    $agent = User::factory()->create(['role_id' => Role::where('name', 'profil-agent-terrain')->value('id')]);
    SitesDuCompte::rattacher($agent, [fermeDuTest()]);
    Employee::factory()->create(['farm_id' => fermeDuTest(), 'user_id' => $agent->id, 'status' => 'Actif',
        'first_name' => 'Mamadou', 'last_name' => 'Diallo', 'contract_type' => $contrat,
        'salary' => $salaire, 'hourly_cost_gnf' => $taux]);

    return $agent;
}

function nourrirBrut(Tests\TestCase $test, User $agent, array $payload): array
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($agent);

    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'raw_feeding.create', 'payload' => $payload,
    ]]])->assertOk()->json('results.0');
}

// ── TAXONOMIE ────────────────────────────────────────────────────────────

test('des races locales sont proposées dès l’installation', function () {
    expect(AnimalBreed::where('species_id', $this->mouton->id)->pluck('name')->all())
        ->toContain('Djallonké', 'Peul-peul');
});

test('l’administrateur crée une espèce, ses souches, les renomme ; un autre profil ne le peut pas', function () {
    $this->actingAs($this->adminUser)->post(route('admin.species.store'), [
        'name_fr' => 'Escargot', 'family' => 'autre', 'production_type' => 'Engraissement', 'cycle_days' => 120,
    ])->assertSessionHasNoErrors()->assertSessionMissing('error');

    $escargot = Species::where('slug', 'escargot')->firstOrFail();
    expect($escargot->productionTypes()->count())->toBe(1);

    $this->actingAs($this->adminUser)->post(route('admin.species.breeds.store', $escargot), ['name' => 'Achatine'])
        ->assertSessionHasNoErrors();
    $souche = AnimalBreed::where('species_id', $escargot->id)->where('name', 'Achatine')->firstOrFail();

    // Un lot sur cette souche : le renommage le suit, la suppression est refusée.
    $lot = loteDeMoutons($this);
    $lot->update(['species_id' => $escargot->id, 'breed_id' => $souche->id, 'model_name' => 'Achatine']);
    $this->actingAs($this->adminUser)->put(route('admin.breeds.update', $souche), ['name' => 'Achatine géante']);
    expect($lot->fresh()->model_name)->toBe('Achatine géante');
    $this->actingAs($this->adminUser)->delete(route('admin.breeds.destroy', $souche))->assertSessionHas('error');

    $gerant = User::factory()->create(['role_id' => Role::where('name', 'profil-gerant')->value('id')]);
    SitesDuCompte::rattacher($gerant, [fermeDuTest()]);
    $this->actingAs($gerant)->post(route('admin.species.breeds.store', $escargot), ['name' => 'Intrus']);
    expect(AnimalBreed::where('name', 'Intrus')->exists())->toBeFalse();
});

test('un lot créé au bureau porte sa souche ; une souche d’une autre espèce est refusée', function () {
    $djallonke = AnimalBreed::where('species_id', $this->mouton->id)->where('name', 'Djallonké')->firstOrFail();
    $lot = app(\App\Actions\Batch\CreateBatch::class)->execute([
        'code' => 'OV-01', 'type' => 'engraissement', 'species_id' => $this->mouton->id,
        'production_type_id' => $this->engraissement->id, 'breed_id' => $djallonke->id,
        'building_id' => $this->bergerie->id, 'qty_alive' => 12, 'qty_dead' => 0,
        'buy_price_per_unit' => 600000, 'arrival_date' => today()->toDateString(),
    ]);
    expect($lot->breed_id)->toBe($djallonke->id)->and($lot->model_name)->toBe('Djallonké');

    $lapin = AnimalBreed::whereHas('species', fn ($q) => $q->where('slug', 'lapin'))->firstOrFail();
    expect(fn () => app(\App\Actions\Batch\CreateBatch::class)->execute([
        'code' => 'OV-02', 'type' => 'engraissement', 'species_id' => $this->mouton->id,
        'production_type_id' => $this->engraissement->id, 'breed_id' => $lapin->id,
        'building_id' => $this->bergerie->id, 'qty_alive' => 5, 'qty_dead' => 0,
        'buy_price_per_unit' => 0, 'arrival_date' => today()->toDateString(),
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('le terrain reçoit les souches et crée un lot avec sa souche, hors ligne', function () {
    $technicien = User::factory()->create(['role_id' => Role::where('name', 'profil-technicien-elevage')->value('id')]);
    SitesDuCompte::rattacher($technicien, [fermeDuTest()]);
    Sanctum::actingAs($technicien);

    $souches = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.animal_breeds.upserts');
    $djallonke = collect($souches)->firstWhere('name', 'Djallonké');
    expect($djallonke)->not->toBeNull()->and($djallonke)->toHaveKeys(['species_id', 'is_active']);

    $uuid = (string) Str::uuid();
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'batch.upsert', 'payload' => [
            'uuid' => $uuid, 'code' => 'OV-TERRAIN', 'type' => 'engraissement',
            'production_type_id' => $this->engraissement->id, 'breed_id' => $djallonke['id'],
            'building_id' => $this->bergerie->id, 'initial_quantity' => 8, 'current_quantity' => 8,
            'status' => 'Actif', 'arrival_date' => today()->toDateString(), 'updated_at' => now()->toIso8601String(),
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success')
        ->and(Batch::where('uuid', $uuid)->value('breed_id'))->toBe($djallonke['id']);
});

// ── COÛT HORAIRE ─────────────────────────────────────────────────────────

test('le coût horaire : salaire ÷ 173,33 h au mois, journée ÷ 8 h pour un journalier, ou le taux forcé', function () {
    expect((new Employee(['contract_type' => 'CDI', 'salary' => 1_733_300]))->coutHoraire())->toBe(10000.0)
        ->and((new Employee(['contract_type' => 'Journalier', 'salary' => 1_040_000]))->coutHoraire())->toBe(5000.0)
        ->and((new Employee(['contract_type' => 'CDI', 'salary' => 1_733_300, 'hourly_cost_gnf' => 12000]))->coutHoraire())->toBe(12000.0)
        ->and((new Employee(['contract_type' => 'CDI', 'salary' => 0]))->coutHoraire())->toBe(0.0);
});

// ── ALIMENTATION BRUTE ───────────────────────────────────────────────────

test('nourrir en brut : preuve de travail, temps imputé au lot, sans toucher au stock ni à la trésorerie', function () {
    $lot = loteDeMoutons($this);
    $agent = agentAvecFiche($this, 'CDI', 1_733_300);   // 10 000 GNF / h
    $avant = (float) $lot->fresh()->operating_cost;
    $uuid = (string) Str::uuid();
    $payload = ['uuid' => $uuid, 'batch_id' => $lot->id, 'fed_at' => now()->toIso8601String(),
        'resource' => 'Herbe locale', 'quantity' => 3, 'quantity_unit' => 'brouette', 'minutes_spent' => 45];

    expect(nourrirBrut($this, $agent, $payload)['status'])->toBe('success');

    $distribution = RawFeeding::where('uuid', $uuid)->firstOrFail();
    expect((float) $distribution->labor_cost_gnf)->toBe(7500.0)
        ->and((float) $distribution->hourly_cost_gnf)->toBe(10000.0)
        ->and($distribution->user_id)->toBe($agent->id);

    $imputation = BatchCostAllocation::where('batch_id', $lot->id)->firstOrFail();
    expect($imputation->label)->toBe("Main-d'œuvre : Coupe/Distribution Herbe locale - Mamadou Diallo")
        ->and($imputation->category)->toBe(BatchCostAllocation::MAIN_OEUVRE_ALIMENTATION)
        ->and((float) $lot->fresh()->operating_cost)->toBe($avant + 7500.0)
        ->and(StockMovement::count())->toBe(0)
        ->and(\App\Models\Expense::count())->toBe(0);

    // Rejeu de la synchro : ni seconde distribution, ni second coût.
    nourrirBrut($this, $agent, $payload);
    expect(RawFeeding::count())->toBe(1)->and(BatchCostAllocation::count())->toBe(1);
});

test('le temps est obligatoire ; sans coût horaire connu l’action reste enregistrée, sans imputation', function () {
    $lot = loteDeMoutons($this);
    $agent = agentAvecFiche($this, 'CDI', 0);

    $refus = nourrirBrut($this, $agent, ['uuid' => (string) Str::uuid(), 'batch_id' => $lot->id,
        'fed_at' => now()->toIso8601String(), 'resource' => 'Fanes de maïs']);
    expect($refus['status'])->not->toBe('success');

    nourrirBrut($this, $agent, ['uuid' => (string) Str::uuid(), 'batch_id' => $lot->id,
        'fed_at' => now()->toIso8601String(), 'resource' => 'Fanes de maïs', 'minutes_spent' => 30]);
    expect(RawFeeding::count())->toBe(1)->and(BatchCostAllocation::count())->toBe(0);
});

test('l’herbe coupée sur une parcelle trace aussi son entretien', function () {
    $lot = loteDeMoutons($this);
    $agent = agentAvecFiche($this, 'Journalier', 1_040_000);
    $parcelle = \App\Models\Plot::create(['farm_id' => fermeDuTest(), 'code' => 'P-MANG', 'name' => 'Sous les manguiers', 'status' => 'Disponible']);

    nourrirBrut($this, $agent, ['uuid' => (string) Str::uuid(), 'batch_id' => $lot->id, 'fed_at' => now()->toIso8601String(),
        'resource' => 'Herbe locale', 'minutes_spent' => 60, 'plot_id' => $parcelle->id]);

    expect(RawFeeding::first()->plot_id)->toBe($parcelle->id)
        ->and((float) RawFeeding::first()->labor_cost_gnf)->toBe(5000.0);
    $this->actingAs($this->adminUser)->get(route('plots.show', $parcelle))->assertOk()->assertSee("Coupes pour l'élevage");
});

test('la fiche du lot sépare la main-d’œuvre de l’aliment acheté — pour qui voit l’argent', function () {
    $lot = loteDeMoutons($this);
    nourrirBrut($this, agentAvecFiche($this, 'CDI', 1_733_300), ['uuid' => (string) Str::uuid(), 'batch_id' => $lot->id,
        'fed_at' => now()->toIso8601String(), 'resource' => 'Herbe locale', 'minutes_spent' => 60]);

    $this->actingAs($this->adminUser)->get(route('batches.show', $lot))->assertOk()
        ->assertSee('Répartition des coûts')->assertSee("Coût main-d'œuvre (alimentation locale)")
        ->assertSee('Alimentation brute');

    $technicien = User::factory()->create(['role_id' => Role::where('name', 'profil-technicien-elevage')->value('id')]);
    SitesDuCompte::rattacher($technicien, [fermeDuTest()]);
    $this->actingAs($technicien)->get(route('batches.show', $lot))->assertOk()
        ->assertSee('Alimentation brute')->assertDontSee('Répartition des coûts');
});

test('sans le droit de saisie élevage, nourrir en brut est refusé', function () {
    $lot = loteDeMoutons($this);
    $vendeur = User::factory()->create(['role_id' => Role::where('name', 'profil-vendeur')->value('id')]);
    SitesDuCompte::rattacher($vendeur, [fermeDuTest()]);

    $res = nourrirBrut($this, $vendeur, ['uuid' => (string) Str::uuid(), 'batch_id' => $lot->id,
        'fed_at' => now()->toIso8601String(), 'resource' => 'Herbe locale', 'minutes_spent' => 15]);
    expect($res['status'])->toBe('permission_denied')->and(RawFeeding::count())->toBe(0);
});
