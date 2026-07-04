<?php

use App\Actions\Crop\RecordCropTransformation;
use App\Models\CropTransformation;
use App\Models\Stock;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Audit C (pré-MEP 2026-07-04) — conservation de matière en transformation
 * végétale.
 *
 * Avant : le déstockage de l'intrant était plafonné à zéro SILENCIEUSEMENT
 * (max(0, …)) → transformer 500 kg avec 100 kg en stock créait la
 * transformation, vidait le stock et faisait disparaître 400 kg de matière
 * sans erreur ni trace. Désormais : sortie STRICTE contrôlée sous verrou
 * (motif C1) + plafond de rendement anti-erreur de pesée (comme l'abattoir).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);

    $this->mais = Stock::factory()->create([
        'item_name'        => 'Maïs grain',
        'category'         => Stock::CAT_RECOLTES,
        'unit'             => 'KG',
        'current_quantity' => 100,
    ]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'input_product'       => 'Maïs grain',
        'output_product'      => 'Farine de maïs',
        'transformation_type' => 'mouture',
        'input_quantity'      => 50,
        'input_unit'          => 'kg',
        'output_quantity'     => 45,
        'output_unit'         => 'kg',
        'production_date'     => now()->toDateString(),
        'consumed_from_stock' => true,
        'input_stock_item'    => 'Maïs grain',
    ], $overrides);
});

test('transformer plus que le stock disponible : refusé, rien n\'est créé ni déstocké', function () {
    expect(fn () => app(RecordCropTransformation::class)->execute(($this->payload)([
        'input_quantity'  => 500, // 100 kg en stock seulement
        'output_quantity' => 450,
    ])))->toThrow(ValidationException::class);

    expect(CropTransformation::count())->toBe(0);
    expect((float) $this->mais->fresh()->current_quantity)->toEqual(100.0);
});

test('rendement aberrant à unités identiques (sortie >> entrée) : refusé', function () {
    expect(fn () => app(RecordCropTransformation::class)->execute(($this->payload)([
        'input_quantity'  => 10,
        'output_quantity' => 25, // 250 % en kg → erreur de pesée
    ])))->toThrow(ValidationException::class);

    expect(CropTransformation::count())->toBe(0);
});

test('unités différentes : le plafond de rendement ne s\'applique pas (kg → litres)', function () {
    $t = app(RecordCropTransformation::class)->execute(($this->payload)([
        'input_quantity'  => 10,
        'output_quantity' => 18,     // ratio > 1,5 mais L vs kg : pas comparable
        'output_unit'     => 'litre',
        'transformation_type' => 'jus',
        'output_product'  => 'Jus de maïs',
    ]));

    expect($t->id)->not->toBeNull();
});

test('transformation valide : stock intrant décrémenté exactement, flag consommé posé', function () {
    $t = app(RecordCropTransformation::class)->execute(($this->payload)());

    expect($t->consumed_from_stock)->toBeTrue();
    expect((float) $t->yield_percent)->toEqual(90.0); // 45 / 50
    expect((float) $this->mais->fresh()->current_quantity)->toEqual(50.0); // 100 - 50
});
