<?php

use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RETIRÉ D'UN SITE, ON EN SORT — À LA REQUÊTE SUIVANTE.
 *
 * Le site en session était revérifié à chaque requête… sur son EXISTENCE
 * (`Farm::isUsable`), jamais sur le RATTACHEMENT. Un compte basculé sur un site
 * dont un administrateur venait de le retirer continuait d'en lire et d'en
 * modifier les données jusqu'à l'expiration de sa session. Le terrain relit le
 * rattachement à chaque requête (SetApiFarmContext) ; le bureau suit la même
 * règle.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->kindia = $this->farm;
    $this->kerouane = Farm::create(['code' => 'KER', 'name' => 'Kérouané']);
    $this->agent = User::factory()->create(['role_id' => $this->adminUser->role_id]);

    foreach ([[$this->kindia->id, true], [$this->kerouane->id, false]] as [$farmId, $defaut]) {
        DB::table('farm_user')->insert([
            'farm_id' => $farmId, 'user_id' => $this->agent->id, 'is_default' => $defaut,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

test('retiré de Kérouané pendant qu’il y travaillait, l’agent en sort à la requête suivante', function () {
    $this->actingAs($this->agent)->withSession(['current_farm_id' => $this->kerouane->id]);

    // L'administrateur le retire de Kérouané.
    DB::table('farm_user')->where('user_id', $this->agent->id)->where('farm_id', $this->kerouane->id)->delete();

    $this->get(route('dashboard'))->assertOk();

    expect((int) session('current_farm_id'))->toBe($this->kindia->id);
});

test('toujours rattaché, il reste sur le site choisi — non-régression', function () {
    $this->actingAs($this->agent)->withSession(['current_farm_id' => $this->kerouane->id]);

    $this->get(route('dashboard'))->assertOk();

    expect((int) session('current_farm_id'))->toBe($this->kerouane->id);
});
