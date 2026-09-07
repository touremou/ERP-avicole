<?php

use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DES POUSSINS ENTRAIENT AU MAGASIN À VALEUR NULLE.
 *
 * Les quatre destinations d'un dispatch d'éclosion partagent UNE déclaration du
 * coût d'un poussin : `Incubation::chickUnitCost()` — œufs mis à couver + frais
 * d'incubation, divisés par les poussins réellement éclos.
 *
 * La branche ÉLEVAGE l'applique : elle en fait le `buy_price_per_unit` et le
 * `total_acquisition_cost` du lot de poussinière, « pour que le lot hérite d'un
 * vrai coût d'acquisition ». La branche STOCK ne l'appelait jamais.
 *
 * Mesuré, sur un cycle de 1 000 œufs à 500 GNF plus 8 000 GNF de frais, éclos à
 * 800 poussins — soit 635 GNF le poussin :
 *
 *   • 300 poussins mis en stock valent 190 500 GNF ;
 *   • l'article sortait à `last_unit_price` ZÉRO, donc 0 GNF d'inventaire.
 *
 * ─── CE QUE ÇA VIDE ───
 *
 * Deux lecteurs valorisent le stock : `Stock::total_value` et la ventilation du
 * tableau de bord. Cette dernière écarte les catégories à zéro
 * (`filter(v > 0)`) : les poussins ne figuraient donc pas à un montant faux, ils
 * DISPARAISSAIENT de l'inventaire valorisé. Le même geste, dirigé vers
 * l'élevage, portait son coût correctement — c'est la destination qui décidait
 * si le poussin valait quelque chose.
 *
 * ─── POURQUOI PASSER PAR LE SERVICE ───
 *
 * Ce bloc tenait le stock à la main — firstOrCreate, increment,
 * StockMovement::create — au lieu d'appeler `StockIntegrationService`, qui sait
 * tenir le COÛT MOYEN PONDÉRÉ via son argument `$unitCost`. Recopier ce calcul
 * ici en aurait fait une seconde déclaration ; on délègue.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);

    $this->couveuse = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse A',
        'capacity' => 10_000, 'status' => 'Disponible',
    ]);
});

/**
 * Un cycle CLOS prêt à dispatcher : $oeufs à $prixOeuf, $frais de frais,
 * $eclos poussins.
 */
function cycleEclosPretADispatcher(
    int $farmId, int $lotId, int $couveuseId,
    int $oeufs, float $prixOeuf, float $frais, int $eclos, string $code = 'INC-T-0001',
): Incubation {
    return Incubation::create([
        'farm_id'             => $farmId,
        'batch_id'            => $lotId,
        'incubator_id'        => $couveuseId,
        'code_incubation'     => $code,
        'start_date'          => now()->subDays(21)->toDateString(),
        'incubation_duration' => 21,
        'hatch_date_expected' => today()->toDateString(),
        'eggs_count'          => $oeufs,
        'egg_unit_cost'       => $prixOeuf,
        'overhead_cost'       => $frais,
        'fertile_eggs'        => (int) round($oeufs * 0.9),
        'hatched_chicks'      => $eclos,
        'status'              => 'clos',
        'finished_at'         => now(),
        'source_type'         => 'internal',
        'egg_grade'           => 'L',
        'chicks_dispatched'   => 0,
        'chicks_remaining'    => $eclos,
    ]);
}

/** L'article de stock des poussins d'un jour, toutes fermes confondues. */
function articlePoussins(): ?Stock
{
    return Stock::withoutGlobalScopes()
        ->where('item_name', "Poussins d'un jour")
        ->first();
}

test('les poussins mis en STOCK portent leur coût de revient', function () {
    /*
     * LE défaut : 300 poussins à 635 GNF valaient 0 GNF à l'inventaire.
     */
    $cycle = cycleEclosPretADispatcher(
        $this->farm->id, $this->lot->id, $this->couveuse->id,
        oeufs: 1000, prixOeuf: 500, frais: 8000, eclos: 800,
    );

    expect($cycle->chickUnitCost())->toBe(635.0);

    $this->post(route('chick-dispatches.store', $cycle->id), [
        'destination_type' => 'stock', 'quantity' => 300, 'quality_grade' => 'A',
    ])->assertSessionHasNoErrors();

    $article = articlePoussins();

    expect((float) $article->current_quantity)->toBe(300.0)
        ->and((float) $article->last_unit_price)->toBe(635.0)
        ->and($article->total_value)->toBe(190_500.0);
});

test('deux éclosions de coûts différents se mélangent au COÛT MOYEN PONDÉRÉ', function () {
    /*
     * LA raison de déléguer au service plutôt que de poser le prix à la main :
     * 300 poussins à 635 puis 100 à 1 035 ne valent pas 1 035 pièce, mais
     * (300 × 635 + 100 × 1 035) ÷ 400 = 735.
     */
    $premier = cycleEclosPretADispatcher(
        $this->farm->id, $this->lot->id, $this->couveuse->id,
        oeufs: 1000, prixOeuf: 500, frais: 8000, eclos: 800, code: 'INC-T-0001',
    );

    $this->post(route('chick-dispatches.store', $premier->id), [
        'destination_type' => 'stock', 'quantity' => 300, 'quality_grade' => 'A',
    ])->assertSessionHasNoErrors();

    // 1 000 œufs à 1 000 GNF + 3 000 de frais, 1 000 éclos → 1 003 GNF pièce.
    $second = cycleEclosPretADispatcher(
        $this->farm->id, $this->lot->id, $this->couveuse->id,
        oeufs: 1000, prixOeuf: 1000, frais: 3000, eclos: 1000, code: 'INC-T-0002',
    );

    expect($second->chickUnitCost())->toBe(1003.0);

    $this->post(route('chick-dispatches.store', $second->id), [
        'destination_type' => 'stock', 'quantity' => 100, 'quality_grade' => 'A',
    ])->assertSessionHasNoErrors();

    $article = articlePoussins();

    // (300 × 635 + 100 × 1 003) ÷ 400 = 727
    expect((float) $article->current_quantity)->toBe(400.0)
        ->and((float) $article->last_unit_price)->toBe(727.0);
});

test('la mise en stock incrémente bien la quantité — non-régression', function () {
    // Le geste d'origine ne doit pas avoir été perdu en déléguant au service.
    $cycle = cycleEclosPretADispatcher(
        $this->farm->id, $this->lot->id, $this->couveuse->id,
        oeufs: 1000, prixOeuf: 500, frais: 8000, eclos: 800,
    );

    $this->post(route('chick-dispatches.store', $cycle->id), [
        'destination_type' => 'stock', 'quantity' => 200, 'quality_grade' => 'A',
    ]);
    $this->post(route('chick-dispatches.store', $cycle->fresh()->id), [
        'destination_type' => 'stock', 'quantity' => 100, 'quality_grade' => 'A',
    ]);

    expect((float) articlePoussins()->current_quantity)->toBe(300.0)
        ->and((int) $cycle->fresh()->chicks_dispatched)->toBe(300);
});

test('un cycle SANS poussin éclos ne fabrique pas un coût — non-régression', function () {
    /*
     * LA borne du calcul : `chickUnitCost()` rend 0 tant qu'aucun poussin n'est
     * éclos, pour ne pas diviser par zéro. Il n'y a alors rien à dispatcher non
     * plus — le contrôle de quantité doit refuser.
     */
    $cycle = cycleEclosPretADispatcher(
        $this->farm->id, $this->lot->id, $this->couveuse->id,
        oeufs: 1000, prixOeuf: 500, frais: 8000, eclos: 0,
    );

    expect($cycle->chickUnitCost())->toBe(0.0);

    $this->post(route('chick-dispatches.store', $cycle->id), [
        'destination_type' => 'stock', 'quantity' => 10, 'quality_grade' => 'A',
    ])->assertSessionHas('error');

    expect(articlePoussins())->toBeNull();
});

test('le dispatch vers l’ÉLEVAGE porte le même coût — non-régression', function () {
    /*
     * L'étalon : c'est cette branche-là qui avait raison, et c'est son écart
     * avec la branche stock qui désignait la fautive. Elle ne doit pas bouger.
     */
    $cycle = cycleEclosPretADispatcher(
        $this->farm->id, $this->lot->id, $this->couveuse->id,
        oeufs: 1000, prixOeuf: 500, frais: 8000, eclos: 800,
    );

    $this->post(route('chick-dispatches.store', $cycle->id), [
        'destination_type' => 'elevage', 'quantity' => 300,
        'quality_grade' => 'A', 'building_id' => $this->building->id,
    ])->assertSessionHasNoErrors();

    $poussiniere = Batch::where('code', 'like', 'POUS-%')->latest('id')->firstOrFail();

    expect((float) $poussiniere->buy_price_per_unit)->toBe(635.0)
        ->and((float) $poussiniere->total_acquisition_cost)->toBe(190_500.0);
});
