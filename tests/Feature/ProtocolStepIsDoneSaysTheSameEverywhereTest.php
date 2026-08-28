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
 * « CETTE VACCINATION A-T-ELLE ÉTÉ FAITE ? » — TROIS ÉCRANS, TROIS RÉPONSES.
 *
 * La question se posait à trois endroits, chacun avec sa propre comparaison :
 *
 *   • SanitaryAlertService : minuscules, espaces SUPPRIMÉS, comparaison
 *     BIDIRECTIONNELLE (l'acte contient l'étape OU l'étape contient l'acte) ;
 *   • DashboardController : minuscules, espaces CONSERVÉS, sens unique — alors
 *     que son commentaire annonçait « réutilise EXACTEMENT la convention de la
 *     fiche lot » ;
 *   • la fiche lot : comme le tableau de bord.
 *
 * Sur la même donnée, ils ne disaient donc pas la même chose. Un acte saisi
 * « Newcastle HB1 » face à une étape « NewcastleHB1 » était FAIT pour le service
 * d'alertes et DÛ pour les deux écrans.
 *
 * ─── ET LA FICHE LOT PORTAIT ENCORE L'ANCIEN ANCRAGE ───
 *
 * Elle calculait son échéance depuis `transfer_date ?? start_date ??
 * arrival_date` — la règle d'AVANT #295. Les `day_number` sont des ÂGES : un lot
 * acheté à 16 semaines voyait son calendrier repartir de zéro à la réception, et
 * ses étapes J7/J14/J21 s'affichaient en rouge clignotant. Des actes faits chez
 * l'éleveur précédent, impossibles à solder.
 *
 * ─── LE SENS DE LA COMPARAISON N'EST PAS SYMÉTRIQUE ───
 *
 * On exige que l'acte CONTIENNE le nom de l'étape, jamais l'inverse. La
 * réciproque disait : un acte « Newcastle » solde une étape « Newcastle
 * Lasota » — un rappel réputé fait parce qu'une primo l'a précédé. Sur un
 * calendrier vaccinal, se tromper dans ce sens fait manquer une injection ; se
 * tromper dans l'autre fait une alerte de trop.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->protocole = Protocol::create([
        'farm_id' => $this->farm->id,
        'name'    => 'Protocole pondeuse',
        'type'    => 'ponte',
    ]);
});

/** Une étape du protocole, à l'âge voulu. */
function etapeDuProtocole(int $protocolId, int $jour, string $action): ProtocolStep
{
    return ProtocolStep::create([
        'protocol_id' => $protocolId,
        'day_number'  => $jour,
        'action_name' => $action,
        'type'        => 'Vaccin',
    ]);
}

/** Un lot rattaché au protocole, né et arrivé aux dates voulues. */
function lotSuiviParProtocole(int $farmId, int $buildingId, int $protocolId, int $ageJours, ?int $depuisArrivee = null): Batch
{
    return Batch::factory()->create([
        'farm_id'          => $farmId,
        'building_id'      => $buildingId,
        'protocol_id'      => $protocolId,
        'birth_date'       => today()->subDays($ageJours)->toDateString(),
        'arrival_date'     => today()->subDays($depuisArrivee ?? $ageJours)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);
}

/** Un acte sanitaire enregistré sur le lot. */
function acteSanitaireDuLot(int $farmId, Batch $lot, string $produit, int $userId): HealthCheck
{
    return HealthCheck::create([
        'farm_id'           => $farmId,
        'batch_id'          => $lot->id,
        'product_name'      => $produit,
        'intervention_date' => today()->toDateString(),
        'type'               => 'Vaccin',
        'mode_administration' => 'eau_de_boisson',
        'user_id'            => $userId,
    ]);
}

test('les ESPACES ne changent pas la réponse', function () {
    /*
     * LE défaut de divergence : « Newcastle HB1 » face à « NewcastleHB1 » était
     * fait pour un écran, dû pour l'autre.
     */
    $etape = etapeDuProtocole($this->protocole->id, 7, 'NewcastleHB1');
    $lot   = lotSuiviParProtocole($this->farm->id, $this->building->id, $this->protocole->id, 30);

    acteSanitaireDuLot($this->farm->id, $lot, 'Newcastle HB1', $this->adminUser->id);

    expect($lot->fresh()->protocolStepDone($etape))->toBeTrue();
});

test('un acte PLUS COURT que l’étape ne la solde PAS', function () {
    /*
     * LA garde qui compte, et c'est une garde sanitaire : une primo
     * « Newcastle » ne doit pas solder le rappel « Newcastle Lasota ». La
     * comparaison bidirectionnelle du service d'alertes le faisait.
     */
    $rappel = etapeDuProtocole($this->protocole->id, 21, 'Newcastle Lasota');
    $lot    = lotSuiviParProtocole($this->farm->id, $this->building->id, $this->protocole->id, 40);

    acteSanitaireDuLot($this->farm->id, $lot, 'Newcastle', $this->adminUser->id);

    expect($lot->fresh()->protocolStepDone($rappel))->toBeFalse();
});

test('un acte qui NOMME l’étape la solde', function () {
    // La borne inverse : « Vaccin Newcastle Lasota flacon 500d » solde bien
    // l'étape « Newcastle Lasota ».
    $etape = etapeDuProtocole($this->protocole->id, 21, 'Newcastle Lasota');
    $lot   = lotSuiviParProtocole($this->farm->id, $this->building->id, $this->protocole->id, 40);

    acteSanitaireDuLot($this->farm->id, $lot, 'Vaccin Newcastle Lasota flacon 500d', $this->adminUser->id);

    expect($lot->fresh()->protocolStepDone($etape))->toBeTrue();
});

test('le service d’alertes et le TABLEAU DE BORD s’accordent désormais', function () {
    /*
     * Les deux lecteurs, sur la même donnée, doivent conclure pareil. C'est
     * l'invariant que la déclaration unique installe.
     */
    etapeDuProtocole($this->protocole->id, 7, 'NewcastleHB1');
    $lot = lotSuiviParProtocole($this->farm->id, $this->building->id, $this->protocole->id, 30);

    acteSanitaireDuLot($this->farm->id, $lot, 'Newcastle HB1', $this->adminUser->id);

    // Le service ne remonte plus l'étape.
    $alertes = (new SanitaryAlertService())->getActiveAlerts();
    expect(collect($alertes)->where('batch_id', $lot->id))->toBeEmpty();

    // Et le tableau de bord non plus.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('vaccineAlerts', fn ($a) => collect($a)->where('batch.id', $lot->id)->isEmpty());
});

test('la FICHE LOT n’affiche plus les étapes antérieures à l’arrivée', function () {
    /*
     * L'ancrage d'avant #295 : un lot acheté à 16 semaines voyait ses J7/J14/J21
     * en rouge clignotant — des actes faits chez l'éleveur précédent.
     */
    etapeDuProtocole($this->protocole->id, 7, 'Marek');
    etapeDuProtocole($this->protocole->id, 140, 'Rappel Choléra');

    // Né il y a 120 jours, reçu il y a 8 jours : le J7 précède l'arrivée.
    $lot = lotSuiviParProtocole($this->farm->id, $this->building->id, $this->protocole->id, 120, 8);

    $this->get(route('batches.show', $lot))
        ->assertOk()
        ->assertDontSee('Marek')          // pas la nôtre
        ->assertSee('Rappel Choléra');    // celle-là si
});

