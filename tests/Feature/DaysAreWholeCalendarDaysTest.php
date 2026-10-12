<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Protocol;
use App\Models\ProtocolStep;
use App\Support\Jours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « 0.8118930709375J ».
 *
 * Signalé par l'exploitation, capture à l'appui : l'en-tête de clôture d'un lot
 * arrivé le jour même annonçait « BATIMENT-M-2000 | REPRODUCTEUR |
 * 0.8118930709375J ». Depuis Carbon 3, `diffInDays()` rend un DÉCIMAL — mesuré
 * contre `now()`, il porte l'heure du jour (19 h 29 = 0,81 jour) — et il est
 * SIGNÉ.
 *
 * Le même défaut, ailleurs, ne se voyait pas, il faisait taire l'écran :
 *   • le pointage du jour calculait l'âge « 1.81… » : jamais égal au J entier
 *     d'une étape de protocole, la bannière « Soin planifié » n'apparaissait
 *     JAMAIS ;
 *   • « dernier poids il y a N jours » se mesurait à rebours : négatif, le
 *     bloc était masqué ;
 *   • le planning annonçait « commande dépassée de 3.42 jours ».
 *
 * Et l'historique de la fiche comptait ses « Jour N » depuis l'ARRIVÉE quand
 * l'en-tête comptait depuis la naissance.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    // L'heure de la capture : c'est elle qui faisait le décimal.
    Carbon::setTestNow(Carbon::today()->setTime(19, 29, 22));
});

afterEach(fn () => Carbon::setTestNow());

test('Jours::entre rend un entier de calendrier, signé', function () {
    expect(Jours::entre(today()))->toBe(0)
        ->and(Jours::entre(today()->subDays(3)))->toBe(3)
        ->and(Jours::entre(today(), today()->subDays(2)))->toBe(-2)
        ->and(Jours::entre('2026-01-01 23:59', '2026-01-02 00:01'))->toBe(1);
});

test('la CLÔTURE annonce une durée entière — pas « 0.81J »', function () {
    $lot = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->toDateString(),
        'birth_date'   => today()->toDateString(),
        'status'       => 'Actif',
    ]);

    $page = $this->get(route('batches.close_form', $lot))->assertOk()->getContent();

    expect($page)->toContain('| 0j')
        ->and($page)->not->toMatch('/\| \d+\.\d+j/');
});

test('le POINTAGE du jour annonce le soin planifié du jour', function () {
    $protocole = Protocol::create(['name' => 'Programme chair', 'type' => 'chair']);
    ProtocolStep::create([
        'protocol_id' => $protocole->id, 'day_number' => 7,
        'action_name' => 'Newcastle HB1', 'type' => 'Vaccin',
    ]);

    // Né il y a 7 jours : l'étape J7 tombe aujourd'hui (Batch::protocolStepDue).
    $lot = Batch::factory()->create([
        'farm_id'             => $this->farm->id,
        'building_id'         => $this->building->id,
        'arrival_date'        => today()->subDays(7)->toDateString(),
        'birth_date'          => today()->subDays(7)->toDateString(),
        'protocol_id'         => $protocole->id,
        'current_protocol_id' => $protocole->id,
        'status'              => 'Actif',
    ]);

    $page = $this->get(route('daily-checks.create', ['batch_id' => $lot->id]))->assertOk()->getContent();

    expect($page)->toContain('Newcastle HB1');
});

test('« dernier poids il y a N jours » compte vers le passé, en entier', function () {
    // Le bloc ne s'affiche que pour une espèce suivie au GMQ (porc, ruminants…) ;
    // l'espèce d'un lot se déduit de son type de production.
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $typePorc = \App\Models\ProductionType::whereHas('species', fn ($q) => $q->where('slug', 'porc'))->firstOrFail();

    $lot = Batch::factory()->create([
        'production_type_id' => $typePorc->id,
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->subDays(20)->toDateString(),
        'status'       => 'Actif',
    ]);

    DailyCheck::factory()->create([
        'batch_id'   => $lot->id,
        'check_date' => today()->subDays(3)->toDateString(),
        'avg_weight' => 0.850,
    ]);

    $page = $this->get(route('daily-checks.create', ['batch_id' => $lot->id]))->assertOk()->getContent();

    expect($page)->toContain('il y a 3 jour(s)');
});

test('l’HISTORIQUE de la fiche compte ses jours depuis la naissance, comme l’en-tête', function () {
    // Poulettes reçues il y a 2 jours, à 16 semaines (112 jours).
    $lot = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->subDays(2)->toDateString(),
        'birth_date'   => today()->subDays(113)->toDateString(),
        'status'       => 'Actif',
    ]);

    // Premier pointage, le jour de l'arrivée : les sujets avaient 112 jours.
    expect($lot->ageLe(today()->subDays(2)))->toBe(112)
        ->and($lot->age)->toBe(114);
});
