<?php

use App\Models\FinishedProduct;
use App\Models\Stock;
use App\Support\Peremption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA DATE DE PÉREMPTION EST LE DERNIER JOUR D'USAGE — PARTOUT.
 *
 * Le scope Stock::expired() et la tâche stocks:check-expiry tenaient un
 * article pour périmé le LENDEMAIN de sa date ; les accesseurs is_expired
 * (isPast) le jour même, dès minuit passé. Le jour J, le tableau de bord le
 * rangeait parmi les « bientôt périmés » et sa ligne le disait « périmé ».
 * Les formulaires sanitaires, eux, acceptent un vaccin le jour de sa date.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('le jour de sa date, un produit est encore bon ; le lendemain, il est périmé', function () {
    expect(Peremption::estPerime(today()))->toBeFalse()
        ->and(Peremption::estPerime(today()->subDay()))->toBeTrue()
        ->and(Peremption::estPerime(null))->toBeFalse();
});

test('l’article du magasin dit ce que dit sa requête', function () {
    $aujourdhui = Stock::create(['farm_id' => $this->farm->id, 'category' => Stock::CAT_CONSO, 'item_name' => 'Vaccin J',
        'unit' => 'Flacon', 'current_quantity' => 5, 'alert_threshold' => 0, 'expiry_date' => today()->toDateString()]);
    $hier = Stock::create(['farm_id' => $this->farm->id, 'category' => Stock::CAT_CONSO, 'item_name' => 'Vaccin J-1',
        'unit' => 'Flacon', 'current_quantity' => 5, 'alert_threshold' => 0, 'expiry_date' => today()->subDay()->toDateString()]);

    $perimes = Stock::expired()->pluck('id');

    expect($aujourdhui->fresh()->is_expired)->toBe($perimes->contains($aujourdhui->id))->toBeFalse()
        ->and($hier->fresh()->is_expired)->toBe($perimes->contains($hier->id))->toBeTrue()
        ->and(Stock::expiringSoon()->pluck('id')->all())->toBe([$aujourdhui->id]);
});

test('un produit fini du jour est « bientôt périmé », pas « périmé »', function () {
    $produit = FinishedProduct::create(['farm_id' => $this->farm->id, 'product_name' => 'Poulet PAC', 'product_type' => 'entier_frais',
        'current_quantity_kg' => 10, 'unit' => 'KG', 'expiry_date' => today()->toDateString()]);

    expect($produit->is_expired)->toBeFalse()
        ->and($produit->is_expiring_soon)->toBeTrue();
});
