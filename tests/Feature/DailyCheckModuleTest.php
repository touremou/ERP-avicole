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

test('un pointage daté avant l\'arrivée du lot est rejeté (âge négatif incohérent)', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
        'arrival_date'     => now()->subDays(10)->toDateString(),
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'      => $batch->id,
            'check_date'    => now()->subDays(15)->toDateString(), // avant l'arrivée
            'mortality'     => 0,
            'feed_consumed' => 0,
            'feed_type'     => 'Chair Démarrage',
        ])
        ->assertSessionHasErrors('check_date');

    expect(DailyCheck::where('batch_id', $batch->id)->exists())->toBeFalse();

    // Le même pointage daté le jour de l'arrivée passe.
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'      => $batch->id,
            'check_date'    => now()->subDays(10)->toDateString(),
            'mortality'     => 0,
            'feed_consumed' => 0,
            'feed_type'     => 'Chair Démarrage',
        ])
        ->assertSessionHasNoErrors();

    expect(DailyCheck::where('batch_id', $batch->id)->exists())->toBeTrue();
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

test('la consommation d\'aliment est valorisée au coût de revient et imputée à la marge du lot', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    // Article aliment valorisé à 250 GNF/kg (CMP, comme s'il sortait de la
    // provenderie ou d'un achat), avec assez de stock pour la consommation.
    Stock::create([
        'item_name'        => 'Chair Démarrage',
        'feed_type'        => 'Chair Démarrage',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 1000,
        'last_unit_price'  => 250,
        'unit_price'       => 250,
        'alert_threshold'  => 0,
    ]);

    // Marge avant consommation (référence : aucun aliment encore imputé).
    $marginBefore = $batch->net_margin;
    expect($batch->feed_cogs)->toBe(0.0);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'      => $batch->id,
            'check_date'    => now()->toDateString(),
            'mortality'     => 0,
            'feed_consumed' => 40,
            'feed_type'     => 'Chair Démarrage',
        ])
        ->assertSessionHasNoErrors();

    // Le coût de revient est figé sur le pointage (40 kg × 250 = snapshot 250).
    $check = DailyCheck::where('batch_id', $batch->id)->first();
    expect((float) $check->feed_unit_cost)->toBe(250.0);

    // Le COGS aliment du lot vaut 40 × 250 et la marge baisse d'autant.
    $batch->refresh();
    expect($batch->feed_cogs)->toBe(10000.0);
    expect($batch->net_margin)->toBe($marginBefore - 10000.0);
});

test('le feed_unit_cost est résolu même si feed_type est null sur le stock (lookup par item_name)', function () {
    // Reproduit le bug : un stock créé manuellement ou via firstOrCreate sans
    // feed_type a feed_type = NULL. resolveFeedUnitCost retournait 0 car il
    // cherchait par feed_type. Le fix ajoute un OR sur item_name.
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 200,
    ]);

    Stock::create([
        'item_name'        => 'Ponte Entretien',
        'feed_type'        => null, // Volontairement absent — c'est le bug
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 500,
        'last_unit_price'  => 300,
        'unit_price'       => 300,
        'alert_threshold'  => 0,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'      => $batch->id,
            'check_date'    => now()->toDateString(),
            'mortality'     => 0,
            'feed_consumed' => 20,
            'feed_type'     => 'Ponte Entretien',
        ])
        ->assertSessionHasNoErrors();

    $check = DailyCheck::where('batch_id', $batch->id)->first();
    expect((float) $check->feed_unit_cost)->toBe(300.0);

    $batch->refresh();
    expect($batch->feed_cogs)->toBe(6000.0); // 20 kg × 300
});

test('feed_cogs cumule TOUS les pointages, avec repli CMP pour les coûts non figés', function () {
    // Reproduit « seul le dernier pointage est valorisé » : des pointages
    // anciens ont feed_unit_cost = 0 (saisis avant que l'aliment soit valorisé).
    // Le repli sur le CMP courant doit les revaloriser pour ne plus sous-estimer.
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
        'arrival_date'     => now()->subDays(5),
    ]);

    Stock::create([
        'item_name'        => 'Chair Croissance',
        'feed_type'        => 'Chair Croissance',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 1000,
        'last_unit_price'  => 400,
        'unit_price'       => 400,
        'alert_threshold'  => 0,
    ]);

    // 2 pointages sans coût figé (legacy) + 1 avec snapshot.
    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'check_date' => now()->subDays(3),
        'feed_consumed' => 10, 'feed_type' => 'Chair Croissance', 'feed_unit_cost' => 0, 'mortality' => 0,
    ]);
    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'check_date' => now()->subDays(2),
        'feed_consumed' => 10, 'feed_type' => 'Chair Croissance', 'feed_unit_cost' => 0, 'mortality' => 0,
    ]);
    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'check_date' => now()->subDay(),
        'feed_consumed' => 10, 'feed_type' => 'Chair Croissance', 'feed_unit_cost' => 400, 'mortality' => 0,
    ]);

    // 3 × 10 kg × 400 = 12000 (les 2 legacy revalorisés au CMP courant 400).
    expect($batch->feed_cogs)->toBe(12000.0);
});

test('le registre de consommation s\'aligne sur les pointages : une ligne par date, somme = feed_cogs', function () {
    // Cohérence Journal des Flux ↔ Historique Daily : une correction (re-saisie
    // du même jour) ne doit PAS produire deux lignes de consommation, et le
    // total du registre doit égaler feed_cogs.
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
        'arrival_date'     => now()->subDays(5),
    ]);

    Stock::create([
        'item_name'        => 'Chair Démarrage',
        'feed_type'        => 'Chair Démarrage',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 1000,
        'last_unit_price'  => 250,
        'unit_price'       => 250,
        'alert_threshold'  => 0,
    ]);

    $date = now()->subDay()->toDateString();

    // Saisie initiale puis CORRECTION le même jour (10 → 30 kg).
    foreach ([10, 30] as $qty) {
        $this->actingAs($this->managerUser)
            ->post(route('daily-checks.store'), [
                'batch_id'      => $batch->id,
                'check_date'    => $date,
                'mortality'     => 0,
                'feed_consumed' => $qty,
                'feed_type'     => 'Chair Démarrage',
            ])
            ->assertSessionHasNoErrors();
    }

    $batch->refresh();
    $ledger = $batch->feedConsumptionLedger();

    // Une seule ligne (la correction écrase, ne s'ajoute pas) — alignée sur
    // l'unique pointage de la date.
    expect($ledger)->toHaveCount(1);
    expect((float) $ledger->first()->qty)->toBe(30.0);
    expect((float) $ledger->first()->amount)->toBe(7500.0); // 30 × 250

    // Le total du registre = feed_cogs (invariant de cohérence).
    expect((float) $ledger->sum('amount'))->toBe($batch->feed_cogs);
    expect($batch->feed_cogs)->toBe(7500.0);
});

test('le pointage enregistre les indicateurs de bien-être (boiterie, picage)', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'             => $batch->id,
            'check_date'           => now()->toDateString(),
            'mortality'            => 0,
            'feed_consumed'        => 0,
            'feed_type'            => 'Chair Démarrage',
            'lame_count'           => 12,
            'pecking_injury_count' => 7,
        ])
        ->assertSessionHasNoErrors();

    $check = DailyCheck::where('batch_id', $batch->id)->first();
    expect($check->lame_count)->toBe(12)
        ->and($check->pecking_injury_count)->toBe(7);
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

test('le formulaire de pointage pré-remplit la météo régionale et propose la reco. eau du jour', function () {
    $farm = App\Models\Farm::where('code', 'FT-001')->first();

    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 1000,
        'model_name'       => 'Cobb500',
        'arrival_date'     => now()->subDays(14),
        'farm_id'          => $farm->id,
    ]);

    // Barème de souche : eau ET aliment cibles → recommendation()['total'] > 0.
    foreach ([1, 2, 3, 4] as $wk) {
        App\Models\ProductionNorm::create([
            'model_name'         => 'Cobb500',
            'batch_type'         => 'chair',
            'week_number'        => $wk,
            'phase_name'         => 'Démarrage',
            'target_weight'      => 100 * $wk,
            'target_feed_daily'  => 50 * $wk,   // g/sujet/j
            'target_water_daily' => 100 * $wk,  // ml/sujet/j
            'target_laying_rate' => 0,
        ]);
    }

    // Relevé météo du jour de la ferme → pré-remplissage température/humidité.
    App\Models\WeatherReading::create([
        'farm_id'         => $farm->id,
        'reading_date'    => now()->toDateString(),
        'temperature_min' => 24.5,
        'temperature_max' => 33.0,
        'humidity_pct'    => 78,
    ]);

    $resp = $this->actingAs($this->managerUser)
        ->get(route('daily-checks.create', ['batch_id' => $batch->id]))
        ->assertOk();

    // 1. Météo régionale pré-remplie (champ temp_min + indicateur visuel).
    $resp->assertSee('value="24.5"', false)
         ->assertSee('Pré-rempli météo', false);

    // 2. Bouton « Reco. » d'eau présent : il pré-remplit le champ water_consumed
    //    (comme le bouton aliment). Le onclick ciblant water_consumed est unique.
    $resp->assertSee("getElementById('water_consumed').value=", false);
});
