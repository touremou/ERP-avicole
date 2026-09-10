<?php

use App\Models\Batch;
use App\Models\MilkProduction;
use App\Models\ProductionType;
use App\Models\Species;
use App\Models\Stock;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE LAIT TRAIT AU CHAMP N'ARRIVAIT JAMAIS AU MAGASIN.
 *
 * Deux règles de l'écran du bureau manquaient au chemin de la synchro, sur la
 * MÊME opération — et la traite est précisément le geste qu'on saisit au
 * troupeau, pas au bureau.
 *
 * ─── 1. LE LAIT N'ENTRAIT PAS EN STOCK ───
 *
 * `MilkProductionController` crédite le magasin « Lait » à chaque collecte, par
 * une méthode PRIVÉE. `SyncService::milkProductionCreate` enregistrait la
 * production et s'arrêtait là.
 *
 * Mesuré, sur la même collecte de 70 litres à 8 000 GNF :
 *
 *   • saisie au BUREAU        : 70 L au magasin, à 8 000 le litre ;
 *   • poussée par le TERRAIN  : production enregistrée, magasin VIDE.
 *
 * Le lait du champ n'existait donc que comme statistique de production :
 * invendable, absent de la valeur d'inventaire, et invisible du magasinier qui
 * a pourtant les bidons devant lui.
 *
 * ─── 2. N'IMPORTE QUEL LOT POUVAIT ÊTRE TRAIT ───
 *
 * L'écran refuse « Le lot X n'est pas un lot laitier », en lisant
 * `Batch::tracksMilk()`. La synchro ne posait pas la question : un lot de
 * POULETS DE CHAIR acceptait une traite, et la production laitière du troupeau
 * s'en trouvait faussée. C'est d'ailleurs ce qui est arrivé pendant la mesure —
 * le bureau a refusé le lot que le terrain venait d'accepter.
 *
 * ─── LA RÈGLE ───
 *
 * L'entrée au magasin vit désormais dans `App\Actions\Milk\SyncMilkStock`, que
 * les DEUX chemins appellent ; et la question « ce lot donne-t-il du lait ? »
 * se pose des deux côtés, sur la déclaration unique du modèle.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $vache = Species::where('slug', 'vache')->firstOrFail();

    $this->lotLaitier = Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => $this->building->id,
        'status'             => 'Actif',
        'initial_quantity'   => 50,
        'current_quantity'   => 50,
        'species_id'         => $vache->id,
        'production_type_id' => ProductionType::where('species_id', $vache->id)
            ->where('slug', 'laitiere')->value('id'),
    ]);
});

/** L'article « Lait » du magasin, s'il existe. */
function articleLait(): ?Stock
{
    return Stock::withoutGlobalScopes()->where('item_name', 'Lait')->first();
}

/** Pousse une traite par la file de synchro du terrain. */
function pousserTraite(int $batchId, float $matin, float $soir, ?float $prix = 8_000, ?string $date = null): array
{
    return app(SyncService::class)->handle('milk_production.create', [
        'uuid'            => (string) Str::uuid(),
        'batch_id'        => $batchId,
        'production_date' => $date ?? today()->toDateString(),
        'morning_liters'  => $matin,
        'evening_liters'  => $soir,
        'unit_price'      => $prix,
    ]);
}

test('une traite poussée par le TERRAIN entre au magasin', function () {
    /*
     * LE défaut : 70 litres enregistrés en production, zéro au magasin.
     */
    expect(articleLait())->toBeNull();

    $resultat = pousserTraite($this->lotLaitier->id, matin: 40, soir: 30);

    expect($resultat['status'])->toBe('success')
        ->and((float) MilkProduction::withoutGlobalScopes()->sum('total_liters'))->toBe(70.0)
        ->and((float) articleLait()->current_quantity)->toBe(70.0);
});

test('le lait du terrain porte le prix de la collecte', function () {
    /*
     * Sans prix, les litres seraient au magasin mais l'inventaire les
     * compterait pour rien — le défaut qu'on vient de corriger ailleurs sur la
     * viande et les poussins.
     */
    pousserTraite($this->lotLaitier->id, matin: 40, soir: 30, prix: 8_000);

    expect((float) articleLait()->last_unit_price)->toBe(8_000.0);
});

test('le TERRAIN et le BUREAU créditent le même magasin', function () {
    /*
     * L'égalité entre les deux portes : deux collectes identiques, l'une par
     * chaque chemin, doivent s'additionner dans le même article.
     */
    pousserTraite($this->lotLaitier->id, matin: 40, soir: 30);

    $this->post(route('milk-productions.store'), [
        'batch_id'        => $this->lotLaitier->id,
        'production_date' => today()->subDay()->toDateString(),
        'morning_liters'  => 40,
        'evening_liters'  => 30,
        'unit_price'      => 8_000,
    ])->assertSessionHas('success');

    expect((float) articleLait()->current_quantity)->toBe(140.0);
});

test('un lot NON LAITIER est refusé au terrain comme au bureau', function () {
    /*
     * La seconde règle. Un lot de poulets de chair acceptait une traite depuis
     * le terrain, pendant que le bureau la refusait — et c'est ce qui s'est
     * produit pendant la mesure.
     */
    $poulets = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'initial_quantity' => 500,
        'current_quantity' => 500,
    ]);

    expect($poulets->tracksMilk())->toBeFalse();

    $resultat = pousserTraite($poulets->id, matin: 40, soir: 30);

    expect($resultat['status'])->toBe('validation_failed')
        ->and(MilkProduction::withoutGlobalScopes()->count())->toBe(0)
        ->and(articleLait())->toBeNull();
});

test('rejouer la même traite n’ajoute pas de lait — non-régression', function () {
    /*
     * L'idempotence par uuid. Elle protégeait déjà la production ; elle doit
     * désormais protéger aussi le magasin, sinon un rejeu doublerait le stock.
     */
    $payload = [
        'uuid'            => (string) Str::uuid(),
        'batch_id'        => $this->lotLaitier->id,
        'production_date' => today()->toDateString(),
        'morning_liters'  => 40,
        'evening_liters'  => 30,
        'unit_price'      => 8_000,
    ];

    app(SyncService::class)->handle('milk_production.create', $payload);
    $second = app(SyncService::class)->handle('milk_production.create', $payload);

    expect($second['status'])->toBe('already_synced')
        ->and((float) articleLait()->current_quantity)->toBe(70.0);
});

test('la saisie au BUREAU reste inchangée — non-régression', function () {
    // On a déplacé son calcul dans une Action partagée : il doit se comporter
    // exactement comme avant.
    $this->post(route('milk-productions.store'), [
        'batch_id'        => $this->lotLaitier->id,
        'production_date' => today()->toDateString(),
        'morning_liters'  => 25,
        'evening_liters'  => 15,
        'unit_price'      => 9_000,
    ])->assertSessionHas('success');

    expect((float) articleLait()->current_quantity)->toBe(40.0)
        ->and((float) articleLait()->last_unit_price)->toBe(9_000.0);
});
