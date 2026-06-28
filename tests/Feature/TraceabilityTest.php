<?php

use App\Models\Batch;
use App\Models\CropTransformation;
use App\Models\Dispatch;
use App\Models\EggProduction;
use App\Models\CropCycle;
use App\Models\Formula;
use App\Models\Harvest;
use App\Models\MillProduction;
use App\Models\Stock;
use App\Services\QrCodeService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── Service QR ─────────────────────────────────────────────────────────────

test('le service QR produit un data-URI PNG', function () {
    $uri = QrCodeService::dataUri('https://exemple.test/trace/lot/LOT-0001');

    expect($uri)->toStartWith('data:image/png;base64,')
        ->and(strlen($uri))->toBeGreaterThan(100);
});

// ─── Page publique de traçabilité ────────────────────────────────────────────

test('la page de traçabilité est publique et affiche l\'origine du lot', function () {
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-TRACE-1']);

    // Aucun acteur connecté : la page doit rester accessible (scan public).
    $this->get(route('trace.batch', 'LOT-TRACE-1'))
        ->assertOk()
        ->assertSee('LOT-TRACE-1')
        ->assertSee('Origine certifiée');
});

test('un code de lot inconnu renvoie 404', function () {
    $this->get(route('trace.batch', 'LOT-INEXISTANT'))->assertNotFound();
});

test('la page publique n\'expose aucune donnée financière', function () {
    $batch = Batch::factory()->create([
        'farm_id'                 => $this->farm->id,
        'code'                    => 'LOT-TRACE-2',
        'total_acquisition_cost'  => 7654321,
    ]);

    $this->get(route('trace.batch', 'LOT-TRACE-2'))
        ->assertOk()
        ->assertDontSee('7654321');
});

// ─── Étiquette imprimable ─────────────────────────────────────────────────────

test('l\'étiquette d\'un lot contient un QR et exige une lecture authentifiée', function () {
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-LABEL-1']);

    // Anonyme → redirigé vers login (route sous auth).
    $this->get(route('batches.label', $batch->id))->assertRedirect(route('login'));

    // Utilisateur autorisé → étiquette avec QR intégré.
    $this->actingAs($this->adminUser)
        ->get(route('batches.label', $batch->id))
        ->assertOk()
        ->assertSee('LOT-LABEL-1')
        ->assertSee('data:image/png;base64', false);
});

test('l\'étiquette d\'une collecte d\'œufs pointe vers la traçabilité du lot', function () {
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-EGG-1']);

    $collecte = EggProduction::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $batch->id,
        'production_date'      => now()->toDateString(),
        'total_eggs_collected' => 480,
        'is_graded'            => true,
        'grade_l'              => 8,
        'grade_m'              => 8,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('egg-productions.label', $collecte->id))
        ->assertOk()
        ->assertSee('LOT-EGG-1')
        ->assertSee('480')
        ->assertSee('data:image/png;base64', false);
});

// ─── Provenderie : OP d'aliment ──────────────────────────────────────────────

test('la traçabilité publique d\'un OP d\'aliment affiche la formule sans coût', function () {
    $formula = Formula::create([
        'farm_id' => $this->farm->id, 'name' => 'Ponte Standard', 'code' => 'PS-1',
        'target_type' => 'ponte', 'total_batch_weight' => 1000, 'is_active' => true,
    ]);

    $op = MillProduction::create([
        'farm_id'           => $this->farm->id,
        'batch_number'      => 'OP-2026-000099',
        'formula_id'        => $formula->id,
        'quantity_produced' => 2000,
        'real_cost_per_kg'  => 1234,
        'operator_id'       => $this->operatorUser->id,
        'status'            => 'Terminé',
    ]);

    $this->get(route('trace.mill', 'OP-2026-000099'))
        ->assertOk()
        ->assertSee('OP-2026-000099')
        ->assertSee('Ponte Standard')
        ->assertDontSee('1234'); // aucun coût exposé au public

    $this->actingAs($this->adminUser)
        ->get(route('production.label', $op->id))
        ->assertOk()
        ->assertSee('OP-2026-000099')
        ->assertSee('data:image/png;base64', false);
});

// ─── Végétal : transformation ────────────────────────────────────────────────

test('la traçabilité publique d\'une transformation végétale affiche le produit', function () {
    $t = CropTransformation::create([
        'farm_id'         => $this->farm->id,
        'batch_number'    => 'TRV-2026-000050',
        'input_product'   => 'Manioc',
        'output_product'  => 'Gari',
        'transformation_type' => 'mouture',
        'output_quantity' => 120,
        'output_unit'     => 'kg',
        'production_date' => now()->toDateString(),
        'status'          => 'termine',
    ]);

    $this->get(route('trace.crop', 'TRV-2026-000050'))
        ->assertOk()
        ->assertSee('TRV-2026-000050')
        ->assertSee('Gari')
        ->assertSee('Manioc');

    $this->actingAs($this->adminUser)
        ->get(route('crop-transformations.label', $t->id))
        ->assertOk()
        ->assertSee('data:image/png;base64', false);
});

// ─── Logistique : expédition ─────────────────────────────────────────────────

test('la traçabilité publique d\'une expédition liste son contenu', function () {
    $dispatch = Dispatch::create([
        'farm_id'         => $this->farm->id,
        'dispatch_number' => 'EXP-2026-000007',
        'destination'     => 'Marché de Madina',
        'driver_name'     => 'Sékou Camara',
        'dispatch_date'   => now()->toDateString(),
        'dispatched_by'   => $this->adminUser->id,
        'status'          => 'expedie',
    ]);
    $dispatch->items()->create([
        'farm_id' => $this->farm->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs calibre L', 'quantity_dispatched' => 50, 'unit' => 'Alvéole',
    ]);

    $this->get(route('trace.dispatch', 'EXP-2026-000007'))
        ->assertOk()
        ->assertSee('EXP-2026-000007')
        ->assertSee('Marché de Madina')
        ->assertSee('Œufs calibre L');

    $this->actingAs($this->adminUser)
        ->get(route('dispatches.label', $dispatch->id))
        ->assertOk()
        ->assertSee('data:image/png;base64', false);
});

test('un numéro de document inconnu renvoie 404 sur tous les types', function () {
    $this->get(route('trace.mill', 'OP-X'))->assertNotFound();
    $this->get(route('trace.crop', 'TRV-X'))->assertNotFound();
    $this->get(route('trace.dispatch', 'EXP-X'))->assertNotFound();
    $this->get(route('trace.harvest', 'uuid-bidon'))->assertNotFound();
});

// ─── Végétal : récolte fraîche ───────────────────────────────────────────────

test('la traçabilité publique d\'une récolte affiche culture, date et quantité', function () {
    $plot = \App\Models\Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle Nord', 'area_ha' => 1, 'status' => 'cultive',
    ]);
    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'crop_name' => 'Tomate', 'variety' => 'Roma',
        'area_used_ha' => 0.5, 'planting_date' => now()->subMonths(3)->toDateString(), 'status' => 'recolte',
    ]);
    $harvest = Harvest::create([
        'farm_id'      => $this->farm->id,
        'crop_cycle_id' => $cycle->id,
        'harvest_date' => now()->toDateString(),
        'quantity'     => 220,
        'unit'         => 'kg',
        'quality'      => 'bon',
    ]);

    $this->get(route('trace.harvest', $harvest->uuid))
        ->assertOk()
        ->assertSee('Tomate')
        ->assertSee('220');

    $this->actingAs($this->adminUser)
        ->get(route('crop-cycles.harvests.label', [$cycle->id, $harvest->id]))
        ->assertOk()
        ->assertSee('Tomate')
        ->assertSee('data:image/png;base64', false);
});

// ─── Stock : étiquette de rayon (interne) ────────────────────────────────────

test('l\'étiquette d\'un article de stock pointe vers la fiche interne et exige une lecture', function () {
    $stock = Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Maïs concassé',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 800,
        'alert_threshold'  => 100,
    ]);

    // Anonyme → login.
    $this->get(route('stocks.label', $stock->id))->assertRedirect(route('login'));

    // L'étiquette encode l'URL INTERNE de la fiche stock (pas une page publique).
    $this->actingAs($this->adminUser)
        ->get(route('stocks.label', $stock->id))
        ->assertOk()
        ->assertSee('Maïs concassé')
        ->assertSee('Article de stock')
        ->assertSee('data:image/png;base64', false);
});

test('l\'étiquette est configurable : copies, colonnes, et pas d\'impression auto par défaut', function () {
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-CFG-1']);

    // Par défaut : aperçu (barre d'actions), AUCUNE impression automatique.
    $resp = $this->actingAs($this->adminUser)->get(route('batches.label', $batch->id))->assertOk();
    $resp->assertSee('Aperçu')->assertSee('Copies');
    expect($resp->getContent())->not->toContain('setTimeout(() => window.print()');

    // ?copies=3 → le QR est répété 3 fois (3 étiquettes sur la page).
    $resp3 = $this->actingAs($this->adminUser)->get(route('batches.label', ['batch' => $batch->id, 'copies' => 3]))->assertOk();
    expect(substr_count($resp3->getContent(), 'class="label"'))->toBe(3);
});

test('le paramètre autoprint relance l\'impression automatique', function () {
    \App\Models\Setting::updateOrCreate(
        ['group' => 'etiquettes', 'key' => 'autoprint', 'farm_id' => null],
        ['value' => '1', 'type' => 'boolean']
    );
    \Illuminate\Support\Facades\Cache::flush();

    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-AP-1']);
    $resp = $this->actingAs($this->adminUser)->get(route('batches.label', $batch->id))->assertOk();
    expect($resp->getContent())->toContain('window.print()');
});

test('les réglages exposent le groupe Étiquettes avec ses options', function () {
    $this->actingAs($this->adminUser)
        ->get(route('settings.index', ['group' => 'etiquettes']))
        ->assertOk()
        ->assertSee('Étiquettes')
        ->assertSee('Nombre de copies par défaut')
        ->assertSee('Type de code');
});

test('en mode code-barres, l\'étiquette rend un Code128 SVG (et pas de QR)', function () {
    \App\Models\Setting::updateOrCreate(
        ['group' => 'etiquettes', 'key' => 'symbology', 'farm_id' => null],
        ['value' => 'barcode', 'type' => 'select', 'options' => 'qr,barcode,both']
    );
    \Illuminate\Support\Facades\Cache::flush();

    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-BC-1']);
    $resp = $this->actingAs($this->adminUser)->get(route('batches.label', $batch->id))->assertOk();

    expect($resp->getContent())->toContain('<svg')->toContain('shape-rendering="crispEdges"');
    // En mode barcode seul, pas d'image QR base64.
    expect($resp->getContent())->not->toContain('data:image/png;base64');
});

test('en mode « both », QR et code-barres sont présents', function () {
    \App\Models\Setting::updateOrCreate(
        ['group' => 'etiquettes', 'key' => 'symbology', 'farm_id' => null],
        ['value' => 'both', 'type' => 'select', 'options' => 'qr,barcode,both']
    );
    \Illuminate\Support\Facades\Cache::flush();

    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'LOT-BOTH-1']);
    $resp = $this->actingAs($this->adminUser)->get(route('batches.label', $batch->id))->assertOk();

    expect($resp->getContent())->toContain('data:image/png;base64')->toContain('<svg');
});
