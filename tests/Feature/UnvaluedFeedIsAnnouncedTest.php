<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN COÛT INCONNU SE PRÉSENTAIT COMME UN COÛT NUL.
 *
 * `Batch::feedConsumptionLedger()` valorise chaque pointage au coût figé le jour
 * de la saisie (`daily_checks.feed_unit_cost`), avec repli sur le coût moyen
 * pondéré courant de l'article correspondant.
 *
 * Quand ni l'un ni l'autre n'existe — type d'aliment renommé, article supprimé,
 * pointage antérieur au figeage — le coût unitaire vaut zéro. Ces kilos entrent
 * dans `feed_cogs` pour RIEN : le lot a mangé, sa marge s'en trouve flattée, et
 * aucun écran ne le disait.
 *
 * ─── ON NE FABRIQUE PAS DE PRIX DE REPLI ───
 *
 * Un chiffre inventé ne se remarque pas ; un chiffre annoncé manquant se
 * corrige. L'écran de clôture signalait déjà le cas voisin — « aliment acheté
 * mais jamais pointé » — et c'est le même geste : dire ce qu'on ne sait pas,
 * AVANT de figer une marge.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'arrival_date'     => today()->subDays(30)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 480,
        'status'           => 'Actif',
    ]);
});

/** Un pointage de consommation, avec ou sans coût figé. */
function pointageAliment(int $farmId, Batch $lot, float $kg, ?float $coutFige, string $type, int $userId): DailyCheck
{
    return DailyCheck::create([
        'farm_id'        => $farmId,
        'batch_id'       => $lot->id,
        'check_date'     => today()->subDays(2)->toDateString(),
        'feed_consumed'  => $kg,
        'feed_unit_cost' => $coutFige,
        'feed_type'      => $type,
        'mortality'      => 0,
        'user_id'        => $userId,
    ]);
}

test('des kilos SANS prix connu sont comptés et annoncés', function () {
    /*
     * LE défaut : 120 kg pointés, aucun coût figé, aucun article « Ponte 2 » au
     * magasin. Ils valent zéro dans la marge — il faut le dire.
     */
    pointageAliment($this->farm->id, $this->lot, 120, null, 'Ponte 2', $this->adminUser->id);

    expect($this->lot->fresh()->unvaluedFeedKg())->toBe(120.0);

    $this->get(route('batches.close_form', $this->lot))
        ->assertOk()
        ->assertSee('sans prix connu');
});

test('un pointage VALORISÉ ne déclenche pas l’avertissement', function () {
    /*
     * LA borne : le cas courant ne doit rien afficher, sans quoi l'avertissement
     * deviendrait du bruit et cesserait d'être lu.
     */
    pointageAliment($this->farm->id, $this->lot, 120, 4_500, 'Ponte 2', $this->adminUser->id);

    expect($this->lot->fresh()->unvaluedFeedKg())->toBe(0.0);

    $this->get(route('batches.close_form', $this->lot))
        ->assertOk()
        ->assertDontSee('sans prix connu');
});

test('le REPLI sur le magasin valorise, donc ne signale rien', function () {
    /*
     * L'autre moitié du mécanisme : sans coût figé, mais avec un article
     * correspondant au magasin, la valorisation aboutit. Le pointage n'est pas
     * « sans prix connu ».
     */
    Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Ponte 2',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 1_000,
        'alert_threshold'  => 0,
        'unit_price'       => 4_000,
        'last_unit_price'  => 4_000,
    ]);

    pointageAliment($this->farm->id, $this->lot, 100, null, 'Ponte 2', $this->adminUser->id);

    $lot = $this->lot->fresh();

    expect($lot->unvaluedFeedKg())->toBe(0.0)
        ->and((float) $lot->feed_cogs)->toBe(400_000.0);
});

test('seuls les kilos NON valorisés sont comptés, pas les autres', function () {
    /*
     * Le mélange : deux pointages, l'un chiffré et l'autre non. L'avertissement
     * ne doit porter que sur ce qui manque.
     */
    pointageAliment($this->farm->id, $this->lot, 80, 5_000, 'Ponte 1', $this->adminUser->id);

    DailyCheck::create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $this->lot->id,
        'check_date'     => today()->subDay()->toDateString(),
        'feed_consumed'  => 45,
        'feed_unit_cost' => null,
        'feed_type'      => 'Inconnu',
        'mortality'      => 0,
        'user_id'        => $this->adminUser->id,
    ]);

    $lot = $this->lot->fresh();

    expect($lot->unvaluedFeedKg())->toBe(45.0)
        ->and((float) $lot->feed_cogs)->toBe(400_000.0);   // seuls les 80 kg chiffrés
});
