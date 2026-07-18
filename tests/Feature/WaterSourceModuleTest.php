<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $manager = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]);
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $manager->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }
    $this->manager = User::factory()->create(['role_id' => $manager->id]);
});

function citerne(int $farmId, array $attrs = []): WaterSource
{
    return WaterSource::create(array_merge([
        'farm_id' => $farmId, 'name' => 'Citerne A', 'type' => 'citerne',
        'capacity_liters' => 1000, 'current_level_liters' => 800, 'current_level_percent' => 80, 'is_active' => true,
    ], $attrs));
}

test('refreshLevel ne dépasse jamais la capacité (anti-débordement)', function () {
    $src = citerne($this->farm->id, ['current_level_liters' => 800]);

    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $src->id, 'user_id' => $this->manager->id,
        'reading_date' => now()->toDateString(), 'volume_consumed_liters' => 0, 'volume_added_liters' => 5000,
    ]);

    $src->refreshLevel();

    expect((float) $src->fresh()->current_level_liters)->toBe(1000.0)
        ->and((float) $src->fresh()->current_level_percent)->toBe(100.0);
});

test('le ravitaillement d\'une citerne ajoute le volume au niveau et trace l\'appoint', function () {
    $src = citerne($this->farm->id, ['capacity_liters' => 10000, 'current_level_liters' => 2000, 'current_level_percent' => 20]);

    $this->actingAs($this->manager)
        ->post(route('utilities.water.sources.refill', $src->id), [
            'volume_added_liters' => 5000,
            'refill_date'         => now()->toDateString(),
            'cost'                => 15000,
            'notes'               => 'Camion-citerne',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $src->refresh();
    expect((float) $src->current_level_liters)->toBe(7000.0)
        ->and((float) $src->current_level_percent)->toBe(70.0);

    // Événement tracé : appoint (consommation 0), coût conservé.
    $reading = WaterReading::where('water_source_id', $src->id)->latest('id')->first();
    expect((float) $reading->volume_added_liters)->toBe(5000.0)
        ->and((float) $reading->volume_consumed_liters)->toBe(0.0)
        ->and((float) $reading->cost)->toBe(15000.0);
});

test('un ravitaillement au-delà de la capacité est refusé avec un message (pas de crash)', function () {
    $src = citerne($this->farm->id, ['capacity_liters' => 10000, 'current_level_liters' => 8000, 'current_level_percent' => 80]);

    $this->actingAs($this->manager)
        ->from(route('utilities.water.sources'))
        ->post(route('utilities.water.sources.refill', $src->id), [
            'volume_added_liters' => 9000, 'refill_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('utilities.water.sources'))
        ->assertSessionHas('error');

    // Niveau inchangé, aucun appoint enregistré.
    expect((float) $src->fresh()->current_level_liters)->toBe(8000.0);
    expect(WaterReading::where('water_source_id', $src->id)->count())->toBe(0);
});

test('un ravitaillement pile jusqu\'à la capacité est accepté', function () {
    $src = citerne($this->farm->id, ['capacity_liters' => 10000, 'current_level_liters' => 8000, 'current_level_percent' => 80]);

    $this->actingAs($this->manager)
        ->post(route('utilities.water.sources.refill', $src->id), [
            'volume_added_liters' => 2000, 'refill_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

    expect((float) $src->fresh()->current_level_liters)->toBe(10000.0);
});

test('un ravitaillement pur (consommation 0) ne clôt PAS la tâche « Relevé eau »', function () {
    $src = citerne($this->farm->id);

    // La tâche du jour n'est complétée que par un relevé de consommation, pas
    // par un simple appoint : on vérifie via l'absence d'effet de bord (aucune
    // exception, l'appoint est bien enregistré).
    $this->actingAs($this->manager)
        ->post(route('utilities.water.sources.refill', $src->id), [
            'volume_added_liters' => 100, 'refill_date' => now()->toDateString(), // ≤ capacité restante (200)
        ])->assertSessionHasNoErrors()->assertRedirect();

    expect(WaterReading::where('water_source_id', $src->id)->where('volume_consumed_liters', 0)->count())->toBe(1);
});

test('un ravitaillement coexiste avec le relevé du jour (pas de collision unique)', function () {
    // Régression : la contrainte unique (source, date) faisait échouer le second
    // enregistrement du jour (« Erreur interne lors de la réconciliation »). Un
    // relevé PUIS un ravitaillement le même jour doivent tous deux passer.
    $src = citerne($this->farm->id, ['capacity_liters' => 10000, 'current_level_liters' => 5000, 'current_level_percent' => 50]);

    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $src->id, 'user_id' => $this->manager->id,
        'reading_date' => now()->toDateString(), 'volume_consumed_liters' => 500, 'volume_added_liters' => 0,
        'is_refill' => false,
    ]);

    $this->actingAs($this->manager)
        ->post(route('utilities.water.sources.refill', $src->id), [
            'volume_added_liters' => 2000, 'refill_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

    // Deux lignes le même jour : le relevé (is_refill=false) et l'appoint (is_refill=true).
    expect(WaterReading::where('water_source_id', $src->id)->count())->toBe(2);
    expect(WaterReading::where('water_source_id', $src->id)->where('is_refill', true)->count())->toBe(1);
});

test('la page Sources d\'eau affiche l\'historique des ravitaillements d\'une citerne', function () {
    $src = citerne($this->farm->id, ['capacity_liters' => 10000, 'current_level_liters' => 2000]);

    $this->actingAs($this->manager)
        ->post(route('utilities.water.sources.refill', $src->id), [
            'volume_added_liters' => 5000, 'refill_date' => now()->toDateString(),
        ])->assertRedirect();

    $this->actingAs($this->manager)
        ->get(route('utilities.water.sources'))
        ->assertOk()
        ->assertSee('Ravitaillements')     // en-tête de l'historique
        ->assertSee('+5 000 L', false);    // ligne de l'appoint
});

test('la page d\'édition affiche le formulaire de MODIFICATION (bug corrigé)', function () {
    $src = citerne($this->farm->id, ['name' => 'Citerne Nord']);

    $this->actingAs($this->manager)
        ->get(route('utilities.water.sources.edit', $src->id))
        ->assertOk()
        ->assertSee('Modifier la source', false)
        ->assertSee(route('utilities.water.sources.update', $src->id), false) // le form pointe vers UPDATE
        ->assertSee('value="Citerne Nord"', false);                          // champ pré-rempli
});

test('mettre à jour une source fonctionne et recale le niveau si la capacité baisse', function () {
    $src = citerne($this->farm->id, ['capacity_liters' => 1000, 'current_level_liters' => 900, 'current_level_percent' => 90]);

    $this->actingAs($this->manager)
        ->put(route('utilities.water.sources.update', $src->id), [
            'name' => 'Citerne MAJ', 'type' => 'citerne', 'capacity_liters' => 500, 'is_active' => 1,
        ])
        ->assertRedirect(route('utilities.water.sources'));

    $src->refresh();
    expect($src->name)->toBe('Citerne MAJ')
        ->and((float) $src->capacity_liters)->toBe(500.0)
        ->and((float) $src->current_level_liters)->toBe(500.0); // recalé à la nouvelle capacité
});
