<?php

use App\Models\Batch;
use App\Models\CropTransformation;
use App\Models\Dispatch;
use App\Models\EggProduction;
use App\Models\Formula;
use App\Models\MillProduction;
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
});
