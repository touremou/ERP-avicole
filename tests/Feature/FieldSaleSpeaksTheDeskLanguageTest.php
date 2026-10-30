<?php

use App\Actions\Sale\ValidateSale;
use App\Models\Batch;
use App\Models\Client;
use App\Models\Farm;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE VENTE DU TERRAIN PARLE LA LANGUE DU BUREAU.
 *
 * Le bureau écrit les unités en codes (« unite », « alveole ») ; le terrain
 * envoyait l'unité de l'article de stock (« Unité », « Alvéole »).
 * SaleItem::stockInputUnit() ne connaît que les codes : « Unité » valait
 * « KG », et 300 œufs vendus à la pièce depuis le terrain sortaient 300
 * ALVÉOLES (9 000 œufs). L'écran de vente du terrain proposait « mobile_money »
 * quand toute vente dit « orange_money ». Et la synchronisation acceptait
 * n'importe quel type, unité, taux de TVA ou lot — le formulaire du bureau,
 * des référentiels fermés.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert(['farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

    $this->client = Client::create(['farm_id' => $this->farm->id, 'client_id' => 'CLI-TER', 'name' => 'Boutique',
        'type' => 'entreprise', 'category' => 'detaillant', 'phone' => '620000000', 'status' => 'actif',
        'credit_limit' => 0, 'balance' => 0]);
    Sanctum::actingAs($this->managerUser);
    $this->oeufs = Stock::create(['farm_id' => $this->farm->id, 'category' => Stock::CAT_OEUFS, 'item_name' => 'M',
        'unit' => 'Alvéole', 'current_quantity' => 1000, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0]);
});

function venteDuTerrain(Tests\TestCase $test, int $ferme, array $ligne, array $champs = []): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'sale.create',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(), 'client_id' => $test->client->id,
            'sale_date' => now()->toDateString(), 'type' => 'bon_livraison',
            'items' => [array_merge(['product_type' => 'oeufs', 'product_name' => 'M', 'product_id' => $test->oeufs->id,
                'quantity' => 1, 'unit' => 'Alvéole', 'unit_price' => 1000], $ligne)],
        ], $champs),
    ]]], ['X-Farm-Id' => (string) $ferme])->assertOk()->json('results.0');
}

test('les unités du stock deviennent des unités de vente', function () {
    expect(SaleItem::normaliserUnite('Unité'))->toBe('unite')
        ->and(SaleItem::normaliserUnite('Alvéole'))->toBe('alveole')
        ->and(SaleItem::normaliserUnite('KG'))->toBe('kg')
        ->and(SaleItem::normaliserUnite('Tête'))->toBe('tete')
        ->and(SaleItem::normaliserUnite('Boite'))->toBe('unite');
});

test('300 œufs vendus à la pièce depuis le terrain sortent 10 alvéoles, pas 300', function () {
    $resultat = venteDuTerrain($this, $this->farm->id, ['quantity' => 300, 'unit' => 'Unité', 'unit_price' => 50]);
    expect($resultat['status'])->toBe('success');

    $vente = Sale::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($vente->items->first()->unit)->toBe('unite');

    $this->actingAs($this->managerUser);
    app(ValidateSale::class)->execute($vente);

    expect((float) $this->oeufs->fresh()->current_quantity)->toEqual(990.0);
});

test('« mobile_money » de l’écran de vente devient « orange_money »', function () {
    venteDuTerrain($this, $this->farm->id, [], ['immediate_payment' => 1000, 'payment_method' => 'mobile_money']);

    expect(Payment::withoutGlobalScopes()->latest('id')->value('method'))->toBe('orange_money');
});

test('un lot d’un autre site ou un type inconnu sont refusés, comme au bureau', function () {
    $autre = Farm::create(['code' => 'SITE-Z', 'name' => 'Site Z', 'is_active' => true]);
    $lotAilleurs = Batch::withoutEvents(fn () => Batch::factory()->create(['farm_id' => $autre->id]));

    expect(venteDuTerrain($this, $this->farm->id, ['product_type' => 'animal_vif', 'batch_id' => $lotAilleurs->id, 'unit' => 'Tête'])['status'])
        ->toBe('validation_failed')
        ->and(venteDuTerrain($this, $this->farm->id, ['product_type' => 'bijoux'])['status'])->toBe('validation_failed');
});
