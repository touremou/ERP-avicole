<?php

use App\Services\Discrepancy\DiscrepancyEvaluator;

uses(Tests\TestCase::class);

/*
 * Tests du moteur d'écart (Three-Way Matching).
 *
 * Volontairement SANS base de données : on surcharge config('logistique.*')
 * avec des entrées dépourvues de clé 'setting', si bien que le moteur résout
 * directement les valeurs 'default' sans jamais appeler setting() (donc sans
 * toucher la table settings). Les valeurs ci-dessous rejouent les défauts
 * réels de config/logistique.php.
 */
beforeEach(function () {
    config()->set('logistique.tolerances', [
        'oeufs'      => ['default' => 2],
        'animal_vif' => ['default' => 0],
        'carcasse'   => ['default' => 1],
        'default'    => ['default' => 1],
    ]);
    config()->set('logistique.severity', [
        'attention' => ['default' => 2],
        'critique'  => ['default' => 5],
    ]);

    $this->evaluator = new DiscrepancyEvaluator();
});

// ─── evaluateLine ───────────────────────────────────────────────────────

test('manquant = expédié - reçu - endommagé', function () {
    $line = $this->evaluator->evaluateLine('oeufs', 100, 90, 5);

    expect($line->missing)->toBe(5.0)
        ->and($line->lineRate)->toBe(5.0);
});

test('le manquant ne peut jamais être négatif', function () {
    // reçu + endommagé > expédié : interdit par la validation, mais le moteur
    // doit rester sûr et borner le manquant à 0 (jamais négatif).
    $line = $this->evaluator->evaluateLine('oeufs', 100, 100, 50);

    expect($line->missing)->toBe(0.0);
});

test('une ligne dans la tolérance n est pas critique', function () {
    // oeufs : tolérance 2 % ; 1 manquant / 100 = 1 % ≤ 2 %.
    $line = $this->evaluator->evaluateLine('oeufs', 100, 99, 0);

    expect($line->withinTolerance)->toBeTrue();
});

test('une ligne hors tolérance est marquée critique', function () {
    // animal_vif : tolérance 0 % ; 1 manquant / 10 = 10 % > 0 %.
    $line = $this->evaluator->evaluateLine('animal_vif', 10, 9, 0);

    expect($line->withinTolerance)->toBeFalse()
        ->and($line->tolerance)->toBe(0.0);
});

test('expédié nul donne un taux de 0 sans division par zéro', function () {
    $line = $this->evaluator->evaluateLine('oeufs', 0, 0, 0);

    expect($line->lineRate)->toBe(0.0)
        ->and($line->withinTolerance)->toBeTrue();
});

test('hasDiscrepancy est vrai si endommagé seul (sans manquant)', function () {
    $line = $this->evaluator->evaluateLine('carcasse', 50, 45, 5);

    expect($line->missing)->toBe(0.0)
        ->and($line->damaged)->toBe(5.0)
        ->and($line->hasDiscrepancy())->toBeTrue();
});

// ─── toleranceFor ───────────────────────────────────────────────────────

test('un type inconnu retombe sur la tolérance par défaut', function () {
    expect($this->evaluator->toleranceFor('type_bidon'))->toBe(1.0);
});

// ─── evaluateReception : agrégation ──────────────────────────────────────

test('agrégation des totaux sur une réception', function () {
    $eval = $this->evaluator->evaluateReception([
        ['product_type' => 'oeufs',    'dispatched' => 100, 'received' => 90, 'damaged' => 5],
        ['product_type' => 'carcasse', 'dispatched' => 50,  'received' => 50, 'damaged' => 0],
    ]);

    expect($eval->totalDispatched)->toBe(150.0)
        ->and($eval->totalReceived)->toBe(140.0)
        ->and($eval->totalDamaged)->toBe(5.0)
        ->and($eval->totalMissing)->toBe(5.0)
        ->and($eval->discrepancyRate)->toBe(round(5 / 150 * 100, 2));
});

// ─── evaluateReception : bandes de sévérité ──────────────────────────────

test('réception sans écart : normal et hasDiscrepancy faux', function () {
    $eval = $this->evaluator->evaluateReception([
        ['product_type' => 'oeufs', 'dispatched' => 100, 'received' => 100, 'damaged' => 0],
    ]);

    expect($eval->hasDiscrepancy())->toBeFalse()
        ->and($eval->severity)->toBe('normal');
});

test('sévérité normale sous le seuil attention', function () {
    // 1 manquant / 100 = 1 % (≤ 2 % et dans la tolérance oeufs).
    $eval = $this->evaluator->evaluateReception([
        ['product_type' => 'oeufs', 'dispatched' => 100, 'received' => 99, 'damaged' => 0],
    ]);

    expect($eval->severity)->toBe('normal')
        ->and($eval->hasCritical)->toBeFalse();
});

test('sévérité attention entre les deux seuils', function () {
    // On élargit la tolérance oeufs pour isoler la bande de sévérité AGRÉGÉE
    // (sinon la ligne serait hors tolérance et forcerait « critique »).
    config()->set('logistique.tolerances.oeufs', ['default' => 5]);

    $eval = $this->evaluator->evaluateReception([
        ['product_type' => 'oeufs', 'dispatched' => 100, 'received' => 97, 'damaged' => 0],
    ]);

    expect($eval->discrepancyRate)->toBe(3.0)
        ->and($eval->hasCritical)->toBeFalse()
        ->and($eval->severity)->toBe('attention');
});

test('sévérité critique au dessus du seuil critique', function () {
    config()->set('logistique.tolerances.oeufs', ['default' => 50]); // neutralise le critère par ligne

    $eval = $this->evaluator->evaluateReception([
        ['product_type' => 'oeufs', 'dispatched' => 100, 'received' => 90, 'damaged' => 0], // 10 % > 5 %
    ]);

    expect($eval->severity)->toBe('critique');
});

test('une seule ligne hors tolérance force la sévérité critique', function () {
    // Taux global négligeable (0,1 %) mais une ligne animal_vif hors tolérance.
    $eval = $this->evaluator->evaluateReception([
        ['product_type' => 'animal_vif', 'dispatched' => 1000, 'received' => 999, 'damaged' => 0],
    ]);

    expect($eval->hasCritical)->toBeTrue()
        ->and($eval->severity)->toBe('critique');
});
