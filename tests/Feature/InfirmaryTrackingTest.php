<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Traçabilité de l'INFIRMERIE (remarque terrain pré-MEP).
 *
 * Trou métier corrigé : un sujet isolé (qty_quarantine_in) est déjà sorti de
 * current_quantity. S'il mourait, le déclarer dans `mortality` le décomptait
 * DEUX FOIS ; ne rien déclarer sous-estimait la mortalité et gonflait le
 * solde d'isolés à jamais. Désormais :
 * - `mortality_infirmary` = morts PARMI les isolés : zéro impact effectif,
 *   compté dans Batch::total_mortality, décrémente le solde d'infirmerie ;
 * - Batch::infirmary_count = Σ in − Σ out − Σ morts infirmerie ;
 * - garde serveur : rétablis + morts isolés ≤ solde disponible.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->batch = Batch::factory()->create([
        'arrival_date'     => now()->subDays(20),
        'initial_quantity' => 500, 'current_quantity' => 500, 'qty_alive' => 500,
        'qty_dead'         => 0,
    ]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'batch_id'      => $this->batch->id,
        'check_date'    => now()->toDateString(),
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Chair Démarrage',
    ], $overrides);
});

test('un mort en infirmerie ne décompte PAS l\'effectif deux fois mais entre dans la mortalité totale', function () {
    // J-2 : 10 sujets isolés → effectif 490, infirmerie 10.
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'check_date' => now()->subDays(2)->toDateString(),
            'qty_quarantine_in' => 10,
        ]))->assertSessionHasNoErrors();

    expect($this->batch->fresh()->current_quantity)->toBe(490);
    expect($this->batch->fresh()->infirmary_count)->toBe(10);

    // Aujourd'hui : 2 morts au troupeau + 3 morts en infirmerie + 4 rétablis.
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'mortality'           => 2,
            'mortality_infirmary' => 3,
            'qty_quarantine_out'  => 4,
        ]))->assertSessionHasNoErrors();

    $batch = $this->batch->fresh();
    // Effectif : 490 − 2 morts troupeau + 4 rétablis = 492 (les 3 morts
    // d'infirmerie n'y touchent PAS : ils étaient déjà sortis).
    expect($batch->current_quantity)->toBe(492);
    // Solde infirmerie : 10 − 4 rétablis − 3 morts = 3.
    expect($batch->infirmary_count)->toBe(3);
    // Mortalité totale du lot : 2 troupeau + 3 infirmerie = 5.
    expect($batch->total_mortality)->toBe(5);
});

test('garde : déclarer plus de sorties (rétablis + morts) que d\'isolés est refusé', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'check_date' => now()->subDay()->toDateString(),
            'qty_quarantine_in' => 5,
        ]));

    // 4 rétablis + 3 morts = 7 sorties pour 5 isolés → refus, rien n'est écrit.
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'qty_quarantine_out'  => 4,
            'mortality_infirmary' => 3,
        ]))->assertSessionHasErrors('qty_quarantine_out');

    expect($this->batch->fresh()->infirmary_count)->toBe(5);
    expect(DailyCheck::whereDate('check_date', now())->count())->toBe(0);
});

test('les isolés du jour comptent dans le disponible (isoler puis déclarer un mort le même jour)', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'qty_quarantine_in'   => 6,
            'mortality_infirmary' => 1, // mort parmi les 6 isolés du jour même
        ]))->assertSessionHasNoErrors();

    $batch = $this->batch->fresh();
    expect($batch->infirmary_count)->toBe(5);
    expect($batch->current_quantity)->toBe(494); // 500 − 6 isolés (le mort n'impacte pas)
    expect($batch->total_mortality)->toBe(1);
});

test('rectification : le pointage corrigé ne se compte pas lui-même dans le disponible', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'check_date' => now()->subDay()->toDateString(),
            'qty_quarantine_in' => 8,
        ]));

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->payload)([
            'mortality_infirmary' => 2,
        ]));

    $check = DailyCheck::whereDate('check_date', now())->first();

    // Correction : 3 morts infirmerie au lieu de 2 → disponible = 8 (hors ce
    // pointage), pas 6 : accepté.
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), ($this->payload)([
            'mortality_infirmary' => 3,
            'qty_quarantine_in'   => 0,
            'qty_quarantine_out'  => 0,
        ]))->assertSessionHasNoErrors();

    expect($this->batch->fresh()->infirmary_count)->toBe(5); // 8 − 3
    expect($this->batch->fresh()->total_mortality)->toBe(3);

    // Correction impossible : 9 morts pour 8 isolés → refus.
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), ($this->payload)([
            'mortality_infirmary' => 9,
            'qty_quarantine_in'   => 0,
            'qty_quarantine_out'  => 0,
        ]))->assertSessionHasErrors('qty_quarantine_out');
});
