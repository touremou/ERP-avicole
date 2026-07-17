<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\TreasuryAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Édition / suppression d'un compte de trésorerie (correctif : ces opérations
 * n'existaient pas). Un compte est modifiable (tresorerie.M) et supprimable
 * (tresorerie.S) UNIQUEMENT s'il ne porte pas de mouvement.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function tresoAccount(string $name = 'Caisse', float $opening = 100000): TreasuryAccount
{
    return TreasuryAccount::create([
        'name' => $name, 'type' => 'caisse',
        'opening_balance' => $opening, 'current_balance' => $opening,
    ]);
}

test("l'admin peut modifier un compte (libellé, type, activation)", function () {
    $acc = tresoAccount('Caisse Principale');

    $this->actingAs($this->adminUser)
        ->put(route('treasury.account.update', $acc), ['name' => 'Caisse Guichet', 'type' => 'banque', 'is_active' => 0])
        ->assertSessionHas('success');

    $fresh = $acc->fresh();
    expect($fresh->name)->toBe('Caisse Guichet')
        ->and($fresh->type)->toBe('banque')
        ->and((bool) $fresh->is_active)->toBeFalse();
});

test("l'admin peut supprimer un compte SANS mouvement", function () {
    $acc = tresoAccount('Compte Vide');

    $this->actingAs($this->adminUser)
        ->delete(route('treasury.account.destroy', $acc))
        ->assertRedirect(route('treasury.index'));

    expect(TreasuryAccount::whereKey($acc->id)->exists())->toBeFalse();
});

test("un compte AVEC mouvement n'est PAS supprimable (intégrité historique)", function () {
    $acc = tresoAccount('Compte Actif');
    $this->actingAs($this->adminUser)->post(route('treasury.movement', $acc), [
        'direction' => 'in', 'amount' => 50000, 'date' => now()->toDateString(),
    ])->assertSessionHas('success');

    $this->actingAs($this->adminUser)
        ->delete(route('treasury.account.destroy', $acc))
        ->assertSessionHas('error');

    expect(TreasuryAccount::whereKey($acc->id)->exists())->toBeTrue();
});

test("un profil trésorerie en lecture seule ne peut NI modifier NI supprimer", function () {
    $role = Role::firstOrCreate(['name' => 'treso_ro'], ['label' => 'TR', 'display_name' => 'TR', 'permissions' => ['L']]);
    $modId = Module::where('slug', 'tresorerie')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $modId],
        ['can_read' => true, 'can_create' => false, 'can_modify' => false, 'can_delete' => false,
         'created_at' => now(), 'updated_at' => now()]
    );
    $lecteur = User::factory()->create(['role_id' => $role->id]);
    $acc = tresoAccount('Compte Lecture');

    $upd = $this->actingAs($lecteur)->put(route('treasury.account.update', $acc), ['name' => 'Piraté', 'type' => 'caisse']);
    expect($upd->status())->toBeIn([302, 403]);
    expect($acc->fresh()->name)->toBe('Compte Lecture');

    $del = $this->actingAs($lecteur)->delete(route('treasury.account.destroy', $acc));
    expect($del->status())->toBeIn([302, 403]);
    expect(TreasuryAccount::whereKey($acc->id)->exists())->toBeTrue();
});
