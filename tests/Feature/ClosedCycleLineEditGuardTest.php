<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Harvest;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * INTERDIRE D'AJOUTER UNE LIGNE À UN CYCLE CLOS, MAIS LAISSER RÉÉCRIRE CELLES
 * QUI Y SONT DÉJÀ, NE PROTÈGE RIEN : C'EST LE MÊME CHIFFRE.
 *
 * Le contrôleur des cultures a six portes qui touchent aux chiffres d'un cycle.
 * Deux vérifiaient la clôture — `storeHarvest` et `storeInput`, chacune avec
 * son message. Quatre ne la vérifiaient pas :
 *
 *   • modifier une récolte      • supprimer une récolte
 *   • modifier un intrant       • supprimer un intrant
 *
 * Mesuré sur le code d'avant, sur un cycle CLÔTURÉ le mois dernier :
 *
 *   – la quantité d'une récolte passe de 100 à 1 000, et `HarvestObserver`
 *     recalcule docilement le chiffre d'affaires du cycle clos ;
 *   – la récolte se supprime, et le chiffre d'affaires tombe à zéro ;
 *   – le coût d'un intrant passe de 50 000 à 900 000 ;
 *   – l'intrant se supprime.
 *
 * ─── POURQUOI CELA COMPTE ───
 *
 * Le compte de résultat sélectionne les cycles végétaux par leur DATE DE
 * CLÔTURE, puis lit leur `total_revenue` et la somme des `crop_inputs` qui leur
 * sont rattachés. Toucher à une ligne d'un cycle clos en juillet réécrit donc
 * le résultat de JUILLET — un mois déjà arrêté, peut-être déjà imprimé et
 * transmis au promoteur. Le rapport ne dit rien : il n'a aucun moyen de savoir
 * que le chiffre a bougé après coup.
 *
 * ─── L'ISSUE RESTE OUVERTE ───
 *
 * Rouvrir. Une clôture peut être une erreur, et une correction légitime doit
 * rester possible — mais par un geste qui SORT le cycle de la période close,
 * pas par une modification silencieuse à l'intérieur.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->parcelle = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle Sud',
        'area_ha' => 2, 'status' => 'libre',
    ]);
});

/** Un cycle clos le mois dernier, avec une récolte et un intrant. */
function cycleClosAvecLignes(int $farmId, int $plotId): CropCycle
{
    $cycle = CropCycle::create([
        'farm_id' => $farmId, 'plot_id' => $plotId, 'code' => 'CYC-LIGNES',
        'crop_name' => 'Maïs', 'planting_date' => now()->subMonths(4)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_TERMINE,
        'closing_date' => now()->subMonth()->toDateString(),
        'total_acquisition_cost' => 300_000, 'additional_costs' => 0,
    ]);

    Harvest::create([
        'farm_id' => $farmId, 'crop_cycle_id' => $cycle->id,
        'harvest_date' => now()->subMonths(2)->toDateString(),
        'quantity' => 100, 'unit' => 'kg', 'net_weight_kg' => 100,
        'unit_price' => 3_000,
    ]);

    CropInput::create([
        'farm_id' => $farmId, 'crop_cycle_id' => $cycle->id,
        'type' => array_key_first(CropInput::TYPES), 'name' => 'Engrais NPK',
        'quantity' => 10, 'unit' => 'kg', 'unit_cost' => 5_000, 'total_cost' => 50_000,
        'input_date' => now()->subMonths(3)->toDateString(),
    ]);

    return $cycle->fresh();
}

/** Le formulaire de modification d'une récolte, avec une quantité multipliée par dix. */
function modifierRecolte(CropCycle $cycle, Harvest $recolte)
{
    return test()->put(route('crop-cycles.harvests.update', [$cycle, $recolte]), [
        'harvest_date' => $recolte->harvest_date->toDateString(),
        'quantity' => 1_000,
        'unit' => 'kg',
        'net_weight_kg' => 1_000,
        'unit_price' => 3_000,
    ]);
}

/** Le formulaire de modification d'un intrant, avec un coût multiplié par dix-huit. */
function modifierIntrant(CropCycle $cycle, CropInput $intrant)
{
    return test()->put(route('crop-cycles.inputs.update', [$cycle, $intrant]), [
        'type' => $intrant->type,
        'name' => $intrant->name,
        'quantity' => 10,
        'unit' => 'kg',
        'unit_cost' => 90_000,
        'total_cost' => 900_000,
        'input_date' => $intrant->input_date->toDateString(),
    ]);
}

test('modifier une RÉCOLTE d’un cycle clôturé est refusé', function () {
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);
    $recolte = $cycle->harvests()->first();

    modifierRecolte($cycle, $recolte)->assertRedirect()->assertSessionHas('error');

    expect((float) $recolte->fresh()->quantity)->toBe(100.0);
});

test('supprimer une RÉCOLTE d’un cycle clôturé est refusé', function () {
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);
    $recolte = $cycle->harvests()->first();

    $this->delete(route('crop-cycles.harvests.destroy', [$cycle, $recolte]))
        ->assertRedirect()->assertSessionHas('error');

    expect(Harvest::withTrashed()->find($recolte->id)?->trashed())->toBeFalse();
});

test('modifier un INTRANT d’un cycle clôturé est refusé', function () {
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);
    $intrant = $cycle->inputs()->first();

    modifierIntrant($cycle, $intrant)->assertRedirect()->assertSessionHas('error');

    expect((float) $intrant->fresh()->total_cost)->toBe(50000.0);
});

test('supprimer un INTRANT d’un cycle clôturé est refusé', function () {
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);
    $intrant = $cycle->inputs()->first();

    $this->delete(route('crop-cycles.inputs.destroy', [$cycle, $intrant]))
        ->assertRedirect()->assertSessionHas('error');

    expect(CropInput::withTrashed()->find($intrant->id))->not->toBeNull();
});

test('le refus dit quoi faire', function () {
    // Un refus sans issue pousse à contourner — le même mot que sur le cycle.
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);

    modifierRecolte($cycle, $cycle->harvests()->first());

    expect(session('error'))->toContain('Rouvrez-le');
});

test('sur un cycle EN COURS, les quatre gestes restent possibles', function () {
    /*
     * On ferme la réécriture du passé, pas la saisie courante. Sans cette
     * mesure, la garde pourrait tout bloquer et les tests ci-dessus
     * passeraient quand même.
     */
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);
    $cycle->update(['status' => CropCycle::STATUS_EN_COURS, 'closing_date' => null]);

    $recolte = $cycle->harvests()->first();
    modifierRecolte($cycle, $recolte);
    expect((float) $recolte->fresh()->quantity)->toBe(1000.0);

    $intrant = $cycle->inputs()->first();
    modifierIntrant($cycle, $intrant);
    expect((float) $intrant->fresh()->total_cost)->toBe(900000.0);

    $this->delete(route('crop-cycles.harvests.destroy', [$cycle, $recolte]));
    expect(Harvest::find($recolte->id))->toBeNull();

    $this->delete(route('crop-cycles.inputs.destroy', [$cycle, $intrant]));
    expect(CropInput::find($intrant->id))->toBeNull();
});

test('le chiffre d’affaires d’un mois arrêté ne bouge plus', function () {
    /*
     * L'enjeu, mesuré de bout en bout sur le compte de résultat lui-même.
     * Avant : supprimer la récolte d'un cycle clos en juillet ramenait le
     * chiffre d'affaires de JUILLET à zéro, en une requête et sans un mot.
     */
    $cycle = cycleClosAvecLignes($this->farm->id, $this->parcelle->id);

    $params = [
        'date_from' => now()->subMonth()->startOfMonth()->toDateString(),
        'date_to' => now()->subMonth()->endOfMonth()->toDateString(),
    ];

    $avant = (float) $this->get(route('reports.profit_loss', $params))->viewData('totalRevenue');
    expect($avant)->toBeGreaterThan(0.0);

    $this->delete(route('crop-cycles.harvests.destroy', [$cycle, $cycle->harvests()->first()]));

    $apres = (float) $this->get(route('reports.profit_loss', $params))->viewData('totalRevenue');
    expect($apres)->toBe($avant);
});
