<?php

use App\Models\Batch;
use App\Models\FeedPurchase;
use App\Models\HealthCheck;
use App\Services\Accounting\PeriodCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE MÉDICAMENT COMPTE À L'ACHAT — DÉCISION DE L'EXPLOITATION.
 *
 * Avant : un vaccin acheté pour le lot comptait dans la marge du lot (achat
 * non-aliment), mais NULLE PART au compte de résultat ni au rapport sanitaire,
 * qui ne lisaient que le coût saisi sur l'acte ; la clôture le rangeait en
 * « autres coûts » et le prenait pour de l'aliment non pointé. Et une litière
 * ou un matériel achetés pour le lot étaient classés « Aliment » — compté au
 * consommé, donc jamais.
 *
 * Désormais : le produit compte à sa date d'achat, partout ; le coût saisi
 * sur l'acte ne porte que ce qui ne passe pas par un achat (honoraires).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'arrival_date' => now()->subMonth()->toDateString(), 'total_acquisition_cost' => 0, 'additional_costs' => 0,
    ]);
});

function acheterPourLeLot(Tests\TestCase $test, Batch $lot, string $article, int $total, array $champs): void
{
    $test->from(route('batches.show', $lot))->post(route('feed-purchases.store'), array_merge([
        'batch_id' => $lot->id, 'purchase_date' => today()->toDateString(), 'supplier' => 'Vétopharma',
        'payment_mode' => 'comptant', 'feed_type' => $article, 'quantity' => 1, 'unit' => 'Boite', 'unit_price' => $total,
    ], $champs))->assertSessionHasNoErrors();
}

test('un vaccin acheté pour le lot compte à l’achat : marge, compte de résultat, rapport sanitaire, clôture', function () {
    acheterPourLeLot($this, $this->lot, 'VACCIN HB1', 60000, ['category' => 'conso', 'metadata' => ['conso_type' => 'Santé']]);
    // L'acte : 5 000 d'honoraires, le produit n'est pas ressaisi.
    HealthCheck::create(['farm_id' => $this->farm->id, 'batch_id' => $this->lot->id, 'intervention_date' => today()->toDateString(),
        'type' => 'Vaccin', 'product_name' => 'VACCIN HB1', 'mode_administration' => 'Eau', 'cost' => 5000]);

    $lot = $this->lot->fresh();
    expect($lot->health_purchases_cost)->toBe(60000.0)
        ->and($lot->operating_cost)->toEqual(65000);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());
    expect($charges['Santé / prophylaxie'])->toEqual(65000);

    $rapport = $this->get(route('reports.health_finance'))->assertOk();
    expect($rapport->viewData('totalGlobalCost'))->toEqual(65000);

    $cloture = $this->get(route('batches.close_form', $lot))->assertOk()->viewData('costs');
    expect($cloture['health'])->toEqual(65000);
});

test('une litière achetée pour le lot n’est plus prise pour de l’aliment', function () {
    acheterPourLeLot($this, $this->lot, 'COPEAUX', 30000, ['category' => 'litieres', 'unit' => 'Sac']);

    $achat = FeedPurchase::where('feed_type', 'COPEAUX')->firstOrFail();
    expect($achat->category)->toBe(FeedPurchase::NATURE_LITIERE)
        ->and($achat->isFeed())->toBeFalse()
        ->and($this->lot->fresh()->operating_cost)->toEqual(30000);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());
    expect($charges['Achats des lots (hygiène, litière, matériel)'])->toEqual(30000);
});

test('un médicament acheté ne déclenche pas l’alerte « aliment acheté jamais pointé »', function () {
    acheterPourLeLot($this, $this->lot, 'VACCIN HB1', 60000, ['category' => 'conso', 'metadata' => ['conso_type' => 'Santé']]);

    expect($this->get(route('batches.close_form', $this->lot))->assertOk()->viewData('costs')['feed_unlogged'] ?? 0)->toEqual(0);
});
