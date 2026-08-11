<?php

use App\Actions\FeedPurchase\CreateFeedPurchase;
use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Setting;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RECALCUL DU COÛT DE L'ALIMENT — vérifié sur des exemples calculés à la main.
 *
 * Le coût au kilo des achats en sacs était calculé à 50 kg codé en dur, quel que
 * soit le réglage. Ce coût alimente le CMP de l'article, et le CMP est FIGÉ dans
 * `daily_checks.feed_unit_cost` à chaque pointage : c'est cette valeur figée qui
 * construit le coût de revient des bandes. Corriger le CMP courant ne répare donc
 * rien du passé.
 *
 * La commande rejoue chronologiquement les entrées (achats) et les sorties
 * (consommations) pour reconstituer la trajectoire du coût moyen pondéré.
 *
 * POURQUOI UN REJEU ET NON UNE RÈGLE DE TROIS : une multiplication par un facteur
 * serait fausse dès qu'un article mêle sacs et kilos, ou dès que le poids du sac a
 * changé en route. Et surtout, les SORTIES comptent : sans elles, un achat tardif
 * de 10 kg pèserait autant qu'un stock déjà consommé. Le test « les sorties
 * changent le poids des achats suivants » le démontre sur des chiffres.
 *
 * SIMULATION PAR DÉFAUT : rien n'est écrit sans --force. Une correction de chiffres
 * comptables ne s'applique pas par surprise.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
});

/** Achat en sacs, à une date donnée, pour un prix TOTAL. */
function buyBags(int $batchId, string $article, float $bags, int $totalPrice, string $date): void
{
    app(CreateFeedPurchase::class)->execute([
        'batch_id'      => $batchId,
        'feed_type'     => $article,
        'quantity'      => $bags,
        'unit'          => 'Sac',
        'unit_price'    => $totalPrice,   // l'action attend le TOTAL (cf. son contrat)
        'purchase_date' => $date,
    ]);
}

/**
 * Consommation figée à un coût donné — l'état qu'aurait laissé l'ancien calcul.
 *
 * Ferme et utilisateur passés en PARAMÈTRES : les propriétés de $this ne sont pas
 * accessibles depuis une fonction globale de Pest.
 */
function consume(int $farmId, int $userId, int $batchId, string $article, float $kg, float $frozenCost, string $date): DailyCheck
{
    return DailyCheck::create([
        'farm_id'        => $farmId,
        'batch_id'       => $batchId,
        'user_id'        => $userId,
        'check_date'     => $date,
        'feed_consumed'  => $kg,
        'feed_type'      => $article,
        'feed_unit_cost' => $frozenCost,
    ]);
}

test('la simulation n’écrit RIEN', function () {
    // Le garde-fou principal : une correction de chiffres comptables ne doit pas
    // s'appliquer parce qu'on a lancé la commande pour voir.
    Setting::set('general.feed_bag_weight', 25);
    buyBags($this->batch->id, 'Chair Démarrage', 20, 500000, now()->subDays(10)->toDateString());

    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Démarrage', 100, 500.0, now()->subDays(5)->toDateString());
    $before = (float) Stock::withoutGlobalScopes()->where('item_name', 'Chair Démarrage')->value('unit_price');

    $this->artisan('feed:recompute-costs')->assertExitCode(0);

    expect((float) $check->fresh()->feed_unit_cost)->toBe(500.0)
        ->and((float) Stock::withoutGlobalScopes()->where('item_name', 'Chair Démarrage')->value('unit_price'))
        ->toBe($before);
});

test('avec --force, la consommation est revalorisée au coût réel', function () {
    // Exemple calculé à la main : 20 sacs de 25 kg = 500 kg pour 500 000 GNF,
    // soit 1 000 GNF/kg. L'ancien calcul avait figé 500 (50 kg/sac).
    Setting::set('general.feed_bag_weight', 25);
    buyBags($this->batch->id, 'Chair Démarrage', 20, 500000, now()->subDays(10)->toDateString());

    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Démarrage', 100, 500.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --force')->assertExitCode(0);

    expect((float) $check->fresh()->feed_unit_cost)->toBe(1000.0);
});

test('les SORTIES changent le poids des achats suivants', function () {
    // LE test qui justifie le rejeu plutôt qu'un facteur.
    //
    //   1er achat : 100 kg pour 100 000  → CMP 1 000
    //   consommation de 90 kg            → reste 10 kg à 1 000
    //   2e achat  : 100 kg pour 300 000  → CMP (10×1 000 + 300 000) / 110 = 2 818,18
    //
    // Sans tenir compte de la sortie, on obtiendrait (100 000 + 300 000) / 200 =
    // 2 000 : une erreur de 40 % sur le coût de tout ce qui est consommé ensuite.
    Setting::set('general.feed_bag_weight', 50);

    buyBags($this->batch->id, 'Chair Croissance', 2, 100000, now()->subDays(20)->toDateString());
    consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Croissance', 90, 0.0, now()->subDays(15)->toDateString());
    buyBags($this->batch->id, 'Chair Croissance', 2, 300000, now()->subDays(10)->toDateString());

    $late = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Croissance', 10, 0.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --force')->assertExitCode(0);

    expect(round((float) $late->fresh()->feed_unit_cost, 2))->toBe(2818.18);
});

test('un article MÊLANT sacs et kilos est traité juste', function () {
    // Un facteur unique serait faux ici : seule la moitié des entrées est en sacs.
    Setting::set('general.feed_bag_weight', 25);

    // 10 sacs de 25 kg = 250 kg pour 250 000 → 1 000 GNF/kg
    buyBags($this->batch->id, 'Chair Finition', 10, 250000, now()->subDays(20)->toDateString());

    // 250 kg achetés au kilo pour 750 000 → 3 000 GNF/kg
    app(CreateFeedPurchase::class)->execute([
        'batch_id' => $this->batch->id, 'feed_type' => 'Chair Finition',
        'quantity' => 250, 'unit' => 'KG', 'unit_price' => 750000,
        'purchase_date' => now()->subDays(15)->toDateString(),
    ]);

    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Finition', 50, 0.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --force')->assertExitCode(0);

    // (250 000 + 750 000) / 500 kg = 2 000 GNF/kg
    expect((float) $check->fresh()->feed_unit_cost)->toBe(2000.0);
});

test('le recalcul est IDEMPOTENT', function () {
    // Il dérive des achats, jamais de la valeur courante : le relancer ne dérive
    // pas. Sans quoi un second passage empilerait les corrections.
    Setting::set('general.feed_bag_weight', 25);
    buyBags($this->batch->id, 'Chair Démarrage', 20, 500000, now()->subDays(10)->toDateString());
    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Démarrage', 100, 500.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --force');
    $first = (float) $check->fresh()->feed_unit_cost;

    $this->artisan('feed:recompute-costs --force');

    expect((float) $check->fresh()->feed_unit_cost)->toBe($first);
});

test('un article recevant de l’aliment de la PROVENDERIE est écarté, pas revalorisé', function () {
    // Refuser vaut mieux que revaloriser à moitié : la commande ne sait pas
    // valoriser une entrée produite en interne, et un CMP reconstruit sur les seuls
    // achats serait faux pour cet article.
    Setting::set('general.feed_bag_weight', 25);
    buyBags($this->batch->id, 'Chair Démarrage', 20, 500000, now()->subDays(10)->toDateString());

    $stock = Stock::withoutGlobalScopes()->where('item_name', 'Chair Démarrage')->first();

    DB::table('stock_movements')->insert([
        'stock_id' => $stock->id, 'farm_id' => $this->farm->id, 'type' => 'in',
        'quantity' => 200, 'notes' => 'Production Provenderie Lot #OP-1',
        'user_id' => $this->adminUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Démarrage', 100, 500.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --force')
        ->expectsOutputToContain('ÉCARTÉS')
        ->assertExitCode(0);

    // Rien touché : la valeur figée reste celle d'avant.
    expect((float) $check->fresh()->feed_unit_cost)->toBe(500.0);
});

test('le poids de sac peut être imposé pour une période passée', function () {
    // Le réglage a pu changer en route (cf. setting_audits). L'option permet de
    // rejouer chaque période avec le poids qui était réellement en vigueur.
    Setting::set('general.feed_bag_weight', 50);
    buyBags($this->batch->id, 'Ponte Production', 10, 250000, now()->subDays(10)->toDateString());

    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Ponte Production', 50, 0.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --bag-weight=25 --force')->assertExitCode(0);

    // 10 sacs × 25 kg = 250 kg pour 250 000 → 1 000 GNF/kg (et non 500 à 50 kg).
    expect((float) $check->fresh()->feed_unit_cost)->toBe(1000.0);
});

test('une consommation déjà juste n’est pas touchée', function () {
    // Le rapport ne doit lister que ce qui change : une liste de lignes identiques
    // cacherait celles qui comptent.
    Setting::set('general.feed_bag_weight', 50);
    buyBags($this->batch->id, 'Chair Démarrage', 10, 500000, now()->subDays(10)->toDateString());

    // 10 × 50 = 500 kg pour 500 000 → 1 000 GNF/kg, déjà figé juste.
    $check = consume($this->farm->id, $this->adminUser->id, $this->batch->id, 'Chair Démarrage', 100, 1000.0, now()->subDays(5)->toDateString());

    $this->artisan('feed:recompute-costs --force')->assertExitCode(0);

    expect((float) $check->fresh()->feed_unit_cost)->toBe(1000.0);
});
