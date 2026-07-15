<?php

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Storage::fake('public');
});

test('créer un article avec photo enregistre l\'article et stocke la photo', function () {
    $this->actingAs($this->adminUser)->post(route('products.store'), [
        'name'         => 'Œuf calibre L',
        'product_type' => 'oeufs',
        'unit'         => 'alveole',
        'base_price'   => 3000,
        'is_active'    => 1,
        'photo'        => UploadedFile::fake()->image('oeuf.jpg'),
    ])->assertRedirect(route('products.index'));

    $product = Product::where('name', 'Œuf calibre L')->first();

    expect($product)->not->toBeNull()
        ->and((float) $product->base_price)->toBe(3000.0)
        ->and($product->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($product->photo_path);
});

test('le catalogue s\'affiche avec les articles', function () {
    Product::create(['farm_id' => session('current_farm_id'), 'name' => 'Poulet 1.8kg', 'product_type' => 'volaille_abattue', 'unit' => 'piece', 'base_price' => 45000]);

    $this->actingAs($this->adminUser)
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee('Poulet 1.8kg');
});

test('modifier un article remplace sa photo et nettoie l\'ancienne', function () {
    $product = Product::create(['farm_id' => session('current_farm_id'), 'name' => 'X', 'product_type' => 'oeufs', 'unit' => 'alveole', 'base_price' => 1000, 'photo_path' => 'products/photos/old.jpg']);
    Storage::disk('public')->put('products/photos/old.jpg', 'x');

    $this->actingAs($this->adminUser)->put(route('products.update', $product), [
        'name' => 'X', 'product_type' => 'oeufs', 'unit' => 'alveole', 'base_price' => 1200, 'is_active' => 1,
        'photo' => UploadedFile::fake()->image('new.jpg'),
    ])->assertRedirect();

    $product->refresh();
    expect((float) $product->base_price)->toBe(1200.0)
        ->and($product->photo_path)->not->toBe('products/photos/old.jpg');
    Storage::disk('public')->assertMissing('products/photos/old.jpg');
});

test('un type de produit invalide est rejeté', function () {
    $this->actingAs($this->adminUser)->post(route('products.store'), [
        'name' => 'Bad', 'product_type' => 'inexistant', 'unit' => 'u', 'base_price' => 10,
    ])->assertSessionHasErrors('product_type');
});

test('un utilisateur en lecture seule ne peut pas créer d\'article', function () {
    $viewer = \App\Models\User::factory()->create([
        'role_id' => \App\Models\Role::where('name', 'viewer')->value('id'),
    ]);

    // Le middleware can: lève une AuthorizationException, convertie en
    // redirection par le handler global (cf. bootstrap/app.php).
    $this->actingAs($viewer)
        ->post(route('products.store'), ['name' => 'Z', 'product_type' => 'oeufs', 'unit' => 'u', 'base_price' => 1])
        ->assertRedirect();

    expect(Product::where('name', 'Z')->exists())->toBeFalse();
});
