<?php

use App\Models\CleaningLog;
use App\Models\TemperatureLog;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Saisie HACCP « en tournée » : tous les points/zones en une validation,
 * un enregistrement UNITAIRE par ligne remplie/cochée (registre inchangé).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);
});

test('tournée de températures : une ligne remplie = un relevé, lignes vides ignorées', function () {
    $this->post(route('slaughter.registres.temperatures.batch'), ['rows' => [
        'chambre_froide_positive' => ['temperature' => 2.5, 'equipment_ref' => 'CF-01'],
        'congelation'             => ['temperature' => -18],
        'salle_decoupe'           => ['temperature' => ''],   // vide → ignorée
        'echaudage'               => [],
    ]])->assertRedirect(route('slaughter.registres.temperatures'))->assertSessionHas('success');

    expect(TemperatureLog::count())->toBe(2)
        ->and(TemperatureLog::where('point', 'chambre_froide_positive')->first()->equipment_ref)->toBe('CF-01');
});

test('tournée de températures : la conformité reste évaluée ligne par ligne', function () {
    // Chambre froide positive : max réglé serveur (défaut 4 °C) → 12 °C hors seuil.
    $this->post(route('slaughter.registres.temperatures.batch'), ['rows' => [
        'chambre_froide_positive' => ['temperature' => 12, 'corrective_action' => 'Réglage compresseur'],
        'salle_decoupe'           => ['temperature' => 8],
    ]])->assertRedirect()->assertSessionHas('error'); // flash « dont X hors seuil »

    expect(TemperatureLog::where('point', 'chambre_froide_positive')->first()->conforme)->toBeFalse();
});

test('tournée de températures vide → erreur, aucun enregistrement', function () {
    $this->post(route('slaughter.registres.temperatures.batch'), ['rows' => [
        'chambre_froide_positive' => ['temperature' => ''],
    ]])->assertRedirect()->assertSessionHas('error');

    expect(TemperatureLog::count())->toBe(0);
});

test('tournée de nettoyage : une zone cochée = un enregistrement', function () {
    $this->post(route('slaughter.registres.nettoyage.batch'), ['rows' => [
        'surfaces_tables' => ['done' => 1, 'product_used' => 'Javel 12°', 'dosage' => '20 ml/L'],
        'sols_siphons'    => ['done' => 1, 'product_used' => 'Détergent D5'],
        'chambre_froide'  => ['done' => 0, 'product_used' => 'Javel 12°'], // non cochée
    ]])->assertRedirect(route('slaughter.registres.nettoyage'))->assertSessionHas('success');

    expect(CleaningLog::count())->toBe(2)
        ->and(CleaningLog::where('zone', 'surfaces_tables')->first()->dosage)->toBe('20 ml/L')
        ->and(CleaningLog::where('zone', 'chambre_froide')->exists())->toBeFalse();
});

test('tournée de nettoyage : produit obligatoire par zone cochée', function () {
    $this->post(route('slaughter.registres.nettoyage.batch'), ['rows' => [
        'surfaces_tables' => ['done' => 1, 'product_used' => ''],
    ]])->assertSessionHasErrors('rows');

    expect(CleaningLog::count())->toBe(0);
});

test('la grille de tournée prérempli produit/dosage depuis la dernière tournée', function () {
    CleaningLog::create([
        'zone' => 'surfaces_tables', 'product_used' => 'Javel 12°', 'dosage' => '20 ml/L',
        'operator_id' => $this->managerUser->id, 'done_at' => now()->subDay(), 'synced_at' => now()->subDay(),
    ]);

    $this->get(route('slaughter.registres.nettoyage'))
        ->assertOk()
        ->assertSee('Tournée de nettoyage', false)
        ->assertSee('Javel 12°', false)
        ->assertSee('20 ml/L', false);
});

test('tournée sous-produits : une ligne pesée = un enregistrement, ordre commun', function () {
    $batch = \App\Models\Batch::factory()->create(['initial_quantity' => 50, 'current_quantity' => 50, 'qty_alive' => 50]);
    $order = \App\Models\SlaughterOrder::create([
        'order_number' => \App\Models\SlaughterOrder::generateNumber(), 'batch_id' => $batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 10,
        'status' => 'termine', 'requested_by' => $this->managerUser->id,
    ]);

    $this->post(route('slaughter.registres.sous_produits.batch'), [
        'slaughter_order_id' => $order->id,
        'rows' => [
            'sang'   => ['quantity_kg' => 4.5, 'destination' => 'equarrissage'],
            'plumes' => ['quantity_kg' => 2,   'destination' => 'compost'],
            'visceres' => ['quantity_kg' => ''], // vide → ignorée
        ],
    ])->assertRedirect(route('slaughter.registres.sous_produits'))->assertSessionHas('success');

    expect(\App\Models\SlaughterByproduct::count())->toBe(2)
        ->and(\App\Models\SlaughterByproduct::where('type', 'sang')->first()->slaughter_order_id)->toBe($order->id)
        ->and(\App\Models\SlaughterByproduct::where('type', 'plumes')->first()->destination)->toBe('compost');
});

test('tournée sous-produits : destination obligatoire par ligne pesée', function () {
    $this->post(route('slaughter.registres.sous_produits.batch'), [
        'rows' => ['sang' => ['quantity_kg' => 3, 'destination' => '']],
    ])->assertSessionHasErrors('rows');

    expect(\App\Models\SlaughterByproduct::count())->toBe(0);
});

test('tournée sous-produits vide → erreur, aucun enregistrement', function () {
    $this->post(route('slaughter.registres.sous_produits.batch'), [
        'rows' => ['sang' => ['quantity_kg' => '']],
    ])->assertSessionHas('error');

    expect(\App\Models\SlaughterByproduct::count())->toBe(0);
});
