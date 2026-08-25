<?php

use App\Models\Batch;
use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA FACTURE D'UN BÂTIMENT ÉTAIT IMPUTÉE EN ENTIER À CHAQUE BANDE QUI L'OCCUPE.
 *
 * `Batch::utility_cost` sommait les relevés taggés sur le bâtiment, sans jamais
 * les partager. Deux bandes logées ensemble se voyaient donc imputer chacune la
 * TOTALITÉ de l'eau et de l'énergie : mesuré, une facture de 300 000 en devenait
 * 600 000 répartis.
 *
 * ─── C'EST MA PROPRE CORRECTION QUI L'A RENDU STRUCTURANT ───
 *
 * #300 a fait reposer la marge de clôture sur ce chiffre. L'ancien écran
 * divisait grossièrement par le nombre de lots actifs de la FERME — une
 * répartition fausse (elle ignorait quel bâtiment), mais une répartition tout de
 * même. En la remplaçant par les relevés du bon bâtiment, j'ai supprimé le seul
 * partage qui existait.
 *
 * ─── LA BASE DE RÉPARTITION ───
 *
 * Le prorata des EFFECTIFS — la mesure d'occupation que le bâtiment utilise
 * déjà (`Building::currentOccupation()` somme des têtes). Un poulailler de
 * 5 000 sujets ne consomme pas comme un de 500.
 *
 * On retient `initial_quantity` et non `current_quantity` : la clôture met ce
 * dernier à zéro, et un lot clos verrait sa part s'effondrer rétroactivement —
 * sa marge changerait après avoir été arrêtée.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un lot du bâtiment commun, d'effectif donné, présent depuis $depuis jours. */
function bandeLogee(int $farmId, int $buildingId, int $effectif, int $depuis = 30, ?string $cloture = null): Batch
{
    return Batch::factory()->create([
        'farm_id'          => $farmId,
        'building_id'      => $buildingId,
        'arrival_date'     => today()->subDays($depuis)->toDateString(),
        'closing_date'     => $cloture,
        'initial_quantity' => $effectif,
        'current_quantity' => $effectif,
        'status'           => $cloture ? 'Terminé' : 'Actif',
    ]);
}

/** Une facture d'énergie taggée sur le bâtiment, à la date voulue. */
function factureEnergie(int $farmId, int $buildingId, float $cout, int $userId, int $ilYA = 5): void
{
    $source = EnergySource::firstOrCreate(
        ['farm_id' => $farmId, 'name' => 'Groupe Perkins'],
        ['type' => 'groupe', 'status' => 'operationnel'],
    );

    EnergyReading::create([
        'farm_id'          => $farmId,
        'energy_source_id' => $source->id,
        'building_id'      => $buildingId,
        'reading_date'     => today()->subDays($ilYA)->toDateString(),
        'cost'             => $cout,
        'user_id'          => $userId,
    ]);
}

test('deux bandes se PARTAGENT la facture, au prorata des effectifs', function () {
    /*
     * LE défaut. 300 000 de facture, 4 000 et 1 000 sujets : 240 000 et 60 000,
     * et non 300 000 chacun.
     */
    $grande = bandeLogee($this->farm->id, $this->building->id, 4_000);
    $petite = bandeLogee($this->farm->id, $this->building->id, 1_000);

    factureEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    expect((float) $grande->fresh()->utility_cost)->toBe(240_000.0)
        ->and((float) $petite->fresh()->utility_cost)->toBe(60_000.0);
});

test('la somme des parts fait EXACTEMENT la facture', function () {
    /*
     * L'invariant : ni perte ni création. C'est lui qui distingue un partage
     * d'une simple réduction.
     */
    $a = bandeLogee($this->farm->id, $this->building->id, 3_000);
    $b = bandeLogee($this->farm->id, $this->building->id, 2_000);
    $c = bandeLogee($this->farm->id, $this->building->id, 1_000);

    factureEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    $somme = (float) $a->fresh()->utility_cost
        + (float) $b->fresh()->utility_cost
        + (float) $c->fresh()->utility_cost;

    expect(round($somme, 2))->toBe(300_000.0);
});

test('une bande SEULE dans son bâtiment paie tout — comme avant', function () {
    /*
     * La borne de non-régression. Le cas le plus courant ne doit pas bouger d'un
     * franc : une part de 1.
     */
    $seule = bandeLogee($this->farm->id, $this->building->id, 2_000);

    factureEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    expect((float) $seule->fresh()->utility_cost)->toBe(300_000.0);
});

test('une bande ARRIVÉE APRÈS le relevé n’en prend pas sa part', function () {
    /*
     * Le partage se fait jour par jour, entre les lots PRÉSENTS ce jour-là. Une
     * bande mise en place la semaine suivante n'a pas consommé cette énergie.
     */
    $presente = bandeLogee($this->farm->id, $this->building->id, 1_000, 30);
    $tardive  = bandeLogee($this->farm->id, $this->building->id, 1_000, 2);   // arrivée il y a 2 j

    factureEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id, 10); // il y a 10 j

    expect((float) $presente->fresh()->utility_cost)->toBe(300_000.0)
        ->and((float) $tardive->fresh()->utility_cost)->toBe(0.0);
});

test('une bande CLÔTURÉE garde la part qu’elle avait', function () {
    /*
     * Pourquoi `initial_quantity` et non `current_quantity` : la clôture met
     * l'effectif à zéro. Sur `current_quantity`, la part d'un lot clos
     * s'effondrerait rétroactivement — et sa marge, déjà arrêtée, changerait.
     */
    $close = bandeLogee($this->farm->id, $this->building->id, 4_000, 30, today()->toDateString());
    $autre = bandeLogee($this->farm->id, $this->building->id, 1_000, 30);

    // La clôture remet l'effectif vivant à zéro.
    $close->update(['current_quantity' => 0]);

    factureEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    expect((float) $close->fresh()->utility_cost)->toBe(240_000.0)
        ->and((float) $autre->fresh()->utility_cost)->toBe(60_000.0);
});

test('sans effectif déclaré, le partage se fait en parts ÉGALES', function () {
    /*
     * Le repli. Tout imputer au premier venu serait arbitraire ; ne rien
     * imputer ferait disparaître la charge.
     */
    $a = bandeLogee($this->farm->id, $this->building->id, 0);
    $b = bandeLogee($this->farm->id, $this->building->id, 0);

    factureEnergie($this->farm->id, $this->building->id, 300_000, $this->adminUser->id);

    expect((float) $a->fresh()->utility_cost)->toBe(150_000.0)
        ->and((float) $b->fresh()->utility_cost)->toBe(150_000.0);
});
