<?php

use App\Models\MillMachine;
use App\Models\MillProduction;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE OP LANCÉE DEPUIS LE TERRAIN N'AVAIT AUCUNE MACHINE PRINCIPALE.
 *
 * Deux chemins créent un ordre de production de provenderie, et ils n'écrivaient pas
 * la même chose :
 *
 *   • le WEB (MillProductionController) fixe `machine_id = machine_ids[0]` ;
 *   • la SYNCHRO (SyncService) ne le renseignait pas du tout.
 *
 * Les écrans du bureau affichaient donc « Standard » à la place de la machine
 * réellement employée, pour toute OP venue du terrain — et la liste ne montrait
 * aucune machine. C'est la traçabilité de l'atelier qui se perdait, sur le chemin
 * même que les techniciens utilisent.
 *
 * ─── LE CODE MORT QUI ANNONÇAIT UNE INTENTION TROMPEUSE ───
 *
 * La clôture calculait une variable `$allMachines` — fusion de la machine principale
 * et des machines du pivot, dédoublonnée — puis la boucle d'usure l'IGNORAIT et
 * parcourait le pivot.
 *
 * Le réflexe serait d'« utiliser la variable ». Ce serait une régression : un élément
 * venu de `$production->machine` ne porte AUCUN pivot, donc pas de
 * `snapshot_capacity_per_hour` — la clôture planterait. La fusion était donc
 * inutilisable telle quelle, et c'est la boucle qui avait raison.
 *
 * La variable a été retirée, et la raison écrite sur place : la capacité FIGÉE au
 * lancement n'existe que sur le pivot, et c'est elle qui donne des heures justes même
 * si la machine a été reparamétrée depuis.
 *
 * L'enjeu n'est pas cosmétique : `total_hours_run` déclenche `needs_maintenance`,
 * donc la maintenance préventive (maintenance:check). Des heures non comptées, c'est
 * une machine qui casse sans avoir été révisée.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Machine de provenderie. Colonnes NON NULL renseignées : type, poultry_type… */
function millMachine(int $farmId): MillMachine
{
    return MillMachine::create([
        'farm_id' => $farmId,
        'name' => 'Broyeur A',
        'type' => 'Broyeur',
        'capacity_per_hour' => 500,
        'maintenance_interval_hours' => 100,
        'total_hours_run' => 0,
        'status' => 'Opérationnel',
    ]);
}

/** Formule de provenderie. */
function millFormula(int $farmId): \App\Models\Formula
{
    return \App\Models\Formula::create([
        'farm_id' => $farmId,
        'code' => 'F-TEST-1',
        'name' => 'Ponte Production',
        'target_type' => 'Ponte',
        'poultry_type' => 'Ponte',
        'total_batch_weight' => 1000,
        'is_locked' => false,
        'is_active' => true,
    ]);
}

test('l’usure lit la capacité FIGÉE au lancement, pas celle d’aujourd’hui', function () {
    // Le point de conception : la capacité du pivot est un instantané. Si la machine
    // est reparamétrée après le lancement, les heures de CETTE OP doivent rester
    // calculées sur la capacité qui était en vigueur.
    $this->machine = millMachine($this->farm->id);
    $this->formule = millFormula($this->farm->id);

    $op = MillProduction::create([
        'farm_id' => $this->farm->id,
        'batch_number' => 'OP-TEST-1',
        'formula_id' => $this->formule->id,
        'machine_id' => $this->machine->id,
        'quantity_produced' => 1000,
        'operator_id' => $this->adminUser->id,
        'status' => 'Planifié',
    ]);

    $op->machines()->attach([$this->machine->id => ['snapshot_capacity_per_hour' => 500]]);

    // La machine est reparamétrée APRÈS le lancement.
    $this->machine->update(['capacity_per_hour' => 1000]);

    expect((float) $op->machines->first()->pivot->snapshot_capacity_per_hour)->toBe(500.0);
});

test('la machine principale figure TOUJOURS dans le pivot', function () {
    // C'est ce qui rend la boucle d'usure correcte : lire le pivot seul ne perd
    // aucune machine, puisque la principale y est.
    $this->machine = millMachine($this->farm->id);
    $this->formule = millFormula($this->farm->id);

    $op = MillProduction::create([
        'farm_id' => $this->farm->id,
        'batch_number' => 'OP-TEST-2',
        'formula_id' => $this->formule->id,
        'machine_id' => $this->machine->id,
        'quantity_produced' => 1000,
        'operator_id' => $this->adminUser->id,
        'status' => 'Planifié',
    ]);

    $op->machines()->attach([$this->machine->id => ['snapshot_capacity_per_hour' => 500]]);

    expect($op->machines->pluck('id'))->toContain($op->machine_id);
});

test('une OP venue du TERRAIN porte sa machine principale', function () {
    /*
     * LE défaut. La synchro ne renseignait pas `machine_id` : les écrans du bureau
     * affichaient « Standard » pour toute OP lancée depuis la PWA.
     */
    $this->machine = millMachine($this->farm->id);
    $this->formule = millFormula($this->farm->id);

    $employe = \App\Models\Employee::factory()->create(['farm_id' => $this->farm->id]);

    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->operatorUser->id,
        'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->operatorUser);

    $reponse = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'type'    => 'mill_production.create',
        'payload' => [
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'formula_id'    => $this->formule->id,
            'machine_ids'   => [$this->machine->id],
            'nb_bags'       => 20,
            'supervisor_id' => $employe->id,
        ],
    ]]]);

    expect($reponse->json('results.0.status'))->toBe('success');

    $op = MillProduction::withoutGlobalScopes()->latest('id')->first();

    expect($op->machine_id)->toBe($this->machine->id)
        ->and($op->machines->pluck('id'))->toContain($this->machine->id);
});

test('la clôture ne calcule plus de variable qu’elle n’utilise pas', function () {
    // `$allMachines` était calculée puis ignorée. Le réflexe — « utiliser la
    // variable » — aurait fait planter la clôture : un élément venu de
    // `$production->machine` n'a pas de pivot, donc pas de capacité figée.
    // On retire les COMMENTAIRES avant de chercher : l'explication du défaut le
    // nomme forcément, et un test qui bute sur sa propre documentation est le même
    // piège que celui corrigé sur « AviSmart SARL » (#219).
    $source = file_get_contents(app_path('Actions/MillProduction/CompleteMillProduction.php'));
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);

    expect($code)->not->toContain('$allMachines');
});

test('le modèle ne porte plus deux déclarations de la même relation', function () {
    // Une version sans pivot subsistait en commentaire au-dessus de la vraie. Deux
    // déclarations dont une morte se lisent mal, et rien ne dit laquelle fait foi.
    $source = file_get_contents(app_path('Models/MillProduction.php'));

    expect(substr_count($source, 'function machines('))->toBe(1);
});

test('total_hours_run pilote bien la maintenance préventive', function () {
    // Ce qui donne son poids au reste : des heures non comptées, c'est une machine
    // qui casse sans avoir été révisée.
    $this->machine = millMachine($this->farm->id);

    $this->machine->update(['total_hours_run' => 99]);
    expect($this->machine->fresh()->needs_maintenance)->toBeFalse();

    $this->machine->update(['total_hours_run' => 101]);
    expect($this->machine->fresh()->needs_maintenance)->toBeTrue();
});
