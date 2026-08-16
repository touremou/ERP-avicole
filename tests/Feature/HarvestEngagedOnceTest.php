<?php

use App\Models\CropCycle;
use App\Models\CropTransformation;
use App\Models\Harvest;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA LISTE CONNAISSAIT LA RÈGLE QUE L'ENREGISTREMENT IGNORAIT.
 *
 * « Une récolte déjà transformée ne se re-transforme pas : ce serait engager
 * deux fois la même matière (et la compter deux fois en coût). » La synchro
 * mobile le dit, en toutes lettres, et le refuse.
 *
 * Le web ne le refusait pas. Il se contentait de ne pas proposer la récolte
 * dans la liste du formulaire (`whereDoesntHave('transformations')`) — une
 * garde d'AFFICHAGE. Rien ne vérifiait le `harvest_id` reçu.
 *
 * Or un formulaire d'atelier reste ouvert, un envoi se double sur une
 * connexion lente, un retour arrière re-soumet. C'est précisément le geste
 * qu'une liste filtrée ne voit pas passer.
 *
 * ─── MESURÉ SUR LE CODE D'AVANT ───
 *
 * Deux envois du même formulaire, sur une récolte de 200 kg :
 *
 *   • matière engagée .......... 400 kg  (pour 200 kg récoltés)
 *   • coût matière imputé ...... 800 000 GNF  (le cycle a coûté 400 000)
 *   • produit fini sorti ....... 120 kg  (il y en a 60)
 *
 * Le coût de revient du cycle et le stock de l'atelier deviennent faux
 * ENSEMBLE, et rien ne le signale : les deux lots portent des numéros
 * différents et paraissent légitimes.
 *
 * ─── UNE SEULE DÉCLARATION ───
 *
 * La condition était recopiée à trois endroits qui LISENT (liste de synchro,
 * liste du formulaire, garde de la synchro) et absente du seul qui ÉCRIT. Elle
 * vit maintenant sur le modèle — `scopeNotEngaged()` pour les listes,
 * `isEngaged()` pour la question posée d'une récolte précise — et les quatre
 * s'y réfèrent.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $plot = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle Atelier',
        'area_ha' => 1, 'status' => 'libre',
    ]);

    $this->cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'CYC-ATL',
        'crop_name' => 'Manioc', 'planting_date' => now()->subMonths(5)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_RECOLTE,
        'total_acquisition_cost' => 400_000, 'additional_costs' => 0,
    ]);

    $this->recolte = Harvest::create([
        'farm_id' => $this->farm->id, 'crop_cycle_id' => $this->cycle->id,
        'harvest_date' => now()->subDays(5)->toDateString(),
        'quantity' => 200, 'unit' => 'kg', 'net_weight_kg' => 200,
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);
});

/** Le formulaire d'atelier : 200 kg de manioc → 60 kg de gari. */
function transformer(?int $harvestId, array $remplacements = [])
{
    return test()->post(route('crop-transformations.store'), array_merge([
        'harvest_id' => $harvestId,
        'input_product' => 'Manioc frais',
        'output_product' => 'Gari',
        'transformation_type' => array_key_first(CropTransformation::TYPES),
        'input_quantity' => 200,
        'input_unit' => 'kg',
        'output_quantity' => 60,
        'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'synced_to_stock' => 1,
        'output_stock_item' => 'Gari',
    ], $remplacements));
}

test('la première transformation passe', function () {
    // On ferme le doublon, pas l'atelier.
    transformer($this->recolte->id)->assertRedirect();

    expect(CropTransformation::count())->toBe(1);
});

test('la SECONDE transformation de la même récolte est refusée', function () {
    // LE défaut, dans le geste exact qui le produit : deux envois.
    transformer($this->recolte->id);
    transformer($this->recolte->id)->assertRedirect()->assertSessionHas('error');

    expect(CropTransformation::count())->toBe(1);
});

test('la matière engagée ne dépasse pas la récolte', function () {
    // 400 kg engagés pour 200 kg récoltés : le chiffre qui rend le défaut réel.
    transformer($this->recolte->id);
    transformer($this->recolte->id);

    expect((float) CropTransformation::sum('input_quantity'))
        ->toBeLessThanOrEqual((float) $this->recolte->net_weight_kg);
});

test('le coût matière n’est imputé qu’une fois au cycle', function () {
    /*
     * Avant : 800 000 GNF de matière consommée sur un cycle qui n'a coûté que
     * 400 000. Le coût de revient au kilo — donc le prix de vente qui s'en
     * déduit — devenait faux.
     */
    transformer($this->recolte->id);
    $apresUn = (float) CropTransformation::sum('input_cost');

    transformer($this->recolte->id);

    expect((float) CropTransformation::sum('input_cost'))->toBe($apresUn)
        ->and($apresUn)->toBeGreaterThan(0.0);
});

test('le refus nomme la récolte concernée', function () {
    // L'opérateur d'atelier doit savoir DE QUOI on parle : il en manipule
    // plusieurs dans la journée.
    transformer($this->recolte->id);
    transformer($this->recolte->id);

    expect(session('error'))->toContain($this->recolte->harvest_date->format('d/m/Y'));
});

test('une transformation SANS récolte rattachée reste possible', function () {
    /*
     * Le rattachement est facultatif : on transforme aussi de la matière
     * achetée ou reprise du stock. La garde ne doit pas bloquer ce cas — sinon
     * les tests ci-dessus passeraient pour la mauvaise raison.
     */
    transformer(null)->assertRedirect();
    transformer(null)->assertRedirect();

    expect(CropTransformation::count())->toBe(2);
});

test('une AUTRE récolte du même cycle reste transformable', function () {
    // La règle porte sur la récolte, pas sur le cycle.
    transformer($this->recolte->id);

    $autre = Harvest::create([
        'farm_id' => $this->farm->id, 'crop_cycle_id' => $this->cycle->id,
        'harvest_date' => now()->subDays(2)->toDateString(),
        'quantity' => 80, 'unit' => 'kg', 'net_weight_kg' => 80,
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    transformer($autre->id, ['input_quantity' => 80, 'output_quantity' => 25])->assertRedirect();

    expect(CropTransformation::count())->toBe(2);
});

test('la liste du formulaire et la garde disent la même chose', function () {
    /*
     * La divergence de départ. Une récolte engagée disparaît de la liste ET
     * est refusée à l'enregistrement — les deux lisent désormais la même
     * déclaration (`notEngaged` / `isEngaged`).
     */
    transformer($this->recolte->id);

    $proposees = $this->get(route('crop-transformations.create'))->viewData('pendingHarvests');

    expect($proposees->pluck('id'))->not->toContain($this->recolte->id)
        ->and($this->recolte->fresh()->isEngaged())->toBeTrue();
});
