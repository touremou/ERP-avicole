<?php

use App\Actions\Batch\CreateBatch;
use App\Actions\Batch\UpdateBatch;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Provider;
use App\Services\Accounting\PeriodCharges;
use App\Support\MortsALaLivraison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES POUSSINS MORTS À LA LIVRAISON : LA RÈGLE DU CONTRAT FOURNISSEUR.
 *
 * Décision de l'exploitation, trois règles au choix du contrat (fiche
 * fournisseur) et figées sur le lot à sa mise en place :
 *   A. avoir fournisseur — le lot ne paie que ses vivants (par défaut) ;
 *   B. facture entière — les vivants absorbent le prix des morts ;
 *   C. perte au transport — le prix des morts va à une charge dédiée.
 *
 * Cas mesuré : 290 vivants, 10 morts à la livraison, 500 GNF le poussin.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->poulailler = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-DOA', 'type' => 'chair',
        'status' => Building::STATUS_VIDE, 'capacity' => 1000,
    ]);
});

function couvoirSous(string $politique, int $ferme): Provider
{
    return Provider::create([
        'farm_id' => $ferme, 'name' => 'Couvoir ' . $politique, 'type' => 'Poussins',
        'phone' => '600000000', 'status' => 'Actif', 'reliability' => 'Bon', 'doa_policy' => $politique,
    ]);
}

function lotLivrePar(?Provider $couvoir, int $batiment): Batch
{
    return app(CreateBatch::class)->execute([
        'code' => 'DOA-' . uniqid(), 'type' => 'chair', 'building_id' => $batiment,
        'provider_id' => $couvoir?->id, 'qty_alive' => 290, 'qty_dead' => 10,
        'buy_price_per_unit' => 500, 'arrival_date' => now()->toDateString(),
    ]);
}

test('A — avoir fournisseur : le lot paie ses 290 vivants', function () {
    $lot = lotLivrePar(couvoirSous(MortsALaLivraison::AVOIR, $this->farm->id), $this->poulailler->id);

    expect((float) $lot->total_acquisition_cost)->toEqual(145000.0)
        ->and((float) $lot->transport_loss_cost)->toEqual(0.0)
        ->and($lot->doa_policy)->toBe('avoir');
});

test('B — facture entière : les vivants absorbent le prix des morts', function () {
    $lot = lotLivrePar(couvoirSous(MortsALaLivraison::REPARTITION, $this->farm->id), $this->poulailler->id);

    // 300 × 500 = 150 000, porté par 290 vivants : 517,24 le sujet.
    expect((float) $lot->total_acquisition_cost)->toEqual(150000.0)
        ->and(round($lot->coutDeRevientParSujet(), 2))->toEqual(round(150000 / 290, 2));
});

test('C — perte au transport : charge dédiée, hors du coût du lot', function () {
    $lot = lotLivrePar(couvoirSous(MortsALaLivraison::PERTE, $this->farm->id), $this->poulailler->id);

    expect((float) $lot->total_acquisition_cost)->toEqual(145000.0)
        ->and((float) $lot->transport_loss_cost)->toEqual(5000.0);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());
    expect($charges['Pertes au transport'])->toEqual(5000.0)
        ->and($charges['Achats animaux (lots)'])->toEqual(145000.0);
});

test('sans fournisseur ni contrat, la règle A s’applique — non-régression', function () {
    $lot = lotLivrePar(null, $this->poulailler->id);

    expect((float) $lot->total_acquisition_cost)->toEqual(145000.0)
        ->and($lot->doa_policy)->toBe('avoir');
});

test('un prix corrigé suit la règle FIGÉE sur le lot, même si le contrat change ensuite', function () {
    $couvoir = couvoirSous(MortsALaLivraison::REPARTITION, $this->farm->id);
    $lot = lotLivrePar($couvoir, $this->poulailler->id);
    $couvoir->update(['doa_policy' => MortsALaLivraison::AVOIR]);

    $lot = app(UpdateBatch::class)->execute($lot, ['buy_price_per_unit' => 600]);

    // Toujours B : 300 × 600.
    expect((float) $lot->total_acquisition_cost)->toEqual(180000.0);
});

test('la fiche fournisseur enregistre la règle du contrat', function () {
    $this->post(route('providers.store'), [
        'name' => 'Couvoir du Sud', 'phone' => '622000000', 'type' => 'Poussins',
        'doa_policy' => MortsALaLivraison::PERTE,
    ])->assertSessionHasNoErrors();

    expect(Provider::where('name', 'Couvoir du Sud')->value('doa_policy'))->toBe('perte');
});

test('C — la marge par espèce porte la perte au transport, comme le compte de résultat', function () {
    $lot = lotLivrePar(couvoirSous(MortsALaLivraison::PERTE, $this->farm->id), $this->poulailler->id);

    $especes = $this->get(route('reports.profit_loss'))->assertOk()->viewData('speciesMargin');
    $ligne = collect($especes)->firstWhere('species', $lot->fresh()->species->name_fr);

    // 145 000 d'achat des vivants + 5 000 de morts en route.
    expect($ligne['cost'])->toEqual(150000.0);
});
