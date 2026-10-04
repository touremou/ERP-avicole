<?php

use App\Actions\Employee\ArchiveEmployee;
use App\Models\Employee;
use App\Models\User;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN AGENT PARTI N'A PLUS ACCÈS.
 *
 * Le dossier RH passait à « Parti », ou était archivé, pendant que le compte
 * de connexion lié restait ouvert : le bureau l'acceptait, et son téléphone
 * continuait de synchroniser ventes et sorties de stock. La suspension —
 * connexion fermée, appareils révoqués — suit désormais le départ, quel que
 * soit le chemin. Sauf pour le dernier administrateur : un dossier RH ne doit
 * pas rendre l'installation inadministrable.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->compte = User::factory()->create(['role_id' => $this->operatorUser->role_id, 'is_active' => true]);
    $this->compte->createToken('telephone-de-service');
    $this->agent = Employee::factory()->create(['user_id' => $this->compte->id, 'status' => 'Actif', 'farm_id' => $this->farm->id]);
});

test('passé à « Parti », l’agent perd l’accès — bureau et téléphone', function () {
    $this->agent->update(['status' => 'Parti']);

    expect($this->compte->fresh()->isActive())->toBeFalse()
        ->and($this->compte->tokens()->count())->toBe(0);
});

test('archivé, l’agent perd l’accès', function () {
    app(ArchiveEmployee::class)->execute($this->agent);

    expect($this->compte->fresh()->isActive())->toBeFalse()
        ->and($this->compte->tokens()->count())->toBe(0);
});

test('en congé, l’agent garde l’accès — non-régression', function () {
    $this->agent->update(['status' => 'Congé']);

    expect($this->compte->fresh()->isActive())->toBeTrue()
        ->and($this->compte->tokens()->count())->toBe(1);
});

test('le DERNIER administrateur n’est jamais coupé par un dossier RH — la borne', function () {
    User::where('id', '!=', $this->adminUser->id)
        ->where('role_id', $this->adminUser->role_id)->update(['is_active' => false]);
    $dossierDuPatron = Employee::factory()->create(['user_id' => $this->adminUser->id, 'status' => 'Actif', 'farm_id' => $this->farm->id]);

    $dossierDuPatron->update(['status' => 'Parti']);

    expect($this->adminUser->fresh()->isActive())->toBeTrue();
});
