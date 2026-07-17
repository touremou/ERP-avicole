<?php

use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Gestion du profil depuis « Mon espace » (mobile) : mise à jour des
 * coordonnées / langue et changement de mot de passe (avec vérification de
 * l'ancien), le tout borné à SON propre compte.
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-PRO'], ['name' => 'Ferme Profil', 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'vendeur_pro'], ['label' => 'Vendeur', 'display_name' => 'Vendeur', 'permissions' => ['L']]);
    $this->user = User::factory()->create([
        'role_id' => $role->id, 'name' => 'Ancien Nom', 'email' => 'ancien@example.com',
        'whatsapp_phone' => '620000000', 'locale' => 'fr', 'password' => Hash::make('secret-actuel1'),
    ]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
});

test('PATCH /auth/profile met à jour nom, e-mail, téléphone et langue', function () {
    Sanctum::actingAs($this->user);

    $json = $this->patchJson('/api/v1/auth/profile', [
        'name' => 'Nouveau Nom', 'email' => 'nouveau@example.com',
        'phone' => '621111111', 'locale' => 'en',
    ])->assertOk()->json('user');

    expect($json['name'])->toBe('Nouveau Nom')
        ->and($json['email'])->toBe('nouveau@example.com')
        ->and($json['phone'])->toBe('621111111')
        ->and($json['locale'])->toBe('en');

    $this->user->refresh();
    expect($this->user->name)->toBe('Nouveau Nom')
        ->and($this->user->whatsapp_phone)->toBe('621111111')
        ->and($this->user->locale)->toBe('en');
});

test("PATCH /auth/profile refuse un e-mail déjà pris par un autre compte", function () {
    User::factory()->create(['email' => 'occupe@example.com']);
    Sanctum::actingAs($this->user);

    $this->patchJson('/api/v1/auth/profile', [
        'name' => 'X', 'email' => 'occupe@example.com',
    ])->assertStatus(422);
});

test("PATCH /auth/profile accepte de conserver son propre e-mail", function () {
    Sanctum::actingAs($this->user);

    $this->patchJson('/api/v1/auth/profile', [
        'name' => 'Toujours Moi', 'email' => 'ancien@example.com',
    ])->assertOk();

    expect($this->user->fresh()->name)->toBe('Toujours Moi');
});

test('PATCH /auth/password change le mot de passe avec le bon mot de passe actuel', function () {
    Sanctum::actingAs($this->user);

    $this->patchJson('/api/v1/auth/password', [
        'current_password' => 'secret-actuel1',
        'password' => 'nouveau-pass1', 'password_confirmation' => 'nouveau-pass1',
    ])->assertOk();

    expect(Hash::check('nouveau-pass1', $this->user->fresh()->password))->toBeTrue();
});

test('PATCH /auth/password refuse un mauvais mot de passe actuel', function () {
    Sanctum::actingAs($this->user);

    $this->patchJson('/api/v1/auth/password', [
        'current_password' => 'faux',
        'password' => 'nouveau-pass1', 'password_confirmation' => 'nouveau-pass1',
    ])->assertStatus(422);

    expect(Hash::check('secret-actuel1', $this->user->fresh()->password))->toBeTrue();
});

test('PATCH /auth/password refuse un mot de passe faible ou non confirmé', function () {
    Sanctum::actingAs($this->user);

    // Trop court / sans chiffre.
    $this->patchJson('/api/v1/auth/password', [
        'current_password' => 'secret-actuel1',
        'password' => 'court', 'password_confirmation' => 'court',
    ])->assertStatus(422);

    // Confirmation qui ne correspond pas.
    $this->patchJson('/api/v1/auth/password', [
        'current_password' => 'secret-actuel1',
        'password' => 'nouveau-pass1', 'password_confirmation' => 'autre-pass1',
    ])->assertStatus(422);
});

test('POST /auth/avatar téléverse la photo, la remplace et nettoie l\'ancienne', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->user);

    $first = $this->postJson('/api/v1/auth/avatar', [
        'photo' => UploadedFile::fake()->image('moi.jpg', 300, 300),
    ])->assertOk()->json('user');

    expect($first['avatar_url'])->not->toBeNull();
    $oldPath = $this->user->fresh()->avatar_path;
    Storage::disk('public')->assertExists($oldPath);

    // Remplacement : nouvelle photo, l'ancienne est supprimée du disque.
    $this->postJson('/api/v1/auth/avatar', [
        'photo' => UploadedFile::fake()->image('moi2.jpg', 300, 300),
    ])->assertOk();

    $newPath = $this->user->fresh()->avatar_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($newPath);
});

test('POST /auth/avatar refuse un fichier non-image', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/auth/avatar', [
        'photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertStatus(422);
});

test('DELETE /auth/avatar retire la photo et le fichier', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/auth/avatar', [
        'photo' => UploadedFile::fake()->image('moi.jpg', 300, 300),
    ])->assertOk();
    $path = $this->user->fresh()->avatar_path;

    $json = $this->deleteJson('/api/v1/auth/avatar')->assertOk()->json('user');

    expect($json['avatar_url'])->toBeNull()
        ->and($this->user->fresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});
