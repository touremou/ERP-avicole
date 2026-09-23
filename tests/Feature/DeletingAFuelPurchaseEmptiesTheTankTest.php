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

test('l’achat et sa dépense disparaissent toujours — non-régression', function () {
    // Ce que le geste faisait déjà, et qui ne change pas.
    $achat = pleinDe($this, $this->cuve, 100);

    expect(Expense::count())->toBe(1);

    $this->delete(route('utilities.fuel.destroy', $achat));

    expect(FuelPurchase::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
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
