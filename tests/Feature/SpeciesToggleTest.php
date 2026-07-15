<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Species;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Garde de désactivation d'espèce : on ne masque pas des sélecteurs
 * (création de lot, normes, POS, abattoir) d'une espèce qui a encore des
 * lots EN PRODUCTION — même esprit que Plot::isOccupied côté cultures.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function speciesWithBatch(int $farmId, string $status): Species
{
    $species = Species::firstOrCreate(
        ['slug' => 'tilapia-toggle'],
        ['name_fr' => 'Tilapia', 'family' => 'aquaculture', 'is_active' => true]
    );
    $type = ProductionType::resolveOrCreate('aquaculture', $species->id);
    Batch::factory()->create([
        'farm_id'            => $farmId,
        'production_type_id' => $type->id,
        'status'             => $status,
    ]);

    return $species->fresh();
}

test('désactiver une espèce AVEC un lot actif est refusé (message + espèce inchangée)', function () {
    $species = speciesWithBatch($this->farm->id, 'Actif');

    $this->actingAs($this->adminUser)
        ->patch(route('admin.species.toggle', $species))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($species->fresh()->is_active)->toBeTrue(); // toujours active
});

test('désactiver une espèce sans lot actif (lots terminés) est autorisé', function () {
    $species = speciesWithBatch($this->farm->id, 'Terminé');

    $this->actingAs($this->adminUser)
        ->patch(route('admin.species.toggle', $species))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($species->fresh()->is_active)->toBeFalse();
});

test('une espèce déjà désactivée peut être RÉactivée même avec des lots (la garde ne vaut qu\'à la désactivation)', function () {
    $species = speciesWithBatch($this->farm->id, 'Actif');
    $species->update(['is_active' => false]);

    $this->actingAs($this->adminUser)
        ->patch(route('admin.species.toggle', $species))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($species->fresh()->is_active)->toBeTrue();
});

test('une espèce orpheline (0 lot) est supprimable, y compris ses types de production', function () {
    // Reliquat type « Aqua » : famille aquaculture, aucun lot, aucun type utile.
    $orphan = Species::create(['slug' => 'aqua', 'name_fr' => 'Aqua', 'family' => 'aquaculture', 'is_active' => true]);
    \App\Models\ProductionType::resolveOrCreate('grossissement', $orphan->id);

    $this->actingAs($this->adminUser)
        ->delete(route('admin.species.destroy', $orphan))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Species::whereKey($orphan->id)->exists())->toBeFalse()
        ->and(\App\Models\ProductionType::where('species_id', $orphan->id)->exists())->toBeFalse();
});

test('une espèce AVEC des lots ne peut PAS être supprimée (historique protégé)', function () {
    $species = speciesWithBatch($this->farm->id, 'Terminé'); // lot terminé = historique

    $this->actingAs($this->adminUser)
        ->delete(route('admin.species.destroy', $species))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Species::whereKey($species->id)->exists())->toBeTrue();
});
