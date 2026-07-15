<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->target = User::factory()->create([
        'name'      => 'Cible',
        'email'     => 'cible@ferme.gn',
        'role_id'   => $this->operatorUser->role_id,
        'is_active' => true,
    ]);
});

test('un admin modifie le nom, l\'email et le rôle d\'un utilisateur', function () {
    $managerRoleId = Role::where('name', 'manager')->value('id');

    $this->actingAs($this->adminUser)
        ->put(route('users.update', $this->target), [
            'name'    => 'Cible Modifiée',
            'email'   => 'nouveau@ferme.gn',
            'role_id' => $managerRoleId,
        ])
        ->assertSessionHas('success');

    $fresh = $this->target->fresh();
    expect($fresh->name)->toBe('Cible Modifiée')
        ->and($fresh->email)->toBe('nouveau@ferme.gn')
        ->and($fresh->role_id)->toBe($managerRoleId);
});

test('un admin suspend puis réactive un utilisateur', function () {
    $this->actingAs($this->adminUser)
        ->patch(route('users.toggle_active', $this->target))
        ->assertSessionHas('success');
    expect($this->target->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->adminUser)
        ->patch(route('users.toggle_active', $this->target))
        ->assertSessionHas('success');
    expect($this->target->fresh()->is_active)->toBeTrue();
});

test('un admin ne peut pas se suspendre lui-même', function () {
    $this->actingAs($this->adminUser)
        ->patch(route('users.toggle_active', $this->adminUser))
        ->assertSessionHas('error');

    expect($this->adminUser->fresh()->is_active)->toBeTrue();
});

test('un admin réinitialise le mot de passe d\'un utilisateur', function () {
    $this->actingAs($this->adminUser)
        ->put(route('users.reset_password', $this->target), [
            'password'              => 'NouveauPass123',
            'password_confirmation' => 'NouveauPass123',
        ])
        ->assertSessionHas('success');

    expect(Hash::check('NouveauPass123', $this->target->fresh()->password))->toBeTrue();
});

test('un non-admin ne peut ni modifier ni suspendre un utilisateur', function () {
    $this->actingAs($this->operatorUser)
        ->put(route('users.update', $this->target), [
            'name' => 'Hack', 'email' => 'hack@ferme.gn', 'role_id' => $this->target->role_id,
        ]);
    expect($this->target->fresh()->name)->toBe('Cible'); // inchangé

    $this->actingAs($this->operatorUser)->patch(route('users.toggle_active', $this->target));
    expect($this->target->fresh()->is_active)->toBeTrue(); // inchangé
});
