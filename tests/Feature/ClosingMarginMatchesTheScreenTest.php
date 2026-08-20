<?php

use App\Actions\Batch\CloseBatch;
use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\EnergyReading;
use App\Models\FeedPurchase;
use App\Models\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MARGE LUE À L'ÉCRAN N'ÉTAIT PAS CELLE QUE LE SYSTÈME ENREGISTRAIT.
 *
 * L'écran de clôture est un simulateur : on y fait varier le prix de vente en
 * regardant la marge par sujet, puis on valide. C'est là que se décide le prix
 * d'un lot entier.
 *
 * Or les deux côtés ne comptaient pas la même chose :
 *
 *   • L'ÉCRAN valorisait la consommation à la MOYENNE des prix des articles
 *     d'aliment du secteur — et, si aucun n'avait de prix, à 5 000 GNF/kg EN DUR.
 *     Une constante inventée s'affichait comme un coût constaté ;
 *   • L'ENREGISTREMENT, lui, retenait la somme des ACHATS rattachés au lot :
 *     un sac livré la veille et encore au silo lui était imputé en entier ;
 *   • L'ÉNERGIE était affichée par l'écran (moyenne de TOUTE l'exploitation ×
 *     durée ÷ nombre de lots actifs) et purement ignorée à l'enregistrement.
 *
 * Trois façons de chiffrer le même lot, dont aucune n'était celle de sa propre
 * fiche — qui utilise, elle, `Batch::feed_cogs` : la consommation valorisée au
 * coût figé le jour du pointage.
 *
 * ─── LA RÈGLE RETENUE, ET POURQUOI ───
 *
 * Un coût de revient ne connaît que ce qui a été CONSOMMÉ. L'aliment acheté et
 * non mangé est un stock, pas une charge du lot : il nourrira le suivant.
 *
 * `feed_cogs` et `utility_cost` existaient déjà, documentés, et servaient déjà
 * la fiche du lot et la comptabilité de période. On ne crée donc aucune règle :
 * on supprime deux recalculs qui divergeaient d'elle.
 *
 * ─── CE QU'ON NE CORRIGE PAS EN SILENCE ───
 *
 * Si rien n'a été pointé alors que de l'aliment a été acheté pour le lot, la
 * marge est flattée d'autant. Une consommation non saisie n'est pas une
 * consommation : on ne la devine pas, on l'AFFICHE, avant que la clôture ne fige
 * le chiffre.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un lot prêt à clôturer, avec un aliment pointé à un coût figé connu. */
function lotAvecConsommation(int $farmId, int $buildingId, float $kg, float $coutKg): Batch
{
    $lot = Batch::factory()->create([
        'farm_id'                => $farmId,
        'building_id'            => $buildingId,
        'arrival_date'           => today()->subDays(40)->toDateString(),
        'birth_date'             => today()->subDays(40)->toDateString(),
        'initial_quantity'       => 500,
        'current_quantity'       => 480,
        'total_acquisition_cost' => 1_000_000,
        'status'                 => 'Actif',
    ]);

    DailyCheck::create([
        'farm_id'        => $farmId,
        'batch_id'       => $lot->id,
        'check_date'     => today()->subDays(10)->toDateString(),
        'mortality'      => 0,
        'feed_consumed'  => $kg,
        'feed_unit_cost' => $coutKg,
        'feed_type'      => 'Ponte',
        'user_id'        => auth()->id(),
    ]);

    return $lot->fresh();
}

/** Un relevé d'énergie facturé, taggé sur le bâtiment du lot. */
function releveEnergie(int $farmId, int $buildingId, float $cout, int $userId): void
{
    $source = \App\Models\EnergySource::create([
        'farm_id' => $farmId,
        'name'    => 'Groupe Perkins 100kVA',
        'type'    => 'groupe',
        'status'  => 'operationnel',
    ]);

    EnergyReading::create([
        'farm_id'          => $farmId,
        'energy_source_id' => $source->id,
        'building_id'      => $buildingId,
        'reading_date'     => today()->subDays(5)->toDateString(),
        'cost'             => $cout,
        'user_id'          => $userId,
    ]);
}

test('l’aliment est compté au coût FIGÉ du pointage, pas à une moyenne', function () {
    /*
     * Le cœur : 200 kg pointés à 4 000 GNF font 800 000, quel que soit le prix
     * courant des articles en magasin.
     */
    $lot = lotAvecConsommation($this->farm->id, $this->building->id, 200, 4000);

    expect((float) $lot->feed_cogs)->toBe(800_000.0);
});

test('l’ÉCRAN de clôture affiche ce coût-là', function () {
    $lot = lotAvecConsommation($this->farm->id, $this->building->id, 200, 4000);

    $vue = $this->get(route('batches.close_form', $lot))->assertOk();

    expect($vue->viewData('costs')['feed'])->toBe(800_000.0);
});

test('l’ÉCRAN et la MARGE ENREGISTRÉE comptent la même chose', function () {
    /*
     * LA borne de cette correction. On lit le total des coûts connus à l'écran,
     * on clôture, et on vérifie que la marge enregistrée s'en déduit exactement.
     */
    $lot = lotAvecConsommation($this->farm->id, $this->building->id, 200, 4000);

    HealthCheck::create([
        'farm_id'             => $this->farm->id,
        'batch_id'            => $lot->id,
        'type'                => 'Vaccin',
        'product_name'        => 'Newcastle',
        'mode_administration' => 'Eau de boisson',
        'intervention_date'   => today()->subDays(20)->toDateString(),
        'cost'                => 150_000,
        'user_id'             => $this->adminUser->id,
    ]);

    // Eau/énergie facturée : l'écran l'affichait déjà, l'enregistrement
    // l'ignorait. Sans elle dans ce scénario, l'égalité ne prouverait rien.
    releveEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    $coutsEcran = $this->get(route('batches.close_form', $lot))->viewData('costs');

    expect($coutsEcran['energy'])->toBeGreaterThan(0);

    $prixVente = 25_000;
    app(CloseBatch::class)->execute($lot, [
        'closing_date'          => today()->toDateString(),
        'actual_sell_price_per_unit' => $prixVente,
        'additional_costs'      => 0,
    ]);

    $lot = $lot->fresh();

    // Marge = recettes − (coûts connus affichés + frais annexes saisis).
    $margeAttendue = (float) $lot->total_revenue - (float) $coutsEcran['total_known'];

    expect((float) $lot->margin)->toBe($margeAttendue);
});

test('un sac ACHETÉ mais pas encore mangé ne charge pas le lot', function () {
    /*
     * L'achat de la veille, encore au silo. Il nourrira le lot suivant : le
     * compter ici amputait la marge d'un coût qui n'est pas de ce lot.
     */
    $lot = lotAvecConsommation($this->farm->id, $this->building->id, 200, 4000);

    FeedPurchase::create([
        'farm_id'       => $this->farm->id,
        'batch_id'      => $lot->id,
        'purchase_date' => today()->subDay()->toDateString(),
        'feed_type'     => 'Ponte',
        'quantity'      => 500,
        'unit_price'    => 4000,
        'total_price'   => 2_000_000,
        'user_id'       => $this->adminUser->id,
    ]);

    $couts = $this->get(route('batches.close_form', $lot))->viewData('costs');

    // 800 000 de consommation, et pas 2 800 000.
    expect($couts['feed'])->toBe(800_000.0);
});

test('un aliment ACHETÉ mais JAMAIS pointé est signalé, pas deviné', function () {
    /*
     * L'autre bord de la même règle. Sans pointage, le coût de revient est
     * silencieusement nul et la marge flattée : on ne corrige pas le chiffre,
     * on prévient avant que la clôture ne le fige.
     */
    $lot = Batch::factory()->create([
        'farm_id'                => $this->farm->id,
        'building_id'            => $this->building->id,
        'arrival_date'           => today()->subDays(40)->toDateString(),
        'initial_quantity'       => 500,
        'current_quantity'       => 480,
        'total_acquisition_cost' => 1_000_000,
        'status'                 => 'Actif',
    ]);

    FeedPurchase::create([
        'farm_id'       => $this->farm->id,
        'batch_id'      => $lot->id,
        'purchase_date' => today()->subDays(5)->toDateString(),
        'feed_type'     => 'Ponte',
        'quantity'      => 500,
        'unit_price'    => 4000,
        'total_price'   => 2_000_000,
        'user_id'       => $this->adminUser->id,
    ]);

    $reponse = $this->get(route('batches.close_form', $lot))->assertOk();

    expect($reponse->viewData('costs')['feed_unlogged'])->toBe(2_000_000.0);

    // Et l'avertissement doit être À L'ÉCRAN, pas seulement dans les données.
    expect(str_contains($reponse->getContent(), 'Aucune consommation pointée'))
        ->toBeTrue('La clôture doit annoncer l’aliment acheté et non pointé.');
});

test('AUCUN prix d’aliment inventé quand rien n’est connu', function () {
    /*
     * L'écran repliait sur 5 000 GNF/kg EN DUR. Un lot sans coût connu affichait
     * donc une charge d'alimentation d'apparence constatée, calculée sur une
     * constante écrite dans le code.
     */
    $lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'arrival_date'     => today()->subDays(40)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 480,
        'status'           => 'Actif',
    ]);

    DailyCheck::create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $lot->id,
        'check_date'     => today()->subDays(10)->toDateString(),
        'mortality'      => 0,
        'feed_consumed'  => 100,
        'feed_unit_cost' => 0,          // rien de figé
        'feed_type'      => 'Inconnu',  // et aucun article correspondant
        'user_id'        => $this->adminUser->id,
    ]);

    $couts = $this->get(route('batches.close_form', $lot))->viewData('costs');

    expect($couts['feed'])->toBe(0.0)
        ->and((float) $couts['feed_price_kg'])->toBe(0.0);

    $source = file_get_contents(base_path('app/Http/Controllers/BatchController.php'));
    expect(str_contains($source, '5000; // Fallback'))
        ->toBeFalse('Aucun prix d’aliment ne doit être écrit en dur.');
});

test('l’ÉNERGIE imputée est celle du bâtiment du lot', function () {
    /*
     * L'écran prenait la moyenne des relevés de TOUTE l'exploitation, × la durée,
     * ÷ le nombre de lots actifs : le lot payait une quote-part de bâtiments qu'il
     * n'occupe pas, et son coût changeait quand un lot voisin était clôturé.
     */
    $lot = lotAvecConsommation($this->farm->id, $this->building->id, 200, 4000);

    releveEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    $couts = $this->get(route('batches.close_form', $lot))->viewData('costs');

    expect($couts['energy'])->toBe(300_000.0);
});
