<?php

use App\Models\Formula;
use App\Models\FormulaItem;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\RawMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN ORDRE ANNULÉ POUVAIT ÊTRE CLÔTURÉ — ET CONSOMMAIT LA MATIÈRE.
 *
 * La synchro mobile refuse deux cas côte à côte, en toutes lettres :
 *
 *     « L'OP #:op a déjà été clôturée (en ligne ou par un autre appareil). »
 *     « L'OP #:op a été annulée. »
 *
 * `CompleteMillProduction` — l'action que les DEUX chemins appellent — portait
 * le premier refus et ignorait le second. Le bureau pouvait donc clôturer un
 * ordre annulé ; le terrain, non.
 *
 * ─── CE N'ÉTAIT PAS UN SIMPLE CHANGEMENT DE STATUT ───
 *
 * La clôture CONSOMME les matières premières et produit l'aliment fini.
 * Mesuré sur un ordre de 200 kg annulé puis clôturé :
 *
 *     maïs concassé ....... 1 000 kg  →  800 kg
 *     statut de l'ordre ... « Annulé » →  « Terminé »
 *
 * ─── LE COMMENTAIRE DE L'ANNULATION N'ÉTAIT VRAI QU'À MOITIÉ ───
 *
 * `MillProductionController::cancel` explique son innocuité ainsi : « la
 * consommation des matières premières n'a lieu qu'à la clôture, donc aucun
 * stock à contre-passer pour un OP planifié ». La phrase est exacte au moment
 * de l'annulation — et cesse de l'être si l'ordre annulé peut encore être
 * clôturé ensuite.
 *
 * L'annulation existe pour les ordres qui N'AURONT PAS LIEU : panne, erreur de
 * saisie. Un ordre annulé qui se relève est une annulation qui n'annule pas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->matiere = RawMaterial::create([
        'farm_id' => $this->farm->id, 'name' => 'Maïs concassé',
        'unit' => 'kg', 'stock_qty' => 1_000, 'alert_threshold' => 100,
        'unit_cost' => 4_000, 'is_active' => true,
    ]);

    $formule = Formula::create([
        'farm_id' => $this->farm->id, 'name' => 'Démarrage chair', 'code' => 'F-001',
        'target_type' => 'volaille', 'total_batch_weight' => 100, 'is_active' => true,
    ]);

    FormulaItem::create([
        'formula_id' => $formule->id, 'raw_material_id' => $this->matiere->id,
        'percentage' => 100,
    ]);

    $machine = MillMachine::create([
        'farm_id' => $this->farm->id, 'name' => 'Broyeur 1', 'type' => 'Broyeur',
        'capacity_per_hour' => 500, 'status' => 'Opérationnel',
    ]);

    $this->op = MillProduction::create([
        'farm_id' => $this->farm->id,
        'formula_id' => $formule->id,
        'machine_id' => $machine->id,
        'operator_id' => $this->adminUser->id,
        'batch_number' => 'OP-001',
        'quantity_produced' => 200,
        'status' => 'Planifié',
    ]);
});

/** Stock de matière première, en kg. */
function stockMatiere(RawMaterial $m): float
{
    return (float) $m->fresh()->stock_qty;
}

test('clôturer un ordre ANNULÉ est refusé', function () {
    $this->put(route('production.cancel', $this->op->id));

    $this->put(route('production.complete', $this->op->id))
        ->assertRedirect()->assertSessionHas('error');

    expect($this->op->fresh()->status)->toBe('Annulé');
});

test('la matière première d’un ordre annulé n’est PAS consommée', function () {
    // LE défaut, chiffré : 1 000 kg → 800 kg pour un ordre qui n'a pas eu lieu.
    $this->put(route('production.cancel', $this->op->id));

    $this->put(route('production.complete', $this->op->id));

    expect(stockMatiere($this->matiere))->toBe(1000.0);
});

test('le refus dit quoi faire', function () {
    // Un refus sans issue pousse à contourner.
    $this->put(route('production.cancel', $this->op->id));
    $this->put(route('production.complete', $this->op->id));

    expect(session('error'))->toContain('nouvel ordre');
});

test('un ordre NON annulé se clôture normalement et consomme sa matière', function () {
    /*
     * La borne : on ferme la résurrection d'un ordre annulé, pas la clôture.
     * Sans cette mesure, une garde trop large passerait les tests ci-dessus.
     */
    $this->put(route('production.complete', $this->op->id));

    expect($this->op->fresh()->status)->toBe('Terminé')
        ->and(stockMatiere($this->matiere))->toBe(800.0);
});

test('une double clôture reste refusée', function () {
    // Le refus qui existait déjà ne doit pas être emporté par le nouveau.
    $this->put(route('production.complete', $this->op->id));

    $this->put(route('production.complete', $this->op->id))
        ->assertRedirect()->assertSessionHas('error');

    expect(stockMatiere($this->matiere))->toBe(800.0);
});

test('annuler un ordre DÉJÀ clôturé reste refusé', function () {
    // L'autre sens de la même règle, déjà tenu par le contrôleur : les deux
    // états terminaux se protègent l'un l'autre.
    $this->put(route('production.complete', $this->op->id));

    $this->put(route('production.cancel', $this->op->id));

    expect($this->op->fresh()->status)->toBe('Terminé');
});
