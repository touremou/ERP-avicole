<?php

use App\Models\EnergySource;
use App\Models\FuelPurchase;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CORRIGER UN ACHAT DE CARBURANT NE CORRIGEAIT PRESQUE RIEN.
 *
 * `edit-fuel.blade.php` rend la cuve en select REQUIRED et la date en champ
 * date REQUIRED. `updateFuelPurchase` ne validait NI l'une NI l'autre : cinq
 * champs seulement, et `update()` ne pouvait donc pas les écrire.
 *
 * ─── MESURÉ ───
 *
 * Un plein de 100 L saisi le 5 juin sur le « Groupe Perkins », alors que
 * c'était le « Groupe Caterpillar » le 20 juin. Le bureau corrige les deux par
 * l'écran :
 *
 *   • réponse : « Achat carburant mis à jour. » ;
 *   • cuve    : toujours Groupe Perkins ;
 *   • date    : toujours le 5 juin ;
 *   • dépense liée : libellé « Carburant — Groupe Perkins (100 L) », date du
 *     5 juin.
 *
 * `syncLedgerExpense()` bâtit son libellé sur le NOM DE LA SOURCE et sa
 * `expense_date` sur la DATE D'ACHAT. Le coût restait donc imputé au mauvais
 * groupe électrogène ET au mauvais mois du compte de résultat — définitivement,
 * l'écran de correction étant le seul recours.
 *
 * ─── ET LA CUVE NE SUIVAIT PAS DAVANTAGE ───
 *
 * Défaut antérieur, découvert en mesurant le premier : `current_fuel_level`
 * n'est pas un calcul, c'est un SOLDE COURANT — crédité par les achats, débité
 * par les relevés de consommation. Il porte l'autonomie du tableau de bord et
 * l'alerte « commander du carburant ».
 *
 * Mesuré : un plein de 100 L corrigé à 150 L laissait la cuve à 100. Le stock
 * physique annoncé était faux de la différence.
 *
 * Ouvrir le changement de cuve sans régulariser aurait AGGRAVÉ les choses :
 * l'achat serait parti sur le Caterpillar en laissant ses litres crédités au
 * Perkins. Une seule règle couvre les deux — rendre à l'ancienne, créditer la
 * nouvelle — et c'est le motif de régularisation par delta que
 * `UpdateFeedPurchase` applique déjà au stock d'aliment.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->perkins     = cuveDe($this->farm->id, 'Groupe Perkins');
    $this->caterpillar = cuveDe($this->farm->id, 'Groupe Caterpillar');
});

/** Un groupe électrogène, cuve vide, capacité 1000 L. */
function cuveDe(int $farmId, string $nom): EnergySource
{
    return EnergySource::create([
        'farm_id' => $farmId, 'name' => $nom, 'type' => 'groupe',
        'fuel_type' => 'gasoil', 'is_active' => true,
        'current_fuel_level' => 0, 'fuel_tank_capacity' => 1000,
    ]);
}

/** Un plein enregistré par la VRAIE porte (la cuve est donc créditée). */
function achatCarburant(object $test, EnergySource $cuve, float $litres = 100, ?string $date = null): FuelPurchase
{
    $test->post(route('utilities.fuel.store'), [
        'energy_source_id' => $cuve->id,
        'purchase_date'    => $date ?? now()->subDays(10)->toDateString(),
        'quantity_liters'  => $litres,
        'unit_price'       => 12_000,
        'supplier'         => 'Total',
    ]);

    return FuelPurchase::latest('id')->firstOrFail();
}

/** Le geste de l'écran de correction. */
function corrigerLAchat(object $test, FuelPurchase $achat, array $champs = [])
{
    return $test->put(route('utilities.fuel.update', $achat), array_merge([
        'energy_source_id' => $achat->energy_source_id,
        'purchase_date'    => $achat->purchase_date->toDateString(),
        'quantity_liters'  => (float) $achat->quantity_liters,
        'unit_price'       => (float) $achat->unit_price,
        'supplier'         => 'Total',
    ], $champs));
}

test('corriger la CUVE la change enfin', function () {
    /*
     * LE défaut : l'écran répondait « mis à jour » et le plein restait imputé
     * au mauvais groupe.
     */
    $achat = achatCarburant($this, $this->perkins);

    corrigerLAchat($this, $achat, ['energy_source_id' => $this->caterpillar->id]);

    expect($achat->fresh()->energy_source_id)->toBe($this->caterpillar->id);
});

test('et les LITRES suivent la cuve', function () {
    /*
     * Changer la cuve sans déplacer les litres aurait remplacé un défaut par un
     * autre : l'achat sur le Caterpillar, le carburant au Perkins.
     */
    $achat = achatCarburant($this, $this->perkins, 100);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(100.0);

    corrigerLAchat($this, $achat, ['energy_source_id' => $this->caterpillar->id]);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(0.0)
        ->and((float) $this->caterpillar->fresh()->current_fuel_level)->toBe(100.0);
});

test('corriger la DATE la change, et déplace la dépense avec elle', function () {
    /*
     * `syncLedgerExpense()` bâtit `expense_date` sur la date d'achat : sans
     * cela, le coût restait dans le mauvais mois du compte de résultat.
     */
    $achat = achatCarburant($this, $this->perkins, 100, now()->subDays(20)->toDateString());

    $nouvelleDate = now()->subDays(3)->toDateString();
    corrigerLAchat($this, $achat, ['purchase_date' => $nouvelleDate]);

    expect($achat->fresh()->purchase_date->toDateString())->toBe($nouvelleDate)
        ->and($achat->fresh()->expense->expense_date->toDateString())->toBe($nouvelleDate);
});

test('et le libellé de la dépense suit la cuve', function () {
    // Le libellé porte le nom de la source : il mentait sur l'imputation.
    $achat = achatCarburant($this, $this->perkins);

    corrigerLAchat($this, $achat, ['energy_source_id' => $this->caterpillar->id]);

    expect($achat->fresh()->expense->label)->toContain('Groupe Caterpillar');
});

test('corriger le VOLUME met la cuve au bon niveau — défaut antérieur', function () {
    /*
     * Découvert en mesurant le premier : un plein de 100 L corrigé à 150 L
     * laissait la cuve à 100. Le stock physique annoncé — et l'alerte de niveau
     * bas qui en dépend — était faux de la différence.
     */
    $achat = achatCarburant($this, $this->perkins, 100);

    corrigerLAchat($this, $achat, ['quantity_liters' => 150]);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(150.0)
        ->and((float) $achat->fresh()->fuel_level_after)->toBe(150.0);
});

test('revoir un volume À LA BAISSE rend les litres', function () {
    // L'autre sens : une correction qui ne marcherait que vers le haut gonflerait
    // la cuve à chaque rectification.
    $achat = achatCarburant($this, $this->perkins, 100);

    corrigerLAchat($this, $achat, ['quantity_liters' => 60]);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(60.0);
});

test('la régularisation ne perturbe pas les AUTRES pleins de la cuve', function () {
    /*
     * On rend ce que CET achat avait crédité, pas le contenu de la cuve : deux
     * pleins successifs, corriger le second ne doit pas effacer le premier.
     */
    achatCarburant($this, $this->perkins, 200);
    $second = achatCarburant($this, $this->perkins, 100);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(300.0);

    corrigerLAchat($this, $second, ['quantity_liters' => 150]);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(350.0);
});

test('le plafond de cuve est respecté, comme à la création — non-régression', function () {
    // On ne déclare pas plus que ce que la cuve peut contenir.
    $achat = achatCarburant($this, $this->perkins, 900);

    corrigerLAchat($this, $achat, ['quantity_liters' => 1200]);

    expect((float) $this->perkins->fresh()->current_fuel_level)->toBe(1000.0);
});

test('une cuve inexistante est refusée', function () {
    // La même borne qu'à la création.
    $achat = achatCarburant($this, $this->perkins);

    corrigerLAchat($this, $achat, ['energy_source_id' => 999_999])
        ->assertSessionHasErrors('energy_source_id');

    expect($achat->fresh()->energy_source_id)->toBe($this->perkins->id);
});

test('une date future est refusée', function () {
    // `before_or_equal:today`, comme à la création : on n'achète pas demain.
    $achat = achatCarburant($this, $this->perkins);

    corrigerLAchat($this, $achat, ['purchase_date' => now()->addWeek()->toDateString()])
        ->assertSessionHasErrors('purchase_date');
});

test('le montant reste répercuté sur la dépense — non-régression', function () {
    // Ce que l'écran faisait déjà correctement, et qui doit continuer.
    $achat = achatCarburant($this, $this->perkins, 100);

    corrigerLAchat($this, $achat, ['quantity_liters' => 150, 'unit_price' => 13_000]);

    expect((float) $achat->fresh()->total_cost)->toBe(1_950_000.0)
        ->and((float) $achat->fresh()->expense->amount)->toBe(1_950_000.0);
});
