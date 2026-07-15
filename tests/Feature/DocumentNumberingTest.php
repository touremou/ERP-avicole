<?php

use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Setting;
use App\Services\DocumentNumberingService;
use App\Services\SaleNumberingService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->year = now()->format('Y');
});

// ─── Format & séquence ─────────────────────────────────────────────────────────

test('le premier numéro avec année suit le format PREFIX-AAAA-000001', function () {
    expect(DocumentNumberingService::generate('mill_production'))
        ->toBe("OP-{$this->year}-000001");
});

test('le premier numéro sans année suit le format PREFIX-00001', function () {
    expect(DocumentNumberingService::generate('expense'))->toBe('DEP-00001');
});

test('la séquence s\'incrémente à partir du dernier numéro existant', function () {
    $formula = Formula::create([
        'farm_id' => $this->farm->id, 'name' => 'F', 'code' => 'F-1',
        'target_type' => 'ponte', 'total_batch_weight' => 1000, 'is_active' => true,
    ]);

    MillProduction::create([
        'farm_id'           => $this->farm->id,
        'batch_number'      => "OP-{$this->year}-000001",
        'formula_id'        => $formula->id,
        'quantity_produced' => 500,
        'operator_id'       => $this->operatorUser->id,
        'status'            => 'Planifié',
    ]);

    expect(DocumentNumberingService::generate('mill_production'))
        ->toBe("OP-{$this->year}-000002");
});

test('l\'ordre de production n\'utilise plus de suffixe aléatoire', function () {
    // Régression : l'ancien format OP-YYYYMMDD-HHmm-XXXX (horodaté + aléatoire)
    // est remplacé par une séquence monotone et prévisible.
    expect(DocumentNumberingService::generate('mill_production'))
        ->toMatch('/^OP-\d{4}-\d{6}$/');
});

// ─── Préfixe configurable ────────────────────────────────────────────────────

test('le préfixe est piloté par les Réglages', function () {
    Setting::set('numbering.expense_prefix', 'CHARGE');

    expect(DocumentNumberingService::generate('expense'))->toBe('CHARGE-00001');
});

// ─── Robustesse ────────────────────────────────────────────────────────────────

test('un type de document inconnu lève une exception', function () {
    DocumentNumberingService::generate('inexistant');
})->throws(InvalidArgumentException::class);

// ─── Rétro-compatibilité ventes ─────────────────────────────────────────────

test('SaleNumberingService délègue toujours et respecte les préfixes ventes', function () {
    // Le préfixe facture est configuré à « FA » dans les Réglages (seed ventes).
    expect(SaleNumberingService::generate('bon_livraison'))->toBe("BL-{$this->year}-000001")
        ->and(SaleNumberingService::generate('facture'))->toBe("FA-{$this->year}-000001");
});

test('un préfixe de vente reparamétré est pris en compte', function () {
    Setting::set('ventes.invoice_prefix_bl', 'BON');

    expect(SaleNumberingService::generate('bon_livraison'))->toBe("BON-{$this->year}-000001");
});
