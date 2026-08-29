<?php

use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Services\Accounting\PeriodCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MÊME EAU ÉTAIT COMPTÉE DEUX FOIS AU COMPTE DE RÉSULTAT.
 *
 * La ligne « Eau » sommait TOUTES les lignes de `water_readings.cost`, sans
 * distinguer deux natures que la table mélange :
 *
 *   • les RAVITAILLEMENTS (`is_refill`) portent le prix payé au camion-citerne
 *     — un achat, donc une charge ;
 *   • les RELEVÉS DE CONSOMMATION portent une valorisation calculée par
 *     `RecordWaterReading` : litres ÷ 1000 × `energie.water_price_m3` (paramètre
 *     semé à 5 000 GNF/m³, libellé « Prix eau SEEG »).
 *
 * Sur une citerne livrée par camion, les deux décrivent la MÊME eau : on
 * l'achète, puis on la consomme. Les additionner comptait la dépense deux fois.
 *
 * ─── LE DOUBLON QUE L'ÉNERGIE ÉVITAIT DÉJÀ ───
 *
 * La ligne « Énergie réseau (EDG) » exclut explicitement les groupes
 * électrogènes, dont le coût réel arrive par la ligne « Carburant ». Le même
 * raisonnement n'avait pas été appliqué à l'eau.
 *
 * ─── LA RÈGLE, ET ELLE EST STANDARD ───
 *
 * La charge naît de l'ACQUISITION. La valorisation d'une consommation interne
 * est une clef de répartition analytique — utile pour imputer l'eau à un
 * bâtiment ou à un lot (`Batch::utility_cost`) — jamais une seconde charge au
 * compte de résultat.
 *
 * On retient donc, PAR SOURCE :
 *   • livrée (citerne, camion) → ses appoints ;
 *   • facturée au compteur (réseau, forage) → ses relevés de consommation, car
 *     là le relevé EST la facture.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->debut = now()->startOfMonth();
    $this->fin   = now()->endOfMonth();
});

/** Une source d'eau du type voulu. */
function sourceEau(int $farmId, string $type, string $nom): WaterSource
{
    return WaterSource::create([
        'farm_id'         => $farmId,
        'name'            => $nom,
        'type'            => $type,
        'capacity_liters' => $type === 'citerne' ? 10_000 : null,
        'is_active'       => true,
    ]);
}

/** Une ligne de relevé — appoint ou consommation — au coût voulu. */
function ligneEau(int $farmId, WaterSource $source, float $cout, bool $appoint, int $userId): WaterReading
{
    return WaterReading::create([
        'farm_id'                => $farmId,
        'water_source_id'        => $source->id,
        'user_id'                => $userId,
        'reading_date'           => today()->toDateString(),
        'volume_consumed_liters' => $appoint ? 0 : 3_000,
        'volume_added_liters'    => $appoint ? 5_000 : 0,
        'is_refill'              => $appoint,
        'cost'                   => $cout,
    ]);
}

/** La ligne « Eau » du compte de résultat de la période. */
function chargeEau(\Carbon\Carbon $debut, \Carbon\Carbon $fin): float
{
    return (float) (PeriodCharges::between($debut, $fin)['Eau'] ?? 0);
}

test('une CITERNE livrée ne compte que ses ravitaillements', function () {
    /*
     * LE défaut : 500 000 payés au camion, puis la consommation de cette même
     * eau valorisée 15 000 au tarif du m³. La charge est 500 000, pas 515 000.
     */
    $citerne = sourceEau($this->farm->id, 'citerne', 'Citerne A');

    ligneEau($this->farm->id, $citerne, 500_000, appoint: true,  userId: $this->adminUser->id);
    ligneEau($this->farm->id, $citerne, 15_000,  appoint: false, userId: $this->adminUser->id);

    expect(chargeEau($this->debut, $this->fin))->toBe(500_000.0);
});

test('un RÉSEAU facturé au compteur compte sa consommation', function () {
    /*
     * La moitié symétrique, et elle compte autant : sur une source facturée au
     * compteur, personne ne livre l'eau — le relevé EST la facture. L'exclure
     * ferait disparaître une charge réelle.
     */
    $reseau = sourceEau($this->farm->id, 'seeg', 'Réseau SEEG');

    ligneEau($this->farm->id, $reseau, 120_000, appoint: false, userId: $this->adminUser->id);

    expect(chargeEau($this->debut, $this->fin))->toBe(120_000.0);
});

test('un FORAGE est traité comme une source facturée', function () {
    // Même règle : ce qui n'est pas livré se compte au relevé.
    $forage = sourceEau($this->farm->id, 'forage', 'Forage Nord');

    ligneEau($this->farm->id, $forage, 40_000, appoint: false, userId: $this->adminUser->id);

    expect(chargeEau($this->debut, $this->fin))->toBe(40_000.0);
});

test('les deux natures COEXISTENT sans se mélanger', function () {
    /*
     * Le cas réel d'une exploitation multi-sources : une citerne livrée et un
     * réseau au compteur. Chaque source apporte SA nature de charge.
     */
    $citerne = sourceEau($this->farm->id, 'citerne', 'Citerne B');
    $reseau  = sourceEau($this->farm->id, 'seeg', 'Réseau');

    ligneEau($this->farm->id, $citerne, 300_000, appoint: true,  userId: $this->adminUser->id);
    ligneEau($this->farm->id, $citerne, 9_000,   appoint: false, userId: $this->adminUser->id);   // ignoré
    ligneEau($this->farm->id, $reseau,  80_000,  appoint: false, userId: $this->adminUser->id);

    expect(chargeEau($this->debut, $this->fin))->toBe(380_000.0);
});

test('un appoint sur une source FACTURÉE ne compte pas deux fois non plus', function () {
    /*
     * La borne inverse : si quelqu'un enregistre un appoint sur le réseau — cas
     * douteux mais possible — c'est le relevé qui fait foi pour cette source.
     * Sans cette moitié de la règle, on aurait déplacé le doublon, pas supprimé.
     */
    $reseau = sourceEau($this->farm->id, 'seeg', 'Réseau');

    ligneEau($this->farm->id, $reseau, 200_000, appoint: true,  userId: $this->adminUser->id);
    ligneEau($this->farm->id, $reseau, 60_000,  appoint: false, userId: $this->adminUser->id);

    expect(chargeEau($this->debut, $this->fin))->toBe(60_000.0);
});

test('l’imputation au LOT reste fondée sur la consommation — non-régression', function () {
    /*
     * Ce correctif touche le COMPTE DE RÉSULTAT, pas la répartition analytique.
     * `Batch::utility_cost` continue d'imputer au bâtiment ce que ses relevés
     * disent : c'est une clef de répartition, et elle doit suivre l'usage réel.
     */
    $citerne = sourceEau($this->farm->id, 'citerne', 'Citerne C');

    $releve = ligneEau($this->farm->id, $citerne, 12_000, appoint: false, userId: $this->adminUser->id);
    $releve->forceFill(['building_id' => $this->building->id])->save();

    $lot = \App\Models\Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'arrival_date'     => today()->subDays(30)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);

    expect((float) $lot->fresh()->utility_cost)->toBe(12_000.0);
});
