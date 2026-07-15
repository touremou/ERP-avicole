<?php

use App\Actions\FeedPurchase\CreateFeedPurchase;
use App\Actions\FeedPurchase\UpdateFeedPurchase;
use App\Models\Batch;
use App\Models\Expense;
use App\Models\FeedPurchase;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    $this->batch = Batch::factory()->create(['status' => 'Actif']);
});

test('un achat d\'aliment crée un achat fournisseur SOLDÉ, lié, sans poster de dépense', function () {
    (new CreateFeedPurchase())->execute([
        'batch_id'      => $this->batch->id,
        'purchase_date' => now()->toDateString(),
        'feed_type'     => 'Maïs concassé',
        'quantity'      => 10,
        'unit_price'    => 500000, // montant total PAYÉ
        'supplier'      => 'Provende Express',
        'unit'          => 'Sac',
        'metadata'      => ['bag_weight' => 50, 'conso_type' => 'Aliment'],
    ]);

    // La colonne metadata existe (migration) et persiste le poids du sac.
    expect(FeedPurchase::count())->toBe(1)
        ->and(FeedPurchase::first()->metadata['bag_weight'])->toBe(50);

    $inv = SupplierInvoice::first();
    expect($inv)->not->toBeNull()
        ->and($inv->category)->toBe('aliment')
        ->and((float) $inv->total_amount)->toBe(500000.0)
        ->and($inv->posts_expense)->toBeFalse()
        ->and($inv->feed_purchase_id)->toBe(FeedPurchase::first()->id)
        ->and($inv->payment_status)->toBe('solde')          // réglé à l'achat
        ->and((float) $inv->remaining_amount)->toBe(0.0);

    expect(Provider::where('name', 'Provende Express')->exists())->toBeTrue();

    // INVARIANT : zéro double-compte → aucune dépense postée (coût déjà en marge lot).
    expect(Expense::count())->toBe(0);
});

test('sans fournisseur renseigné, aucun achat fournisseur n\'est créé', function () {
    (new CreateFeedPurchase())->execute([
        'batch_id'      => $this->batch->id,
        'purchase_date' => now()->toDateString(),
        'feed_type'     => 'Tourteau',
        'quantity'      => 5,
        'unit_price'    => 100000,
        'unit'          => 'Sac',
    ]);

    expect(FeedPurchase::count())->toBe(1)
        ->and(SupplierInvoice::count())->toBe(0);
});

test('un achat d\'aliment à crédit crée une dette fournisseur (non soldée), sans dépense', function () {
    (new CreateFeedPurchase())->execute([
        'batch_id'      => $this->batch->id,
        'purchase_date' => now()->toDateString(),
        'feed_type'     => 'Tourteau soja',
        'quantity'      => 8,
        'unit_price'    => 400000,
        'supplier'      => 'Crédit Aliments',
        'unit'          => 'Sac',
        'payment_mode'  => 'credit',
    ]);

    $inv = App\Models\SupplierInvoice::first();
    expect($inv->payment_status)->toBe('impaye')
        ->and((float) $inv->remaining_amount)->toBe(400000.0)
        ->and($inv->posts_expense)->toBeFalse();

    expect(Provider::where('name', 'Crédit Aliments')->first()->outstandingDebt())->toBe(400000.0)
        ->and(Expense::count())->toBe(0); // toujours zéro double-compte
});

test('modifier un achat comptant met à jour la facture AP (montant + règlement re-calé)', function () {
    (new CreateFeedPurchase())->execute([
        'batch_id' => $this->batch->id, 'purchase_date' => now()->toDateString(),
        'feed_type' => 'Maïs', 'quantity' => 10, 'unit_price' => 500000,
        'supplier' => 'Provende Express', 'unit' => 'Sac',
    ]);
    $fp = FeedPurchase::first();

    (new UpdateFeedPurchase())->execute($fp, [
        'feed_type' => 'Maïs', 'quantity' => 10, 'unit_price' => 650000, // total corrigé
        'supplier' => 'Provende Express', 'purchase_date' => now()->toDateString(),
    ]);

    $inv = SupplierInvoice::first();
    expect((float) $inv->total_amount)->toBe(650000.0)
        ->and($inv->payment_status)->toBe('solde')          // reste soldé
        ->and((float) $inv->paid_amount)->toBe(650000.0);   // règlement re-calé
});

test('modifier un achat à crédit met à jour la dette (reste impayé)', function () {
    (new CreateFeedPurchase())->execute([
        'batch_id' => $this->batch->id, 'purchase_date' => now()->toDateString(),
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 400000,
        'supplier' => 'Crédit Aliments', 'unit' => 'Sac', 'payment_mode' => 'credit',
    ]);
    $fp = FeedPurchase::first();

    (new UpdateFeedPurchase())->execute($fp, [
        'feed_type' => 'Soja', 'quantity' => 9, 'unit_price' => 450000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
    ]);

    $inv = SupplierInvoice::first();
    expect((float) $inv->total_amount)->toBe(450000.0)
        ->and($inv->payment_status)->toBe('impaye')
        ->and((float) $inv->remaining_amount)->toBe(450000.0);
});

test('le coût aliment n\'est PAS dupliqué : la dette fournisseur globale reste nulle', function () {
    // L'achat est soldé → il ne gonfle pas les « dettes fournisseurs ».
    (new CreateFeedPurchase())->execute([
        'batch_id'      => $this->batch->id,
        'purchase_date' => now()->toDateString(),
        'feed_type'     => 'Concentré',
        'quantity'      => 4,
        'unit_price'    => 200000,
        'supplier'      => 'Agrivet',
        'unit'          => 'Sac',
    ]);

    $provider = Provider::where('name', 'Agrivet')->first();
    expect($provider->outstandingDebt())->toBe(0.0); // soldé d'emblée
});
