<?php

use App\Actions\EggProduction\RecordEggCollection;
use App\Models\Batch;
use App\Models\Building;
use App\Models\EggProduction;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX LIRE-PUIS-ÉCRIRE QUE SEULE LA SYNCHRO SÉRIALISAIT.
 *
 * Même forme que le défaut mesuré sur la clôture d'ordre de production : la
 * synchro mobile pose `lockForUpdate()` avant le geste, le web appelle le même
 * code sans verrou. La règle métier est identique des deux côtés — seule la
 * sérialisation manquait.
 *
 *   • COLLECTE D'ŒUFS — `RecordEggCollection` cherche la collecte du jour puis
 *     l'enrichit ou la crée. Deux saisies simultanées n'en trouvaient aucune et
 *     en créaient DEUX, doublant la ponte du jour. L'index
 *     (batch_id, production_date) n'est pas UNIQUE : la base ne rattrape pas.
 *
 *   • RAVITAILLEMENT DE CITERNE — le niveau est lu, contrôlé contre la
 *     capacité, puis recalculé À PARTIR DE LA MÊME VALEUR LUE. Deux appoints
 *     simultanés partaient du même niveau et le second écrasait le premier :
 *     deux relevés enregistrés, un seul comptabilisé.
 *
 * ─── CE QUE CE TEST VÉRIFIE, ET CE QU'IL NE VÉRIFIE PAS ───
 *
 * Il vérifie la NON-RÉGRESSION : les deux chemins continuent de faire
 * exactement ce qu'ils faisaient, verrou compris, y compris leurs refus.
 *
 * Il NE reproduit PAS la course. Contrairement à la clôture d'ordre — dont la
 * garde lisait un objet chargé en mémoire, ce que deux instances suffisaient à
 * reproduire — ces deux gardes relisent la base à l'intérieur de leur
 * transaction. Seule une vraie concurrence les met en défaut, et le harnais de
 * test est mono-processus.
 *
 * C'est dit franchement plutôt que masqué derrière un test qui n'affirmerait que
 * la présence du verrou, c'est-à-dire l'implémentation et non le comportement.
 * En production (MySQL/InnoDB), la lecture verrouillante pose un verrou
 * d'intervalle qui bloque aussi l'INSERTION concurrente — c'est bien la création
 * en double qui est empêchée, pas seulement la modification.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('la collecte d’œufs enregistre toujours normalement', function () {
    $batiment = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'Pondoir 1', 'capacity' => 2000,
        'type' => 'ponte', 'status' => 'occupe',
    ]);

    $lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $batiment->id,
        'status' => Batch::STATUS_ACTIF,
        'initial_quantity' => 500, 'current_quantity' => 500,
        'arrival_date' => now()->subMonths(6)->toDateString(),
    ]);

    app(RecordEggCollection::class)->execute([
        'batch_id' => $lot->id,
        'production_date' => now()->toDateString(),
        'total_eggs_collected' => 400,
        'broken_eggs' => 5,
        'small_eggs' => 2,
    ]);

    expect(EggProduction::count())->toBe(1)
        ->and((int) EggProduction::first()->total_eggs_collected)->toBe(400);
});

test('un second passage le MÊME jour enrichit la collecte au lieu d’en créer une seconde', function () {
    /*
     * Le comportement que le verrou protège : plusieurs passages dans la
     * journée s'additionnent sur UNE collecte. C'est cette recherche-puis-écrit
     * que deux saisies simultanées contournaient.
     */
    $batiment = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'Pondoir 1', 'capacity' => 2000,
        'type' => 'ponte', 'status' => 'occupe',
    ]);

    $lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $batiment->id,
        'status' => Batch::STATUS_ACTIF,
        'initial_quantity' => 500, 'current_quantity' => 500,
        'arrival_date' => now()->subMonths(6)->toDateString(),
    ]);

    $payload = [
        'batch_id' => $lot->id,
        'production_date' => now()->toDateString(),
        'broken_eggs' => 0,
        'small_eggs' => 0,
    ];

    app(RecordEggCollection::class)->execute($payload + ['total_eggs_collected' => 300]);
    app(RecordEggCollection::class)->execute($payload + ['total_eggs_collected' => 150]);

    expect(EggProduction::count())->toBe(1);
});

test('le ravitaillement de citerne enregistre le relevé ET monte le niveau', function () {
    $citerne = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Citerne principale',
        'type' => 'citerne', 'capacity_liters' => 5_000,
        'current_level_liters' => 1_000, 'current_level_percent' => 20,
        'status' => 'actif',
    ]);

    $this->post(route('utilities.water.sources.refill', $citerne), [
        'volume_added_liters' => 3_000,
        'refill_date' => now()->toDateString(),
    ])->assertRedirect();

    expect(WaterReading::count())->toBe(1)
        ->and((float) $citerne->fresh()->current_level_liters)->toBe(4000.0);
});

test('le refus anti-débordement fonctionne toujours', function () {
    /*
     * La borne : le verrou enveloppe désormais le contrôle de capacité. Il ne
     * doit ni l'avaler ni le contourner — le refus doit sortir de la
     * transaction tel quel.
     */
    $citerne = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Citerne principale',
        'type' => 'citerne', 'capacity_liters' => 5_000,
        'current_level_liters' => 4_500, 'current_level_percent' => 90,
        'status' => 'actif',
    ]);

    $this->post(route('utilities.water.sources.refill', $citerne), [
        'volume_added_liters' => 3_000,
        'refill_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    expect(WaterReading::count())->toBe(0)
        ->and((float) $citerne->fresh()->current_level_liters)->toBe(4500.0);
});

test('deux ravitaillements SUCCESSIFS s’additionnent bien', function () {
    // Le cas séquentiel — celui qui arrive vraiment tous les jours — doit
    // rester exact : c'est la lecture du niveau à jour qui le garantit.
    $citerne = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Citerne principale',
        'type' => 'citerne', 'capacity_liters' => 5_000,
        'current_level_liters' => 0, 'current_level_percent' => 0,
        'status' => 'actif',
    ]);

    foreach ([1_000, 1_500] as $volume) {
        $this->post(route('utilities.water.sources.refill', $citerne), [
            'volume_added_liters' => $volume,
            'refill_date' => now()->toDateString(),
        ]);
    }

    expect(WaterReading::count())->toBe(2)
        ->and((float) $citerne->fresh()->current_level_liters)->toBe(2500.0);
});
