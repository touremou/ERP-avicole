<?php

use App\Actions\FeedPurchase\CreateFeedPurchase;
use App\Models\Batch;
use App\Models\Setting;
use App\Models\Stock;
use App\Services\UnitConverter;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE POIDS DU SAC ÉTAIT DÉCLARÉ DEUX FOIS, ET LA DIVERGENCE TOMBAIT SUR L'ARGENT.
 *
 * `UnitConverter::bagWeight()` est la règle : surcharge de l'achat, sinon le
 * réglage `general.feed_bag_weight`, sinon 50 kg. Le modèle FeedPurchase — celui
 * qui affiche la fiche — l'applique correctement.
 *
 * Mais l'ACTION qui écrit au magasin et calcule le coût écrivait
 * `$metadata['bag_weight'] ?? 50` : elle sautait le réglage de la ferme.
 *
 * Pour une exploitation achetant en sacs de 25 kg, un même achat de 20 sacs à
 * 500 000 GNF donnait donc :
 *
 *   • fiche de l'achat ......... 500 kg     (20 × 25, correct)
 *   • stock crédité ............ 1 000 kg   (20 × 50, LE DOUBLE)
 *   • coût pivot ............... 500 GNF/kg (au lieu de 1 000, LA MOITIÉ)
 *
 * Le magasin était gonflé du double et le coût moyen pondéré sous-évalué — donc le
 * coût de revient de chaque bande nourrie sur ce stock. C'est un chiffre sur lequel
 * on décide, pas un détail d'affichage.
 *
 * Sans effet pour une ferme restée à 50 kg : le défaut ne se déclenche qu'une fois
 * le réglage utilisé — ce qui en fait un piège, pas une panne visible.
 *
 * PIÈGE DE NOMMAGE À CONNAÎTRE, rencontré en écrivant ces tests : le champ
 * `unit_price` du tableau passé à l'action reçoit en réalité le prix TOTAL de
 * l'achat — l'action calcule ensuite le prix unitaire en le divisant par la
 * quantité (CreateFeedPurchase::execute). Et le poids de sac d'un achat précis se
 * pose dans `metadata['bag_weight']`, pas à la racine.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
});

test('le réglage de la ferme gouverne la conversion sac → kg', function () {
    Setting::set('general.feed_bag_weight', 25);

    expect(UnitConverter::bagWeight())->toBe(25.0)
        // La surcharge d'un achat précis reste prioritaire : un sac de 40 kg
        // acheté exceptionnellement ne doit pas être compté à 25.
        ->and(UnitConverter::bagWeight(40))->toBe(40.0);
});

test('un achat en SACS crédite le magasin selon le réglage, pas 50 en dur', function () {
    // LE test de régression. Avant, 20 sacs de 25 kg créditaient 1 000 kg.
    Setting::set('general.feed_bag_weight', 25);

    app(CreateFeedPurchase::class)->execute([
        'batch_id'      => $this->batch->id,
        'feed_type'     => 'Chair Démarrage',
        'quantity'      => 20,
        'unit'          => 'Sac',
        'unit_price'    => 25000,
        'purchase_date' => now()->toDateString(),
    ]);

    $stock = Stock::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('item_name', 'like', '%Démarrage%')
        ->first();

    expect($stock)->not->toBeNull()
        ->and((float) $stock->current_quantity)->toBe(500.0);
});

test('le coût au kilo suit la même conversion — le CMP n’est pas faussé', function () {
    // La conséquence financière : un coût pivot de moitié sous-évalue le coût de
    // revient de toutes les bandes nourries sur ce stock.
    Setting::set('general.feed_bag_weight', 25);

    app(CreateFeedPurchase::class)->execute([
        'batch_id'      => $this->batch->id,
        'feed_type'     => 'Chair Croissance',
        'quantity'      => 20,
        'unit'          => 'Sac',
        'unit_price'    => 500000,  // prix TOTAL de l'achat, pour 500 kg
        'purchase_date' => now()->toDateString(),
    ]);

    $stock = Stock::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('item_name', 'like', '%Croissance%')
        ->first();

    // 500 000 / 500 kg = 1 000 GNF/kg. Avec le « 50 » codé en dur : 500.
    expect((float) $stock->unit_price)->toBe(1000.0);
});

test('la fiche de l’achat et le magasin annoncent la MÊME quantité', function () {
    // Le cœur du défaut : deux déclarations d'une même règle. Ce test les confronte
    // directement, ce qu'aucun test ne faisait.
    Setting::set('general.feed_bag_weight', 25);

    $purchase = app(CreateFeedPurchase::class)->execute([
        'batch_id'      => $this->batch->id,
        'feed_type'     => 'Chair Finition',
        'quantity'      => 12,
        'unit'          => 'Sac',
        'unit_price'    => 30000,
        'purchase_date' => now()->toDateString(),
    ]);

    $stock = Stock::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('item_name', 'like', '%Finition%')
        ->first();

    expect((float) $purchase->normalized_quantity)->toBe((float) $stock->current_quantity);
});

test('une surcharge d’achat l’emporte sur le réglage', function () {
    Setting::set('general.feed_bag_weight', 25);

    app(CreateFeedPurchase::class)->execute([
        'batch_id'      => $this->batch->id,
        'feed_type'     => 'Ponte Production',
        'quantity'      => 10,
        'unit'          => 'Sac',
        'unit_price'    => 40000,
        'purchase_date' => now()->toDateString(),
        'metadata'      => ['bag_weight' => 40],
    ]);

    $stock = Stock::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('item_name', 'like', '%Ponte%')
        ->first();

    expect((float) $stock->current_quantity)->toBe(400.0);
});

test('une ferme restée à 50 kg ne voit aucun changement', function () {
    // Non-régression : le défaut ne se déclenchait qu'avec le réglage utilisé. La
    // correction ne doit rien déplacer pour qui ne l'a jamais touché.
    app(CreateFeedPurchase::class)->execute([
        'batch_id'      => $this->batch->id,
        'feed_type'     => 'Chair Démarrage',
        'quantity'      => 10,
        'unit'          => 'Sac',
        'unit_price'    => 25000,
        'purchase_date' => now()->toDateString(),
    ]);

    $stock = Stock::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('item_name', 'like', '%Démarrage%')
        ->first();

    expect((float) $stock->current_quantity)->toBe(500.0);
});

test('la conversion sac → kg n’est déclarée QU’UNE fois', function () {
    // Garde-fou : c'est le repli « ?? 50 » recopié à côté de la règle qui a produit
    // la divergence. Tout facteur de conversion écrit en dur hors d'UnitConverter
    // est un candidat au même défaut.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        // UnitConverter EST la déclaration : c'est le seul endroit légitime.
        if (str_ends_with($file->getPathname(), 'Services/UnitConverter.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $no => $line) {
            if (str_starts_with(ltrim($line), '*') || str_starts_with(ltrim($line), '//')) {
                continue;
            }

            if (preg_match("/bag_weight'\]\s*\?\?\s*\d/", $line)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($no + 1);
            }
        }
    }

    expect($offenders)->toBe([], "Poids de sac codé en dur, hors d'UnitConverter :\n  " . implode("\n  ", $offenders));
});
