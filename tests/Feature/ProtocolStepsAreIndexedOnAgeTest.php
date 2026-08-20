<?php

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\Protocol;
use App\Models\ProtocolStep;
use App\Services\SanitaryAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES ÉTAPES D'UN PROTOCOLE SONT DES ÂGES, ET NE VALENT QUE POUR CE QU'ON A GARDÉ.
 *
 * #293 laissait la question ouverte, faute de réponse dans le code : les
 * `day_number` d'un protocole sont-ils des ÂGES ou des jours depuis la mise en
 * place ? L'exploitation a tranché — ce sont des âges. C'est aussi la convention
 * de tous les programmes de vaccination avicoles : J1 Marek au couvoir,
 * J7 Newcastle, J14 Gumboro, J21 rappel.
 *
 * L'échéance d'une étape est donc `naissance + day_number`, et non plus
 * « arrivée » ni « mutation ».
 *
 * ─── MAIS BASCULER SEULEMENT L'ANCRAGE AURAIT ÉTÉ UN DÉGÂT ───
 *
 * `SanitaryAlertService` n'a AUCUNE fenêtre : toute étape dont la date est passée
 * et dont le produit n'est pas au registre devient une alerte, indéfiniment.
 *
 * Un lot acheté à 16 semaines se serait donc vu réclamer son J7 Newcastle, son
 * J14 Gumboro, son J21 — des actes faits chez l'éleveur PRÉCÉDENT, qui ne
 * figureront jamais dans notre registre. Des dizaines d'alertes impossibles à
 * solder, sur le canal sanitaire.
 *
 * ─── LA RÈGLE INDUSTRIELLE TIENT EN DEUX MOITIÉS ───
 *
 *   1. QUAND l'acte est dû : à un ÂGE — naissance + day_number ;
 *   2. À QUI il incombe : à nous seulement s'il tombe APRÈS l'arrivée du lot.
 *
 * Ce qui était dû avant que le lot n'entre à la ferme relevait de son détenteur
 * d'alors. On ne le réclame pas, et on ne prétend pas non plus l'avoir fait.
 *
 * C'est aussi ce qui explique les deux ancrages trouvés en #293 : l'un cherchait
 * à dire l'âge, l'autre notre responsabilité. Il fallait les deux, pas choisir.
 *
 * ─── CE QUE ÇA NE CHANGE PAS ───
 *
 * Pour un poussin d'un jour — le cas courant — naissance et arrivée coïncident :
 * toutes les étapes nous incombent, et les échéances sont celles d'avant, au jour
 * près.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->protocole = Protocol::create([
        'name' => 'Programme volaille standard',
        'type' => 'ponte',
    ]);

    foreach ([['J7 Newcastle', 7], ['J14 Gumboro', 14], ['J120 Rappel', 120]] as [$nom, $jour]) {
        ProtocolStep::create([
            'protocol_id'  => $this->protocole->id,
            'day_number'   => $jour,
            'action_name'  => $nom,
            'type'         => 'Vaccin',
        ]);
    }
});

/** Un lot rattaché au protocole, né il y a $age jours, arrivé il y a $depuis. */
function lotSousProtocole(int $farmId, int $buildingId, int $protocoleId, int $age, int $depuis): Batch
{
    return Batch::factory()->create([
        'farm_id'             => $farmId,
        'building_id'         => $buildingId,
        // Les deux : `protocol_id` est celui que lit SanitaryAlertService,
        // `current_protocol_id` celui du tableau de bord après mutation.
        'protocol_id'         => $protocoleId,
        'current_protocol_id' => $protocoleId,
        'birth_date'          => today()->subDays($age - 1)->toDateString(),
        'arrival_date'        => today()->subDays($depuis)->toDateString(),
        'status'              => 'Actif',
    ]);
}

test('l’échéance d’une étape se compte depuis la NAISSANCE', function () {
    /*
     * La réponse de l'exploitation, appliquée : J7 tombe au 7e jour de vie, pas
     * au 7e jour de présence.
     */
    // Né ET arrivé il y a 30 jours : le J7 tombe donc pendant notre garde, et
    // la question posée est bien celle de l'ancrage.
    $lot = lotSousProtocole($this->farm->id, $this->building->id, $this->protocole->id, 30, 30);

    expect($lot->protocolStepDue(7)?->toDateString())
        ->toBe($lot->birth_date->copy()->addDays(7)->toDateString())
        ->and($lot->protocolStepDue(7)?->toDateString())
        ->not->toBe($lot->arrival_date->copy()->addDays(7)->toDateString());
});

test('une étape DUE AVANT l’arrivée ne nous incombe pas', function () {
    /*
     * LA moitié qui évite le dégât. Un lot acheté à 16 semaines a reçu son J7
     * chez l'éleveur précédent : le réclamer serait une alerte impossible à
     * solder.
     */
    $poulettes = lotSousProtocole($this->farm->id, $this->building->id, $this->protocole->id, 112, 0);

    expect($poulettes->protocolStepDue(7))->toBeNull()
        ->and($poulettes->protocolStepDue(14))->toBeNull();
});

test('une étape à venir APRÈS l’arrivée nous incombe bien', function () {
    // La borne : on écarte le passé d'un autre, pas notre propre calendrier.
    $poulettes = lotSousProtocole($this->farm->id, $this->building->id, $this->protocole->id, 112, 0);

    expect($poulettes->protocolStepDue(120))->not->toBeNull();
});

test('l’ALERTE SANITAIRE ne réclame pas les vaccins d’un autre éleveur', function () {
    /*
     * De bout en bout, sur le service qui alerte. Sans la seconde moitié de la
     * règle, ce lot aurait généré une alerte par étape antérieure à son achat.
     */
    lotSousProtocole($this->farm->id, $this->building->id, $this->protocole->id, 112, 0);

    $alertes = app(SanitaryAlertService::class)->getActiveAlerts();

    expect(collect($alertes)->pluck('step_name'))
        ->not->toContain('J7 Newcastle')
        ->not->toContain('J14 Gumboro');
});

test('un POUSSIN D’UN JOUR garde exactement ses échéances d’avant', function () {
    /*
     * Le cas courant, et la borne de non-régression : naissance et arrivée
     * coïncident, donc toutes les étapes nous incombent et tombent aux mêmes
     * dates qu'avant cette correction.
     */
    $poussins = lotSousProtocole($this->farm->id, $this->building->id, $this->protocole->id, 1, 0);

    expect($poussins->protocolStepDue(7)?->toDateString())
        ->toBe(today()->addDays(7)->toDateString());
});

test('un acte DÉJÀ FAIT ne réapparaît pas', function () {
    // La garde qui existait déjà : le registre solde l'étape.
    $lot = lotSousProtocole($this->farm->id, $this->building->id, $this->protocole->id, 30, 30);

    HealthCheck::create([
        'farm_id'             => $this->farm->id,
        'batch_id'            => $lot->id,
        'type'                => 'Vaccin',
        'product_name'        => 'J7 Newcastle',
        'mode_administration' => 'Eau de boisson',
        'intervention_date'   => today()->subDays(20)->toDateString(),
        'cost'                => 50_000,
        'user_id'             => $this->adminUser->id,
    ]);

    $alertes = app(SanitaryAlertService::class)->getActiveAlerts();

    expect(collect($alertes)->pluck('step_name'))->not->toContain('J7 Newcastle');
});

test('SANS date de naissance, on retombe sur l’arrivée', function () {
    /*
     * Les lots antérieurs au champ portent naissance = arrivée (reprise de #292),
     * mais la garde vaut aussi pour une valeur restée nulle.
     */
    $lot = Batch::factory()->create([
        'farm_id'             => $this->farm->id,
        'building_id'         => $this->building->id,
        'current_protocol_id' => $this->protocole->id,
        'birth_date'          => null,
        'arrival_date'        => today()->subDays(10)->toDateString(),
        'status'              => 'Actif',
    ]);

    expect($lot->protocolStepDue(7)?->toDateString())
        ->toBe(today()->subDays(3)->toDateString());
});
