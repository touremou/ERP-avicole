<?php

use App\Models\Product;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Cohérence d'unité POS : un article adossé à un stock physique vend dans
 * l'unité de CE stock — sinon un article « unite » sur un stock en KG fait
 * vendre des pièces au prix du kilo (découpes transférées de l'abattoir).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);
    $this->kgStock = Stock::create([
        'item_name' => 'Cuisse de poulet nature', 'category' => Stock::CAT_PRODUITS_FINIS,
        'unit' => 'KG', 'current_quantity' => 0.4, 'alert_threshold' => 0, 'last_unit_price' => 30000,
    ]);
});

test("création : l'unité de l'article est FORCÉE à celle du stock lié", function () {
    $this->post(route("products.store"), [
        'name' => 'Cuisse de poulet nature', 'product_type' => 'carcasse',
        'stock_id' => $this->kgStock->id,
        'unit' => 'unite', // désaligné volontairement → doit être corrigé
        'base_price' => 30000,
    ])->assertRedirect(route('products.index'));

    expect(Product::where('name', 'Cuisse de poulet nature')->value('unit'))->toBe('KG');
});

test("édition : re-lier un stock réaligne l'unité", function () {
    $product = Product::create([
        'name' => 'Article libre', 'product_type' => 'autre',
        'unit' => 'unite', 'base_price' => 5000, 'is_active' => true,
    ]);
    expect($product->fresh()->unit)->toBe('unite'); // sans stock : unité libre

    $product->update(['stock_id' => $this->kgStock->id]);
    expect($product->fresh()->unit)->toBe('KG');
});

test('sans stock lié, l\'unité reste libre (articles non suivis)', function () {
    $product = Product::create([
        'name' => 'Prestation transport', 'product_type' => 'autre',
        'unit' => 'course', 'base_price' => 10000, 'is_active' => true,
    ]);

    expect($product->fresh()->unit)->toBe('course');
});

test("la migration de réalignement corrige les articles existants désalignés", function () {
    // Article désaligné inséré en direct (contourne le hook du modèle).
    \Illuminate\Support\Facades\DB::table('products')->insert([
        'name' => 'Ailes fumées', 'product_type' => 'carcasse',
        'stock_id' => $this->kgStock->id, 'unit' => 'unite', 'base_price' => 25000,
        'is_active' => 1, 'is_favorite' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    (new class extends \Illuminate\Database\Migrations\Migration {
        public function up(): void
        {
            $rows = \Illuminate\Support\Facades\DB::table('products')
                ->join('stocks', 'stocks.id', '=', 'products.stock_id')
                ->whereColumn('products.unit', '!=', 'stocks.unit')
                ->get(['products.id as product_id', 'stocks.unit as stock_unit']);
            foreach ($rows as $row) {
                \Illuminate\Support\Facades\DB::table('products')->where('id', $row->product_id)->update(['unit' => $row->stock_unit]);
            }
        }
    })->up();

    expect(\Illuminate\Support\Facades\DB::table('products')->where('name', 'Ailes fumées')->value('unit'))->toBe('KG');
});
