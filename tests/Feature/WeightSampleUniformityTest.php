<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Uniformité AUTOMATISÉE (évolution pré-MEP) : l'opérateur saisit les pesées
 * individuelles de l'échantillon — l'ERP calcule poids moyen ET taux
 * d'uniformité CÔTÉ SERVEUR (DailyCheck::computeSampleStats, source de
 * vérité : les valeurs envoyées par le navigateur sont écrasées).
 *
 * Formule : uniformité = 100 × (pesées dans [0,9·m̄ ; 1,1·m̄]) / n.
 * Échantillon de référence : 10 pesées, moyenne 0,498 kg, bande
 * [0,4482 ; 0,5478] → 0,600 et 0,380 hors bande → 80 %.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->batch = Batch::factory()->create([
        'arrival_date'     => now()->subDays(20),
        'initial_quantity' => 500, 'current_quantity' => 500, 'qty_alive' => 500,
    ]);

    // 8 pesées dans la bande ±10 %, 2 dehors → uniformité 80 %.
    $this->samples = [0.50, 0.51, 0.49, 0.52, 0.48, 0.50, 0.51, 0.49, 0.60, 0.38];

    $this->basePayload = fn (array $overrides = []) => array_merge([
        'batch_id'      => $this->batch->id,
        'check_date'    => now()->toDateString(),
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Chair Démarrage',
    ], $overrides);
});

test('les pesées d\'échantillon produisent moyenne et uniformité calculées serveur', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->basePayload)([
            'weight_samples' => $this->samples,
        ]))
        ->assertSessionHasNoErrors();

    $check = DailyCheck::first();
    expect((float) $check->avg_weight)->toEqual(0.498);
    expect((float) $check->uniformity_pct)->toEqual(80.0);
    expect($check->weight_samples)->toHaveCount(10); // pesées conservées : vérifiable
});

test('le serveur écrase des valeurs client incohérentes avec l\'échantillon', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->basePayload)([
            'weight_samples' => $this->samples,
            'avg_weight'     => 9.999, // mensonge client
            'uniformity_pct' => 99.9,  // mensonge client
        ]))
        ->assertSessionHasNoErrors();

    $check = DailyCheck::first();
    expect((float) $check->avg_weight)->toEqual(0.498);
    expect((float) $check->uniformity_pct)->toEqual(80.0);
});

test('pesée aberrante (250 kg — erreur d\'unité) : refusée', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->basePayload)([
            'weight_samples' => [0.5, 250],
        ]))
        ->assertSessionHasErrors('weight_samples.1');

    expect(DailyCheck::count())->toBe(0);
});

test('rectification avec un nouvel échantillon : moyenne et uniformité recalculées', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->basePayload)([
            'weight_samples' => $this->samples,
        ]));

    $check = DailyCheck::first();

    // Nouvel échantillon parfaitement homogène → 100 %.
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), ($this->basePayload)([
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
            'weight_samples'     => [0.50, 0.51, 0.50, 0.49, 0.50],
        ]))
        ->assertSessionHasNoErrors();

    $check = $check->fresh();
    expect((float) $check->uniformity_pct)->toEqual(100.0);
    expect($check->weight_samples)->toHaveCount(5);
});

test('rectification SANS échantillon : les pesées enregistrées sont conservées', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), ($this->basePayload)([
            'weight_samples' => $this->samples,
        ]));

    $check = DailyCheck::first();

    // Correction d'un autre champ (mortalité) sans toucher à l'échantillon.
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), ($this->basePayload)([
            'mortality'          => 2,
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
        ]))
        ->assertSessionHasNoErrors();

    $check = $check->fresh();
    expect($check->weight_samples)->toHaveCount(10);
    expect((float) $check->uniformity_pct)->toEqual(80.0);
});
