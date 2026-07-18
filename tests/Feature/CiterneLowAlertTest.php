<?php

use App\Models\Farm;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Alerte automatique « citerne basse » : émise UNE fois au franchissement du
 * seuil (≥30% → <30%), reçue in-app (cloche) par les abonnés « énergie ».
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-CIT'], ['name' => 'Ferme Citerne', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $role = Role::firstOrCreate(['name' => 'gestion'], ['label' => 'Gestion', 'display_name' => 'Gestion', 'permissions' => ['L']]);
    $this->user = User::factory()->create(['role_id' => $role->id]);

    // Abonné aux alertes ressources/énergie, canal in-app (cloche).
    NotificationPreference::create([
        'user_id' => $this->user->id, 'is_active' => true,
        'channel_whatsapp' => false, 'channel_database' => true, 'channel_email' => false,
        'alert_energy' => true,
    ]);
});

function citerneAt(int $farmId, float $percent): WaterSource
{
    $capacity = 10000;

    return WaterSource::create([
        'farm_id' => $farmId, 'name' => 'Citerne Principale', 'type' => 'citerne',
        'capacity_liters' => $capacity, 'current_level_liters' => $capacity * $percent / 100,
        'current_level_percent' => $percent, 'is_active' => true,
    ]);
}

test('franchir le seuil de 30% émet une alerte in-app', function () {
    $src = citerneAt($this->farm->id, 35);
    expect($this->user->fresh()->notifications()->count())->toBe(0);

    // Descente sous 30% (ex. consommation).
    $src->update(['current_level_percent' => 25, 'current_level_liters' => 2500]);

    $notifs = $this->user->fresh()->notifications;
    expect($notifs->count())->toBe(1)
        ->and($notifs->first()->data['title'])->toContain('Citerne basse');
});

test('rester sous 30% ne ré-émet pas l\'alerte (une seule fois par descente)', function () {
    $src = citerneAt($this->farm->id, 25);

    // Déjà bas → baisse encore : pas de nouveau franchissement.
    $src->update(['current_level_percent' => 15, 'current_level_liters' => 1500]);

    expect($this->user->fresh()->notifications()->count())->toBe(0);
});

test('un ravitaillement au-dessus de 30% réarme l\'alerte suivante', function () {
    $src = citerneAt($this->farm->id, 25);
    $src->update(['current_level_percent' => 80, 'current_level_liters' => 8000]); // ravitaillé
    expect($this->user->fresh()->notifications()->count())->toBe(0);

    $src->update(['current_level_percent' => 20, 'current_level_liters' => 2000]); // re-descente
    expect($this->user->fresh()->notifications()->count())->toBe(1);
});
