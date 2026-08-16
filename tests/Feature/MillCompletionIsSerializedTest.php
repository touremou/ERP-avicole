<?php

use App\Actions\MillProduction\CompleteMillProduction;
use App\Models\Formula;
use App\Models\FormulaItem;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\RawMaterial;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX CLÔTURES SIMULTANÉES CONSOMMAIENT LA MATIÈRE DEUX FOIS.
 *
 * La garde « déjà clôturée » lisait le statut de l'objet REÇU — une copie
 * chargée par la requête, avant que l'autre n'écrive. Deux requêtes concurrentes
 * voyaient donc toutes deux « Planifié » et passaient l'une comme l'autre.
 *
 * ─── MESURÉ ───
 *
 * Deux clôtures du même ordre de 200 kg :
 *
 *     maïs concassé ....... 1 000 kg → 600 kg     (400 consommés pour 200 produits)
 *     aliment fini ........ deux entrées pour une seule fabrication
 *
 * C'est le double-clic sur « Clôturer » depuis une connexion lente — le geste
 * exact que cette base garde partout ailleurs, par un uuid d'idempotence ou un
 * refus de rejeu.
 *
 * ─── LA PROTECTION TENAIT UN CHEMIN SUR DEUX ───
 *
 * La synchro mobile verrouillait déjà la ligne avant d'appeler l'action
 * (`MillProduction::lockForUpdate()`), et refuse proprement en conflit. Le web
 * appelait la MÊME action sans verrou ni transaction : `findOrFail` puis
 * `execute()`.
 *
 * Le verrou vit désormais DANS l'action, donc sur les deux chemins. Celui de la
 * synchro, pris sur la même ligne dans la même transaction, reste sans effet de
 * bord.
 *
 * ─── POURQUOI LE TEST EST ÉCRIT AINSI ───
 *
 * On ne simule pas deux processus : on charge DEUX instances du même ordre avant
 * toute écriture, ce que font littéralement deux requêtes concurrentes. La
 * seconde porte donc un statut périmé — la condition exacte du défaut.
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
        'formula_id' => $formule->id, 'raw_material_id' => $this->matiere->id, 'percentage' => 100,
    ]);

    $machine = MillMachine::create([
        'farm_id' => $this->farm->id, 'name' => 'Broyeur 1', 'type' => 'Broyeur',
        'capacity_per_hour' => 500, 'status' => 'Opérationnel',
    ]);

    $this->op = MillProduction::create([
        'farm_id' => $this->farm->id, 'formula_id' => $formule->id,
        'machine_id' => $machine->id, 'operator_id' => $this->adminUser->id,
        'batch_number' => 'OP-001', 'quantity_produced' => 200, 'status' => 'Planifié',
    ]);
});

/** Une copie de l'ordre, telle qu'une requête la charge. */
function copieDeLOrdre(int $id): MillProduction
{
    return MillProduction::with(['formula.items.rawMaterial', 'machine', 'machines'])->findOrFail($id);
}

/** Exécute la clôture sur une copie et dit si elle a abouti. */
function cloturer(MillProduction $copie): bool
{
    try {
        app(CompleteMillProduction::class)->execute($copie);

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

test('la seconde clôture concurrente est refusée', function () {
    // Les deux copies sont chargées AVANT toute écriture : la seconde porte un
    // statut périmé, exactement comme la seconde requête d'un double-clic.
    $a = copieDeLOrdre($this->op->id);
    $b = copieDeLOrdre($this->op->id);

    expect(cloturer($a))->toBeTrue()
        ->and(cloturer($b))->toBeFalse();
});

test('la matière première n’est consommée qu’une fois', function () {
    // LE défaut, chiffré : 400 kg consommés pour 200 kg produits.
    $a = copieDeLOrdre($this->op->id);
    $b = copieDeLOrdre($this->op->id);

    cloturer($a);
    cloturer($b);

    expect((float) $this->matiere->fresh()->stock_qty)->toBe(800.0);
});

test('l’aliment fini n’entre qu’une fois en stock', function () {
    /*
     * L'autre moitié du double-comptage : deux entrées d'aliment fini pour une
     * seule fabrication gonflent l'inventaire — et le coût de revient qui s'en
     * déduit.
     */
    $a = copieDeLOrdre($this->op->id);
    $b = copieDeLOrdre($this->op->id);

    cloturer($a);
    cloturer($b);

    $aliment = Stock::where('category', Stock::CAT_CONSO)->sum('current_quantity');

    expect((float) $aliment)->toBe(200.0);
});

test('une clôture seule fonctionne normalement', function () {
    // La borne : on sérialise, on ne bloque pas. Sans elle, un verrou trop
    // large ferait passer les tests ci-dessus en cassant le geste.
    expect(cloturer(copieDeLOrdre($this->op->id)))->toBeTrue()
        ->and($this->op->fresh()->status)->toBe('Terminé')
        ->and((float) $this->matiere->fresh()->stock_qty)->toBe(800.0);
});

test('la clôture par la route web aboutit toujours', function () {
    // Le chemin réel, bout en bout : le verrou ajouté dans l'action ne doit pas
    // changer ce que voit l'utilisateur d'une clôture ordinaire.
    $this->put(route('production.complete', $this->op->id))->assertRedirect();

    expect($this->op->fresh()->status)->toBe('Terminé');
});

test('un ordre ANNULÉ reste refusé sous verrou', function () {
    // Le refus ajouté précédemment ne doit pas être emporté par la relecture
    // sous verrou — c'est le statut RELU qui décide, et il vaut « Annulé ».
    $this->put(route('production.cancel', $this->op->id));

    expect(cloturer(copieDeLOrdre($this->op->id)))->toBeFalse()
        ->and((float) $this->matiere->fresh()->stock_qty)->toBe(1000.0);
});
