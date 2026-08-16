<?php

use App\Models\CropCycle;
use App\Models\Plot;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN CYCLE CLÔTURÉ POUVAIT ÊTRE ROUVERT ET RÉÉCRIT PAR LE FORMULAIRE ORDINAIRE.
 *
 * Trois faits mesurés sur le code d'avant, en une seule requête :
 *
 *   1. le statut passait de « terminé » à « en cours » — avec le simple droit M,
 *      alors que rouvrir un LOT d'élevage exige le droit S et une action dédiée ;
 *   2. la DATE DE CLÔTURE était conservée ;
 *   3. le coût d'acquisition passait de 500 000 à 5 000 000, sans un mot.
 *
 * ─── POURQUOI CELA COMPTE ───
 *
 * Le compte de résultat sélectionne les cycles végétaux par leur DATE DE
 * CLÔTURE. Modifier le coût d'un cycle clos en juillet réécrit donc le résultat
 * de JUILLET — un mois déjà arrêté, peut-être déjà imprimé et transmis. Et un
 * cycle rouvert qui garde sa date restait compté parmi les cycles clôturés tout
 * en continuant d'accumuler récoltes et intrants : une période arrêtée qui bouge
 * encore, indéfiniment.
 *
 * C'est le principe que cette base défend ailleurs explicitement — « supprimer
 * une source d'énergie ne doit pas RÉÉCRIRE le passé » — appliqué au seul
 * endroit qui l'ignorait.
 *
 * ─── CE QU'ON N'A PAS SUPPRIMÉ ───
 *
 * La possibilité de rouvrir. Une clôture peut être une erreur, et retirer le
 * geste sans le remplacer aurait laissé l'utilisateur sans recours. Il devient
 * une action DISTINCTE, réservée au droit S, qui EFFACE la date de clôture — un
 * cycle en cours n'en a pas, et c'est elle qui décide de son rattachement
 * comptable.
 *
 * ─── DEUX BARRIÈRES PLUTÔT QU'UNE ───
 *
 * Le rapport filtre désormais aussi sur le STATUT. Si un autre chemin venait un
 * jour à rouvrir un cycle sans effacer sa date, le compte de résultat resterait
 * juste.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->parcelle = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle Nord',
        'area_ha' => 3, 'status' => 'libre',
    ]);
});

/** Cycle clôturé le mois dernier, avec ses chiffres. */
function cycleCloture(int $farmId, int $plotId): CropCycle
{
    return CropCycle::create([
        'farm_id' => $farmId, 'plot_id' => $plotId, 'code' => 'CYC-CLOS',
        'crop_name' => 'Maïs', 'planting_date' => now()->subMonths(4)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_TERMINE,
        'closing_date' => now()->subMonth()->toDateString(),
        'total_acquisition_cost' => 500_000, 'additional_costs' => 0,
        'total_revenue' => 900_000,
    ]);
}

/** Soumet le formulaire de modification du cycle. */
function modifierCycle(CropCycle $cycle, array $remplacements = [])
{
    return test()->put(route('crop-cycles.update', $cycle), array_merge([
        'plot_id'                => $cycle->plot_id,
        'crop_name'              => 'Maïs',
        'planting_date'          => $cycle->planting_date->toDateString(),
        'area_used_ha'           => 1,
        'status'                 => CropCycle::STATUS_EN_COURS,
        'total_acquisition_cost' => 5_000_000,
        'additional_costs'       => 0,
    ], $remplacements));
}

test('modifier un cycle CLÔTURÉ par le formulaire est refusé', function () {
    // LE défaut : le coût passait de 500 000 à 5 000 000 en une requête.
    $cycle = cycleCloture($this->farm->id, $this->parcelle->id);

    modifierCycle($cycle)->assertRedirect()->assertSessionHas('error');

    expect((float) $cycle->fresh()->total_acquisition_cost)->toBe(500000.0)
        ->and($cycle->fresh()->status)->toBe(CropCycle::STATUS_TERMINE);
});

test('le refus dit quoi faire', function () {
    // Un refus sans issue pousse à contourner.
    $cycle = cycleCloture($this->farm->id, $this->parcelle->id);

    modifierCycle($cycle);

    expect(session('error'))->toContain('Rouvrez-le');
});

test('un cycle EN COURS reste modifiable', function () {
    // On ferme la réécriture du passé, pas la saisie courante.
    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $this->parcelle->id, 'code' => 'CYC-VIF',
        'crop_name' => 'Maïs', 'planting_date' => now()->subMonth()->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_EN_COURS,
        'total_acquisition_cost' => 100_000, 'additional_costs' => 0,
    ]);

    modifierCycle($cycle)->assertRedirect();

    expect((float) $cycle->fresh()->total_acquisition_cost)->toBe(5000000.0);
});

test('la RÉOUVERTURE efface la date de clôture', function () {
    /*
     * Le cœur du défaut : le cycle repartait « en cours » en gardant sa date,
     * donc restait compté parmi les cycles clôturés du compte de résultat.
     */
    $cycle = cycleCloture($this->farm->id, $this->parcelle->id);

    $this->put(route('crop-cycles.reopen', $cycle))->assertRedirect()->assertSessionHas('success');

    expect($cycle->fresh()->status)->toBe(CropCycle::STATUS_EN_COURS)
        ->and($cycle->fresh()->closing_date)->toBeNull();
});

test('la réouverture exige le droit S', function () {
    // Rouvrir un LOT d'élevage l'exige déjà ; le cycle passait par le simple
    // formulaire de modification, avec le droit M.
    $cycle = cycleCloture($this->farm->id, $this->parcelle->id);

    // La porte de droit de cette base REDIRIGE plutôt qu'elle ne renvoie 403 :
    // ce qui compte est que le cycle n'ait pas bougé.
    $this->actingAs($this->managerUser)
        ->put(route('crop-cycles.reopen', $cycle))
        ->assertRedirect();

    expect($cycle->fresh()->status)->toBe(CropCycle::STATUS_TERMINE);
});

test('rouvrir un cycle qui n’est PAS clôturé est refusé', function () {
    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $this->parcelle->id, 'code' => 'CYC-VIF2',
        'crop_name' => 'Maïs', 'planting_date' => now()->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_EN_COURS,
    ]);

    $this->put(route('crop-cycles.reopen', $cycle))->assertSessionHas('error');
});

test('un cycle rouvert QUITTE le compte de résultat de sa période', function () {
    /*
     * L'enjeu, mesuré de bout en bout. Avant : le cycle rouvert gardait sa date
     * de clôture et continuait de peser sur un mois arrêté.
     */
    $cycle = cycleCloture($this->farm->id, $this->parcelle->id);

    $debut = now()->subMonth()->startOfMonth()->toDateString();
    $fin   = now()->subMonth()->endOfMonth()->toDateString();

    $avant = $this->get(route('reports.profit_loss', ['date_from' => $debut, 'date_to' => $fin]))->assertOk();
    expect((float) $avant->viewData('totalRevenue'))->toBeGreaterThan(0.0);

    $this->put(route('crop-cycles.reopen', $cycle));

    $apres = $this->get(route('reports.profit_loss', ['date_from' => $debut, 'date_to' => $fin]))->assertOk();
    expect((float) $apres->viewData('totalRevenue'))->toBe(0.0);
});

test('le rapport ignore un cycle NON clôturé qui porterait encore une date', function () {
    /*
     * La seconde barrière. Si un autre chemin rouvrait un cycle sans effacer sa
     * date, le rapport resterait juste — une garde ne doit pas dépendre d'un
     * seul point d'écriture.
     */
    $cycle = cycleCloture($this->farm->id, $this->parcelle->id);
    $cycle->updateQuietly(['status' => CropCycle::STATUS_EN_COURS]); // date conservée

    $rapport = $this->get(route('reports.profit_loss', [
        'date_from' => now()->subMonth()->startOfMonth()->toDateString(),
        'date_to'   => now()->subMonth()->endOfMonth()->toDateString(),
    ]))->assertOk();

    expect((float) $rapport->viewData('totalRevenue'))->toBe(0.0);
});
