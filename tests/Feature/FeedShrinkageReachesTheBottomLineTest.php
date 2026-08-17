<?php

use App\Actions\Stock\CreateStockAdjustment;
use App\Models\Stock;
use App\Services\Accounting\PeriodCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ALIMENT VOLÉ NE COÛTAIT RIEN.
 *
 * La démarque était chiffrée (`value_impact`, au CMP figé), exportée, affichée
 * en tuile — et ne pesait sur AUCUN résultat. Un sac sorti du magasin sans
 * passer par les mangeoires n'apparaissait dans aucune charge.
 *
 * Le mécanisme : l'aliment est imputé À LA CONSOMMATION, lue sur les pointages.
 * Ce qui est volé n'est jamais consommé, donc jamais compté. Le bénéfice affiché
 * était surévalué du montant exact des pertes — sur le poste que le promoteur,
 * à l'étranger, surveille le plus.
 *
 * ─── POURQUOI L'ALIMENT SEUL ───
 *
 * C'est le seul cas où le double comptage est impossible. Les autres stocks sont
 * chargés À L'ACHAT : matières premières de la provenderie par le module
 * Dépenses (convention verrouillée par RawMaterialEntryIsInventoryOnlyTest),
 * médicaments et consommables au prix d'achat. Leur démarque est déjà dans les
 * charges, au jour de l'achat.
 *
 * La catégorie « conso » porte d'ailleurs « Aliment & Santé » : compter toute la
 * catégorie aurait ramassé les médicaments au passage. Le tri s'appuie sur la
 * règle déjà écrite dans le modèle Batch — un `conso_type` absent vaut
 * « Aliment » — désormais portée par Stock::isFeed() plutôt que recopiée.
 *
 * ─── LES GAINS NE SONT PAS DÉDUITS ───
 *
 * Ce n'est pas une symétrie oubliée. Le coût de l'aliment ne vient pas du stock
 * mais des pointages : un écart d'inventaire positif ne correspond à aucune
 * charge déjà prise, et le déduire créerait un avoir sorti de nulle part.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->silo = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Aliment ponte',
        'category' => Stock::CAT_CONSO, 'unit' => 'KG',
        'current_quantity' => 2000, 'alert_threshold' => 100,
        'unit_price' => 5000, 'last_unit_price' => 5000,
        'metadata' => ['conso_type' => 'Aliment'],
    ]);
});

/** Constate une perte sur un article, au CMP du jour. */
function perdre(Stock $article, float $quantiteRestante, int $userId, string $motif = 'vol'): void
{
    app(CreateStockAdjustment::class)->execute(
        $article->id,
        $quantiteRestante,
        $motif,
        'Constat magasin',
        $userId,
        now()->toDateString(),
    );
}

test('l’aliment volé pèse enfin sur les charges', function () {
    /*
     * LE défaut, chiffré : 200 kg à 5 000 disparaissent, soit 1 000 000 qui ne
     * coûtaient rien.
     */
    perdre($this->silo, 1800, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Démarque aliment'])->toBe(1000000.0);
});

test('la démarque des MATIÈRES PREMIÈRES n’est pas recomptée', function () {
    /*
     * La borne qui a décidé du périmètre. Les ingrédients de la provenderie sont
     * payés au module Dépenses : leur perte est déjà dans les charges, au jour
     * de l'achat. L'ajouter ici gonflerait les charges du même montant.
     */
    $mais = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Maïs concassé',
        'category' => 'matiere_premiere', 'unit' => 'KG',
        'current_quantity' => 1000, 'alert_threshold' => 100,
        'unit_price' => 4000, 'last_unit_price' => 4000,
    ]);

    perdre($mais, 500, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Démarque aliment'])->toBe(0.0);
});

test('un MÉDICAMENT perdu n’est pas compté comme de l’aliment', function () {
    /*
     * La catégorie « conso » porte « Aliment & Santé ». Compter la catégorie
     * entière aurait ramassé les médicaments, chargés au prix d'achat.
     */
    $vaccin = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Vaccin Gumboro',
        'category' => Stock::CAT_CONSO, 'unit' => 'Unité',
        'current_quantity' => 100, 'alert_threshold' => 10,
        'unit_price' => 20_000, 'last_unit_price' => 20_000,
        'metadata' => ['conso_type' => 'Santé'],
    ]);

    perdre($vaccin, 90, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Démarque aliment'])->toBe(0.0);
});

test('un conso_type ABSENT vaut aliment, comme partout ailleurs', function () {
    /*
     * La règle du modèle Batch, tenue ici aussi : les articles antérieurs à la
     * métadonnée ne doivent pas échapper au comptage par leur seule ancienneté.
     */
    $ancien = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Aliment démarrage',
        'category' => Stock::CAT_CONSO, 'unit' => 'KG',
        'current_quantity' => 500, 'alert_threshold' => 50,
        'unit_price' => 6000, 'last_unit_price' => 6000,
    ]);

    perdre($ancien, 400, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Démarque aliment'])->toBe(600000.0);
});

test('un GAIN d’inventaire ne crée pas d’avoir', function () {
    /*
     * La borne annoncée dans l'en-tête. Le coût de l'aliment vient des
     * pointages, pas du stock : un écart positif ne rembourse aucune charge.
     */
    perdre($this->silo, 2500, $this->adminUser->id);   // comptage supérieur au livre → gain

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Démarque aliment'])->toBe(0.0);
});

test('une perte HORS PÉRIODE ne remonte pas', function () {
    // La période borne la charge, comme toutes les autres lignes.
    app(CreateStockAdjustment::class)->execute(
        $this->silo->id, 1800, 'vol', 'Mois précédent',
        $this->adminUser->id,
        now()->subMonth()->startOfMonth()->toDateString(),
    );

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Démarque aliment'])->toBe(0.0);
});

test('la ligne remonte aux DEUX écrans, pas à un seul', function () {
    /*
     * La leçon de ce fichier de service : deux écrans répondaient à « combien
     * ai-je gagné » sans compter les mêmes charges. La démarque entre par la
     * déclaration commune — donc au compte de résultat ET au tableau de bord,
     * du même coup.
     */
    perdre($this->silo, 1800, $this->adminUser->id);

    $this->actingAs($this->adminUser);

    // 1. Le COMPTE DE RÉSULTAT : la charge y figure, nommée.
    $pnl = $this->get(route('reports.profit_loss', [
        'date_from' => now()->startOfMonth()->toDateString(),
        'date_to'   => now()->endOfMonth()->toDateString(),
    ]))->assertOk();

    expect($pnl->viewData('costs'))->toHaveKey('Démarque aliment')
        ->and($pnl->viewData('costs')['Démarque aliment'])->toBe(1000000.0);

    // 2. Le TABLEAU DE BORD, qui lit la même déclaration : ses charges du mois
    //    incluent donc la perte, sans qu'on ait eu à l'y ajouter séparément.
    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect(array_sum($charges))->toBeGreaterThanOrEqual(1000000.0);
});
