<?php

use App\Actions\Utility\RecordEnergyReading;
use App\Actions\Utility\RecordWaterReading;
use App\Models\EnergySource;
use App\Models\WaterSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CORRIGER UN RELEVÉ RECOMPTAIT SA CONSOMMATION ENTIÈRE.
 *
 * Les deux actions de relevé écrivent leur ligne par `updateOrCreate` — une
 * ligne par (source, jour), rejouable. Mais les COMPTEURS qu'elles pilotent
 * étaient, eux, ajoutés à neuf à chaque passage :
 *
 *   • `RecordEnergyReading` incrémentait `total_hours_run` du total du relevé,
 *     et décrémentait la cuve du carburant du relevé ;
 *   • `RecordWaterReading` appelait `refreshLevel()`, qui relisait le relevé du
 *     jour et en retirait la consommation ENTIÈRE du niveau de citerne.
 *
 * Rectifier une saisie — le geste le plus banal du terrain — comptait donc deux
 * fois. Mesuré : un relevé de 6 h corrigé à 8 h portait le compteur à 14 h ;
 * une consommation de 500 L corrigée à 600 vidait la citerne de 1 100 L.
 *
 * ─── CE QUE ÇA FAUSSE ───
 *
 * Rien de décoratif. `total_hours_run` commande l'échéance de vidange, donc la
 * bascule automatique du groupe en statut « maintenance » : le groupe partait
 * en entretien avant l'heure. Le niveau de cuve commande l'alerte gasoil et
 * l'autonomie : l'alerte « carburant bas » se déclenchait sur une cuve pleine.
 *
 * ─── LA RÈGLE ÉTAIT DÉJÀ ÉCRITE ───
 *
 * `SyncManureCollection` et `SyncWaterConsumption` appliquent le DELTA, et le
 * disent : « une rectification ou une suppression réajuste le niveau sans
 * jamais double-compter ». L'en-tête de `RecordEnergyReading` annonçait même sa
 * propre rejouabilité. Elle valait pour la ligne, pas pour les compteurs.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->groupe = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Perkins', 'type' => 'groupe',
        'status' => 'operationnel', 'total_hours_run' => 100,
        'fuel_tank_capacity' => 500, 'current_fuel_level' => 400,
        'maintenance_interval_hours' => 250,
    ]);

    $this->citerne = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Citerne A', 'type' => 'citerne',
        'capacity_liters' => 10_000, 'current_level_liters' => 8_000,
        'current_level_percent' => 80, 'is_active' => true,
    ]);
});

/** Le relevé d'énergie du jour, réécrit autant de fois qu'on le corrige. */
function saisieEnergieDuJour(int $sourceId, float $heures, ?float $gasoil = null): void
{
    app(RecordEnergyReading::class)->execute(array_filter([
        'energy_source_id'     => $sourceId,
        'reading_date'         => today()->toDateString(),
        'hours_run'            => $heures,
        'fuel_consumed_liters' => $gasoil,
    ], fn ($v) => $v !== null), auth()->id());
}

/** Le relevé d'eau du jour, réécrit autant de fois qu'on le corrige. */
function saisieEauDuJour(int $sourceId, float $litres): void
{
    app(RecordWaterReading::class)->execute([
        'water_source_id'        => $sourceId,
        'reading_date'           => today()->toDateString(),
        'volume_consumed_liters' => $litres,
    ], auth()->id());
}

test('corriger les HEURES ne les ajoute pas une seconde fois', function () {
    /*
     * LE défaut, sur le compteur qui commande la vidange. 100 h au compteur,
     * un relevé de 6 h corrigé à 8 : le compteur doit marquer 108, pas 114.
     */
    saisieEnergieDuJour($this->groupe->id, 6);
    expect((float) $this->groupe->fresh()->total_hours_run)->toBe(106.0);

    saisieEnergieDuJour($this->groupe->id, 8);
    expect((float) $this->groupe->fresh()->total_hours_run)->toBe(108.0);
});

test('corriger les heures À LA BAISSE rend les heures au compteur', function () {
    // La correction dans l'autre sens : 8 h ramenées à 3 doivent redescendre.
    saisieEnergieDuJour($this->groupe->id, 8);
    saisieEnergieDuJour($this->groupe->id, 3);

    expect((float) $this->groupe->fresh()->total_hours_run)->toBe(103.0);
});

test('corriger le CARBURANT ne vide pas la cuve deux fois', function () {
    /*
     * Le niveau de cuve commande l'alerte gasoil et l'autonomie. 400 L en cuve,
     * un relevé de 50 L corrigé à 60 : il doit rester 340 L, pas 290.
     */
    saisieEnergieDuJour($this->groupe->id, 6, 50);
    expect((float) $this->groupe->fresh()->current_fuel_level)->toBe(350.0);

    saisieEnergieDuJour($this->groupe->id, 6, 60);
    expect((float) $this->groupe->fresh()->current_fuel_level)->toBe(340.0);
});

test('corriger le carburant à la baisse REND le gasoil à la cuve', function () {
    saisieEnergieDuJour($this->groupe->id, 6, 60);
    saisieEnergieDuJour($this->groupe->id, 6, 10);

    expect((float) $this->groupe->fresh()->current_fuel_level)->toBe(390.0);
});

test('corriger un relevé d’EAU ne vide pas la citerne deux fois', function () {
    /*
     * Le même défaut sur l'eau : 8 000 L en citerne, 500 consommés puis
     * corrigés à 600 — il doit rester 7 400 L, pas 6 900.
     */
    saisieEauDuJour($this->citerne->id, 500);
    expect((float) $this->citerne->fresh()->current_level_liters)->toBe(7_500.0);

    saisieEauDuJour($this->citerne->id, 600);
    expect((float) $this->citerne->fresh()->current_level_liters)->toBe(7_400.0);
});

test('le PREMIER relevé compte normalement — non-régression', function () {
    /*
     * La borne : le cas courant, une saisie unique et juste, ne doit pas
     * bouger. C'est lui qui distingue une correction d'une amputation.
     */
    saisieEnergieDuJour($this->groupe->id, 7, 40);
    saisieEauDuJour($this->citerne->id, 300);

    expect((float) $this->groupe->fresh()->total_hours_run)->toBe(107.0)
        ->and((float) $this->groupe->fresh()->current_fuel_level)->toBe(360.0)
        ->and((float) $this->citerne->fresh()->current_level_liters)->toBe(7_700.0);
});

test('deux relevés de JOURS DIFFÉRENTS s’additionnent bien', function () {
    /*
     * L'autre borne, et elle compte autant : le delta ne doit pas transformer
     * deux journées distinctes en une seule. Ce sont deux lignes, deux
     * consommations.
     */
    app(RecordEnergyReading::class)->execute([
        'energy_source_id' => $this->groupe->id,
        'reading_date'     => today()->subDay()->toDateString(),
        'hours_run'        => 5,
    ], auth()->id());

    saisieEnergieDuJour($this->groupe->id, 6);

    expect((float) $this->groupe->fresh()->total_hours_run)->toBe(111.0);
});
