<?php

use App\Models\Batch;
use App\Models\EggProduction;
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
