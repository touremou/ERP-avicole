<?php

use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RAPPORT TECHNIQUE LIT LE LOT, IL NE LE RECALCULE PAS.
 *
 * Trois chiffres y avaient leur propre recette :
 *   • l'effectif = initial − morts : ventes ignorées, morts au transport
 *     retranchées d'un effectif qui ne les a jamais comptées ;
 *   • le poids = le DERNIER POINTAGE (0 g sans pesée ce jour-là), choisi par
 *     une sous-requête qui mélangeait les dates des lots ;
 *   • le gain quotidien = poids ÷ âge, quand la fiche mesure entre pesées.
 * Et l'indice de consommation prenait un « 0 » saisi pour une pesée.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    // 960 vivants reçus (+ 40 morts au transport), 20 morts, 100 vendus.
    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 960, 'qty_dead' => 40, 'current_quantity' => 840,
        'arrival_date' => now()->subDays(30)->toDateString(), 'birth_date' => now()->subDays(30)->toDateString(),
    ]);
    $pointage = fn (int $ilYA, ?float $poids, int $morts = 0, float $aliment = 0) => DB::table('daily_checks')->insert([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id, 'check_date' => now()->subDays($ilYA)->toDateString(),
        'avg_weight' => $poids, 'mortality' => $morts, 'feed_consumed' => $aliment, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pointage(20, 0.5, 20, 500);   // pesée : 500 g
    $pointage(10, 1.1, 0, 500);    // pesée : 1 100 g → +60 g/j sur 10 jours
    $pointage(1, 0.0, 0, 0);       // pointage SANS pesée, saisi « 0 »
});

test('effectif, poids et gain sont ceux de la fiche du lot', function () {
    $ligne = collect($this->get(route('reports.technical'))->assertOk()->viewData('stats'))->firstWhere('id', $this->lot->id);

    expect($ligne['current'])->toBe(840)
        ->and($ligne['avg_weight'])->toEqual(1100)
        ->and($ligne['daily_gain'])->toEqual(60.0)
        ->and($ligne['daily_gain'])->toEqual($this->lot->gmq());
});

test('un « 0 » saisi n’est pas une pesée pour l’indice de consommation', function () {
    expect($this->lot->fcr_corrected)->not->toBeNull()
        ->and($this->lot->fcr_corrected)->toBeGreaterThan(0);
});
