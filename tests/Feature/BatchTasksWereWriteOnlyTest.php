<?php

use App\Models\Batch;
use App\Models\Protocol;
use App\Models\ProtocolStep;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE TABLE ÉCRITE À CHAQUE LOT, LUE PAR PERSONNE.
 *
 * `batch_tasks` était remplie par SanitarySchedulerService à chaque création et
 * à chaque transfert. Rien ne l'affichait — aucun écran, aucune route, aucune
 * API, rien côté mobile. Sa seule autre mention était DependencyGuard, où ces
 * lignes invisibles BLOQUAIENT la suppression d'un lot sous le libellé
 * « tâches de lot » : un motif que personne ne pouvait aller consulter.
 *
 * ─── ET UNE TROISIÈME FAÇON DE DATER LA MÊME ÉTAPE ───
 *
 * Le planificateur datait chaque étape à
 * `transfer_date ?? arrival_date ?? created_at + day_number`, quand le tableau
 * de bord et l'alerte sanitaire passent tous deux par `protocolStepDue()`
 * (naissance + day_number, et seulement si l'étape nous incombe — #294).
 *
 * Une même règle écrite deux fois de deux façons, dont l'une n'avait aucun
 * lecteur : c'est le doublon muet qu'on retire, pas la fonction.
 *
 * ─── CE QUI RESTE ───
 *
 * Les étapes de protocole en retard continuent d'être signalées, depuis la
 * déclaration unique. Ces tests le vérifient — c'est la seule chose qui compte
 * pour l'exploitation.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->protocole = Protocol::create(['name' => 'Programme ponte', 'type' => 'ponte']);

    ProtocolStep::create([
        'protocol_id' => $this->protocole->id,
        'day_number'  => 7,
        'action_name' => 'J7 Newcastle',
        'type'        => 'Vaccin',
    ]);
});

test('la table sans lecteur a bien disparu', function () {
    expect(Schema::hasTable('batch_tasks'))->toBeFalse();
});

test('créer un lot sous protocole fonctionne toujours', function () {
    /*
     * La borne de non-régression : le planificateur était appelé DANS la
     * transaction de création. Le retirer ne doit pas casser la mise en lot.
     */
    $this->actingAs($this->adminUser);

    $lot = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'protocol_id'  => $this->protocole->id,
        'arrival_date' => today()->subDays(30)->toDateString(),
        'birth_date'   => today()->subDays(30)->toDateString(),
        'status'       => 'Actif',
    ]);

    expect($lot->exists)->toBeTrue();
});

test('l’ALERTE SANITAIRE signale toujours l’étape en retard', function () {
    /*
     * CE QUI COMPTE pour l'exploitation : la vaccination oubliée reste
     * réclamée. C'est la fonction, et elle ne passait jamais par la table
     * supprimée.
     */
    Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'protocol_id'  => $this->protocole->id,
        'arrival_date' => today()->subDays(30)->toDateString(),
        'birth_date'   => today()->subDays(30)->toDateString(),
        'status'       => 'Actif',
    ]);

    $alertes = app(\App\Services\SanitaryAlertService::class)->getActiveAlerts();

    expect(collect($alertes)->pluck('step_name'))->toContain('J7 Newcastle');
});

test('l’échéance n’est plus calculée qu’à UN endroit', function () {
    /*
     * La garde contre le retour du doublon : plus aucun service ne redate une
     * étape de protocole chez lui.
     */
    expect(file_exists(base_path('app/Services/SanitarySchedulerService.php')))
        ->toBeFalse('Le planificateur muet ne doit pas revenir.');

    $alerte = file_get_contents(base_path('app/Services/SanitaryAlertService.php'));

    expect(str_contains($alerte, 'protocolStepDue('))->toBeTrue();
});
