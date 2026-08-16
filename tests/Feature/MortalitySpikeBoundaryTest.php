<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Services\NotificationHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TÉLÉPHONE SONNAIT, L'ÉCRAN NE MONTRAIT RIEN.
 *
 * Le pic de mortalité du jour est évalué à DEUX endroits. Un audit précédent a
 * déjà mutualisé le taux et le seuil — les deux appellent
 * `Batch::dailyMortalityRate()` et `Batch::dailyMortalityThreshold()`, et les
 * commentaires le disent. Restait UN CARACTÈRE de différence :
 *
 *   • l'ALERTE (DailyCheck::checkDailyMortalitySpike) part dès que le seuil est
 *     ATTEINT — `if ($dailyRate < $seuil) { return; }` ;
 *   • le TABLEAU DE BORD ne listait le lot qu'AU-DESSUS — `$rate > $seuil`.
 *
 * À l'égalité exacte, l'alerte partait et le lot n'apparaissait pas dans les
 * urgences sanitaires.
 *
 * ─── LE CAS N'EST PAS THÉORIQUE ───
 *
 * Le taux est arrondi à deux décimales (`round(…, 2)`) et les effectifs sont des
 * nombres ronds. 10 morts sur 2 000 sujets font exactement 0,5 % — la valeur par
 * défaut du seuil. Un lot de 2 000, c'est un bâtiment ordinaire.
 *
 * ─── POURQUOI CELA COMPTE ───
 *
 * Le promoteur est à l'étranger : l'alerte sur son téléphone est le signal, le
 * tableau de bord est la vérification. Quand la vérification contredit le
 * signal, c'est le signal qu'on cesse de croire — et la prochaine alerte, la
 * vraie, se lira comme la précédente.
 *
 * L'alerte fait foi : `dailyMortalityThreshold()` est un SEUIL D'ALERTE, et un
 * seuil atteint est un seuil atteint.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->batiment = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B2', 'capacity' => 5000,
        'type' => 'poulailler', 'status' => 'occupe',
    ]);
});

/**
 * Un lot dont la mortalité du jour tombe EXACTEMENT sur son seuil.
 *
 * L'effectif est calculé à partir du seuil réel du lot (qui dépend de sa phase),
 * et non fixé en dur : un seuil de phase modifié en réglages ne doit pas rendre
 * ce test muet — il continuerait de passer sans plus rien mesurer.
 */
function lotPileAuSeuil(int $farmId, int $buildingId, int $morts = 10): array
{
    $lot = Batch::factory()->create([
        'farm_id' => $farmId, 'building_id' => $buildingId,
        'status' => Batch::STATUS_ACTIF,
        'arrival_date' => now()->subDays(40)->toDateString(),
    ]);

    $effectif = (int) round($morts / $lot->dailyMortalityThreshold() * 100);
    $lot->update(['initial_quantity' => $effectif, 'current_quantity' => $effectif]);
    $lot->refresh();

    $check = DailyCheck::create([
        'farm_id' => $farmId, 'batch_id' => $lot->id,
        'check_date' => now()->toDateString(),
        'mortality' => $morts,
    ]);

    return [$lot->fresh(), $check];
}

/** Les lots listés en « urgences sanitaires » par le tableau de bord. */
function urgencesSanitaires(): \Illuminate\Support\Collection
{
    return collect(test()->get(route('dashboard'))->assertOk()->viewData('emergencyBatches'));
}

test('le décor est bien à l’égalité exacte', function () {
    // Sans cette vérification, les tests suivants pourraient passer parce que
    // le taux est au-dessus du seuil — donc sans rien mesurer du défaut.
    [$lot, $check] = lotPileAuSeuil($this->farm->id, $this->batiment->id);

    expect($lot->dailyMortalityRate($check))->toBe($lot->dailyMortalityThreshold());
});

test('un lot PILE au seuil figure dans les urgences du tableau de bord', function () {
    // LE défaut : l'alerte partait, l'écran restait vide.
    [$lot] = lotPileAuSeuil($this->farm->id, $this->batiment->id);

    expect(urgencesSanitaires()->pluck('id'))->toContain($lot->id);
});

test('l’alerte et l’écran s’accordent au seuil exact', function () {
    /*
     * Le défaut énoncé tel qu'il se vivait : le signal et sa vérification se
     * contredisaient. On mesure les deux chemins, pas une comparaison isolée.
     */
    $alertes = [];
    $hub = Mockery::mock(NotificationHub::class)->makePartial();
    $hub->shouldReceive('alertDailyMortalitySpike')
        ->andReturnUsing(function ($batch) use (&$alertes) { $alertes[] = $batch->id; });
    app()->instance(NotificationHub::class, $hub);

    [$lot] = lotPileAuSeuil($this->farm->id, $this->batiment->id);

    expect($alertes)->toContain($lot->id)
        ->and(urgencesSanitaires()->pluck('id'))->toContain($lot->id);
});

test('un lot SOUS le seuil reste hors des urgences', function () {
    // La borne : on aligne les deux lectures, on n'élargit pas l'alerte.
    $lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->batiment->id,
        'status' => Batch::STATUS_ACTIF,
        'arrival_date' => now()->subDays(40)->toDateString(),
    ]);

    // Effectif doublé pour le même nombre de morts : taux = moitié du seuil.
    $effectif = (int) round(10 / $lot->dailyMortalityThreshold() * 100) * 2;
    $lot->update(['initial_quantity' => $effectif, 'current_quantity' => $effectif]);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $lot->id,
        'check_date' => now()->toDateString(), 'mortality' => 10,
    ]);

    expect(urgencesSanitaires()->pluck('id'))->not->toContain($lot->id);
});

test('le plancher en nombre de morts continue de filtrer le bruit', function () {
    /*
     * L'autre borne, celle que le plancher protège : sur un petit lot, un décès
     * isolé dépasse mécaniquement le seuil en pourcentage sans constituer un
     * pic. Aligner la comparaison ne doit pas rouvrir ce bruit-là.
     */
    $lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->batiment->id,
        'status' => Batch::STATUS_ACTIF,
        'initial_quantity' => 100, 'current_quantity' => 100,
        'arrival_date' => now()->subDays(40)->toDateString(),
    ]);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $lot->id,
        'check_date' => now()->toDateString(), 'mortality' => 1,
    ]);

    expect(urgencesSanitaires()->pluck('id'))->not->toContain($lot->id);
});
