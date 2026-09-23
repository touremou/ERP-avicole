<?php

use App\Models\EnergySource;
use App\Models\Expense;
use App\Models\FuelPurchase;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * SUPPRIMER UN PLEIN LAISSAIT SES LITRES DANS LA CUVE.
 *
 * `storeFuelPurchase` CRÉDITE `current_fuel_level` du volume acheté.
 * `destroyFuelPurchase` retirait l'achat et sa dépense — et ne rendait rien.
 *
 * ─── MESURÉ ───
 *
 * Un plein de 100 L sur une cuve vide, puis supprimé :
 *
 *   • l'achat disparaît, la dépense aussi ;
 *   • la cuve annonce toujours 100 L.
 *
 * Ce solde n'est pas décoratif : `current_fuel_level` porte l'autonomie
 * affichée au tableau de bord (`EnergySource::autonomyDays`) et l'alerte
 * « commander du carburant ». Du carburant fantôme retarde donc la commande
 * — jusqu'à la panne sèche du groupe électrogène, qui est précisément ce que
 * l'alerte existe pour empêcher.
 *
 * ─── LE PENDANT DE LA MODIFICATION ───
 *
 * La régularisation de cuve vient d'être posée sur l'écran de CORRECTION :
 * rendre à l'ancienne cuve, créditer la nouvelle. La SUPPRESSION est le même
 * geste amputé de sa seconde moitié — elle rend, et ne crédite rien.
 *
 * Un geste qui défait un achat doit défaire ce que l'achat avait fait.
 *
 * ─── ET LA DÉPENSE S'ANNULE, ELLE NE S'EFFACE PAS ───
 *
 * Le même geste appelait `$purchase->expense?->delete()` — c'est-à-dire
 * exactement ce que `ExpenseController::destroy` REFUSE, et pour une raison
 * qu'il écrit en toutes lettres :
 *
 *     « Une dépense validée ne se supprime pas — elle s'annule. […]
 *       L'annulation garde la pièce, sa trace et son motif ; la suppression
 *       efface tout. »
 *
 * `ValidatedExpenseCannotBeDeletedTest` fige ce refus. Mais la garde vit sur le
 * contrôleur des dépenses : en appelant le MODÈLE directement, ce chemin-ci
 * passait à côté. Et la dépense d'un achat de carburant est TOUJOURS validée,
 * puisque `syncLedgerExpense()` la crée ainsi — le geste interdit par une porte
 * était donc accompli par une autre, à chaque suppression.
 *
 * PRÉCISION QUI COMPTE : ce `delete()` était un SOFT-DELETE. La ligne survivait
 * en base — elle n'était donc pas perdue pour qui interroge la base. Mais elle
 * quittait le registre et le compte de résultat sans rien laisser de VISIBLE :
 * personne, depuis l'application, ne pouvait plus dire ce qui avait été annulé
 * ni pour combien. C'est cette visibilité que l'annulation rend.
 *
 * ─── CE QUE L'ANNULATION GARDE, ET CE QU'ELLE NE GARDE PAS ───
 *
 * Mesuré, sur un plein de 100 L à 1 200 000 GNF chez « Total Guinée », reçu
 * REC-4417 :
 *
 *   GARDÉ   — la pièce existe, statut « annule », avec son libellé, son
 *             montant, son fournisseur et sa référence de reçu. Le registre
 *             peut répondre à « qu'est-ce qui a été annulé, et pour combien ».
 *   PAS GARDÉ — le mouvement de caisse. `ExpenseObserver::saved` contre-passe
 *             dès que le statut quitte « valide » : les sorties retombent à 0
 *             et le solde remonte.
 *
 * Ce second point est INHÉRENT à la sémantique d'annulation du dépôt, celle-là
 * même qu'il présente comme le remède légitime. Le préserver demanderait de
 * remplacer `reverseFor()` — qui SUPPRIME l'écriture — par une véritable
 * contre-passation qui en écrit une opposée, et cela vaudrait pour tous les
 * encaissements, règlements et salaires. C'est une décision de trésorerie, pas
 * une correction de carburant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->cuve = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Perkins', 'type' => 'groupe',
        'fuel_type' => 'gasoil', 'is_active' => true,
        'current_fuel_level' => 0, 'fuel_tank_capacity' => 1000,
    ]);
});

/** Un plein enregistré par la VRAIE porte : la cuve est donc créditée. */
function pleinDe(object $test, EnergySource $cuve, float $litres): FuelPurchase
{
    $test->post(route('utilities.fuel.store'), [
        'energy_source_id' => $cuve->id,
        'purchase_date'    => now()->subDay()->toDateString(),
        'quantity_liters'  => $litres,
        'unit_price'       => 12_000,
        'supplier'         => 'Total',
    ]);

    return FuelPurchase::latest('id')->firstOrFail();
}

test('supprimer un plein retire ses litres de la cuve', function () {
    /*
     * LE défaut : la cuve gardait un carburant dont plus aucune pièce ne
     * justifiait l'entrée.
     */
    $achat = pleinDe($this, $this->cuve, 100);

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(100.0);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(0.0);
});

test('et ne retire QUE les siens', function () {
    /*
     * On rend ce que CET achat avait crédité, pas le contenu de la cuve : deux
     * pleins, en supprimer un ne doit pas effacer l'autre.
     */
    pleinDe($this, $this->cuve, 200);
    $second = pleinDe($this, $this->cuve, 100);

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(300.0);

    $this->delete(route('utilities.fuel.destroy', $second));

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(200.0);
});

test('la cuve ne descend jamais sous zéro', function () {
    /*
     * Le carburant a pu être consommé depuis : les relevés débitent la même
     * colonne. Rendre plus que ce qu'elle contient annoncerait un niveau
     * négatif, qu'aucun réservoir ne peut porter.
     */
    $achat = pleinDe($this, $this->cuve, 100);

    // Le groupe a tourné : la cuve est redescendue à 30 L.
    $this->cuve->update(['current_fuel_level' => 30]);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(0.0);
});

test('l’achat disparaît, mais sa dépense est ANNULÉE — pas effacée', function () {
    /*
     * LE geste que `ExpenseController::destroy` refuse, et que ce chemin-ci
     * accomplissait en appelant le modèle directement. La dépense d'un achat de
     * carburant est toujours validée : la règle s'appliquait donc à chaque
     * suppression, et n'était appliquée nulle part.
     */
    $achat = pleinDe($this, $this->cuve, 100);

    expect(Expense::count())->toBe(1)
        ->and(Expense::first()->status)->toBe('valide');

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect(FuelPurchase::count())->toBe(0)
        ->and(Expense::count())->toBe(1)
        ->and(Expense::first()->status)->toBe('annule');
});

test('la pièce annulée garde ce qui permet d’y répondre', function () {
    /*
     * « L'annulation garde la pièce, sa trace et son motif ; la suppression
     * efface tout. » Une pièce annulée qui aurait perdu son montant ou son
     * fournisseur ne vaudrait pas mieux qu'une pièce effacée.
     */
    $this->post(route('utilities.fuel.store'), [
        'energy_source_id'  => $this->cuve->id,
        'purchase_date'     => now()->subDay()->toDateString(),
        'quantity_liters'   => 100,
        'unit_price'        => 12_000,
        'supplier'          => 'Total Guinée',
        'receipt_reference' => 'REC-4417',
    ]);

    $this->delete(route('utilities.fuel.destroy', FuelPurchase::firstOrFail()));

    $depense = Expense::firstOrFail();

    expect($depense->label)->toContain('Groupe Perkins')
        ->and((float) $depense->amount)->toBe(1_200_000.0)
        ->and($depense->supplier_name)->toBe('Total Guinée')
        ->and($depense->notes)->toContain('REC-4417');
});

test('et le coût quitte bien le compte de résultat', function () {
    /*
     * L'annulation doit produire l'effet COMPTABLE de la suppression : la
     * charge s'en va. Garder la pièce sans retirer le coût laisserait le
     * résultat faux — on aurait échangé un défaut contre un autre.
     */
    $achat = pleinDe($this, $this->cuve, 100);

    expect((float) Expense::validated()->sum('amount'))->toBe(1_200_000.0);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect((float) Expense::validated()->sum('amount'))->toBe(0.0);
});

test('le message ne promet plus une suppression qui n’a pas lieu', function () {
    /*
     * L'écran annonçait « Achat et dépense liée supprimés ». La dépense n'est
     * plus supprimée : le dire autrement serait mentir à l'opérateur sur l'état
     * de son registre.
     */
    $achat = pleinDe($this, $this->cuve, 100);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect(session('success'))->toContain('annulée');
});

test('un achat supprimé APRÈS correction rend le volume corrigé', function () {
    /*
     * Les deux régularisations doivent s'accorder : la correction a porté le
     * plein à 150 L, c'est 150 L que la suppression doit rendre — pas les 100
     * d'origine, qui laisseraient 50 L fantômes.
     */
    $achat = pleinDe($this, $this->cuve, 100);

    $this->put(route('utilities.fuel.update', $achat), [
        'energy_source_id' => $this->cuve->id,
        'purchase_date'    => now()->subDay()->toDateString(),
        'quantity_liters'  => 150,
        'unit_price'       => 12_000,
        'supplier'         => 'Total',
    ]);

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(150.0);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect((float) $this->cuve->fresh()->current_fuel_level)->toBe(0.0);
});

test('un achat déplacé puis supprimé rend à la BONNE cuve', function () {
    /*
     * Même exigence de bout en bout : après un changement de groupe, c'est la
     * cuve d'ARRIVÉE qui doit être débitée — l'autre a déjà été rendue à la
     * correction.
     */
    $caterpillar = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Caterpillar', 'type' => 'groupe',
        'fuel_type' => 'gasoil', 'is_active' => true,
        'current_fuel_level' => 0, 'fuel_tank_capacity' => 1000,
    ]);

    $achat = pleinDe($this, $this->cuve, 100);

    $this->put(route('utilities.fuel.update', $achat), [
        'energy_source_id' => $caterpillar->id,
        'purchase_date'    => now()->subDay()->toDateString(),
        'quantity_liters'  => 100,
        'unit_price'       => 12_000,
        'supplier'         => 'Total',
    ]);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect((float) $caterpillar->fresh()->current_fuel_level)->toBe(0.0)
        ->and((float) $this->cuve->fresh()->current_fuel_level)->toBe(0.0);
});
