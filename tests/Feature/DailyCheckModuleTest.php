<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Species;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $manager = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M']]
    );
    $viewer = Role::firstOrCreate(
        ['name' => 'viewer'],
        ['label' => 'Viewer', 'display_name' => 'Viewer', 'permissions' => ['L']]
    );

    $now = now();
    foreach ([[$manager, true], [$viewer, false]] as [$role, $write]) {
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['can_read' => true, 'can_create' => $write, 'can_modify' => $write, 'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
    $this->viewerUser  = User::factory()->create(['role_id' => $viewer->id]);
    $this->building    = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
});

test('un visiteur (L) ne peut PAS enregistrer un pointage', function () {
    $batch = Batch::factory()->create(['building_id' => $this->building->id, 'status' => 'Actif']);

    // L'app convertit AuthorizationException en redirection (cf. bootstrap/app.php) :
    // l'accès est refusé et aucun pointage n'est créé.
    $this->actingAs($this->viewerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'     => $batch->id,
            'check_date'   => now()->toDateString(),
            'mortality'    => 1,
            'feed_consumed' => 0,
            'feed_type'    => 'Chair Démarrage',
        ])
        ->assertRedirect();

    expect(DailyCheck::where('batch_id', $batch->id)->exists())->toBeFalse();
});

test('une rectification avec champs quarantaine vides est acceptée (défaut à 0)', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'           => $batch->id,
        'mortality'          => 0,
        'feed_consumed'      => 0,
        'feed_type'          => 'Chair Démarrage',
        'qty_quarantine_in'  => 0,
        'qty_quarantine_out' => 0,
        'qty_sorted_out'     => 0,
    ]);

    // Les champs quarantaine sont soumis VIDES (l'utilisateur a effacé la
    // valeur) : ils doivent retomber à 0, pas déclencher une erreur "requis".
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'          => 2,
            'feed_consumed'      => 0,
            'feed_type'          => 'Chair Démarrage',
            'qty_quarantine_in'  => '',
            'qty_quarantine_out' => '',
        ])
        ->assertSessionDoesntHaveErrors();

    $check->refresh();
    expect($check->mortality)->toBe(2);
    expect($check->qty_quarantine_in)->toBe(0);
});

test('une rectification aquacole avec un pH hors bornes est rejetée', function () {
    $tilapia = Species::firstOrCreate(
        ['slug' => 'tilapia'],
        ['name_fr' => 'Tilapia', 'family' => 'aquaculture', 'is_active' => true, 'tracks_water_quality' => true]
    );

    $bassin = Building::factory()->create(['type' => 'bassin', 'capacity' => 5000]);

    $batch = Batch::factory()->create([
        'species_id'         => $tilapia->id,
        'building_id'        => $bassin->id,
        'status'             => 'Actif',
        'current_quantity'   => 1000,
        'production_type_id' => ProductionType::resolveOrCreate('grossissement', $tilapia->id)->id,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'      => $batch->id,
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Grossissement',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'          => 0,
            'feed_consumed'      => 0,
            'feed_type'          => 'Grossissement',
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
            'ext_water_ph'       => 15, // > 14 : impossible
        ])
        ->assertSessionHasErrors('ext_water_ph');
});

test('une rectification dont la mortalité dépasse l\'effectif est rejetée', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 3,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'      => $batch->id,
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Chair Démarrage',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'          => 999,
            'feed_consumed'      => 0,
            'feed_type'          => 'Chair Démarrage',
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
        ])
        ->assertSessionHasErrors('mortality');
});

test('un ramassage de fumier au pointage crédite un stock « Fumier » vendable comme fertilisant', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'            => $batch->id,
            'check_date'          => now()->toDateString(),
            'mortality'           => 0,
            'feed_consumed'       => 0,
            'feed_type'           => 'Chair Démarrage',
            'litter_changed'      => 1,
            'manure_collected_kg' => 120,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Le pointage conserve la quantité ramassée pour la traçabilité.
    $check = DailyCheck::where('batch_id', $batch->id)->first();
    expect((float) $check->manure_collected_kg)->toBe(120.0);

    // Un article « Fumier » est créé en produits_finis et crédité de 120 kg.
    $fumier = Stock::where('item_name', 'Fumier')
        ->where('category', Stock::CAT_PRODUITS_FINIS)
        ->first();

    expect($fumier)->not->toBeNull()
        ->and((float) $fumier->current_quantity)->toBe(120.0)
        ->and(StockMovement::where('stock_id', $fumier->id)->where('type', 'in')->exists())->toBeTrue();

    // produits_finis est un type vendable : le fumier est mobilisable en vente.
    expect(Stock::categoryForProductType('produits_finis'))->toBe(Stock::CAT_PRODUITS_FINIS);
});

test('une quantité de fumier saisie sans litière changée est ignorée', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'            => $batch->id,
            'check_date'          => now()->toDateString(),
            'mortality'           => 0,
            'feed_consumed'       => 0,
            'feed_type'           => 'Chair Démarrage',
            // litter_changed non coché
            'manure_collected_kg' => 80,
        ])
        ->assertSessionHasNoErrors();

    $check = DailyCheck::where('batch_id', $batch->id)->first();
    expect((float) $check->manure_collected_kg)->toBe(0.0);

    expect(Stock::where('item_name', 'Fumier')->exists())->toBeFalse();
});

test('rectifier la quantité de fumier compense le stock sans double comptage', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'            => $batch->id,
        'mortality'           => 0,
        'feed_consumed'       => 0,
        'feed_type'           => 'Chair Démarrage',
        'litter_changed'      => true,
        'manure_collected_kg' => 100,
    ]);

    // On initialise le stock fumier à 100 kg (état après ramassage initial).
    $fumier = Stock::create([
        'item_name'        => 'Fumier',
        'category'         => Stock::CAT_PRODUITS_FINIS,
        'unit'             => 'KG',
        'current_quantity' => 100,
        'alert_threshold'  => 0,
    ]);

    // Rectification : le ramassage réel n'était que de 60 kg.
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'           => 0,
            'feed_consumed'       => 0,
            'feed_type'           => 'Chair Démarrage',
            'qty_quarantine_in'   => 0,
            'qty_quarantine_out'  => 0,
            'litter_changed'      => 1,
            'manure_collected_kg' => 60,
        ])
        ->assertSessionHasNoErrors();

    // 100 (initial) − 100 (restitution) + 60 (nouveau) = 60 kg, pas 160.
    expect((float) $fumier->fresh()->current_quantity)->toBe(60.0);
});

test('le lot expose le fumier ramassé cumulé et son revenu estimé au prix de l\'article « Fumier »', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'            => $batch->id,
            'check_date'          => now()->toDateString(),
            'mortality'           => 0,
            'feed_consumed'       => 0,
            'feed_type'           => 'Chair Démarrage',
            'litter_changed'      => 1,
            'manure_collected_kg' => 50,
        ])
        ->assertSessionHasNoErrors();

    $batch->refresh();
    expect($batch->manure_collected_kg)->toBe(50.0);

    // Sans prix unitaire renseigné sur l'article « Fumier », le revenu est nul.
    expect($batch->estimated_manure_revenue)->toBe(0.0);

    // Une fois le prix unitaire de l'article fixé (cf. Stocks > Edit), le
    // revenu estimé est exposé pour le rapport de marge du lot.
    Stock::where('item_name', 'Fumier')->where('category', Stock::CAT_PRODUITS_FINIS)
        ->update(['unit_price' => 50]);

    expect($batch->estimated_manure_revenue)->toBe(2500.0);
});

test('le lot expose le nombre de jours depuis le dernier renouvellement de litière', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    expect($batch->days_since_litter_change)->toBeNull();

    DailyCheck::factory()->create([
        'batch_id'       => $batch->id,
        'check_date'     => now()->subDays(5),
        'litter_changed' => true,
    ]);
    // Un pointage plus récent SANS litière changée ne doit pas écraser la date.
    DailyCheck::factory()->create([
        'batch_id'       => $batch->id,
        'check_date'     => now()->subDays(2),
        'litter_changed' => false,
    ]);

    expect($batch->fresh()->days_since_litter_change)->toBe(5);
});
