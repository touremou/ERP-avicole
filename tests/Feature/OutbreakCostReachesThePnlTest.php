<?php

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\HealthIncident;
use App\Services\Accounting\PeriodCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MARGE DU LOT PAYAIT L'ÉPIDÉMIE, LE COMPTE DE RÉSULTAT NON.
 *
 * Le coût de traitement d'un incident sanitaire (`treatment_cost`, saisi au
 * diagnostic vétérinaire) était lu par TROIS endroits : la marge du lot, la
 * tuile du module santé, le rapport sanitaire. Pas par le compte de résultat, ni
 * par le tableau de bord.
 *
 * La ligne « Santé / prophylaxie » ne sommait que les actes du registre
 * (HealthCheck). Une épidémie traitée à 2 000 000 amputait donc la marge du lot
 * de 2 000 000 et le résultat de la ferme de zéro.
 *
 * ─── LA SOMME DES MARGES NE POUVAIT PAS TOMBER JUSTE ───
 *
 * C'est le défaut le plus insidieux de cette famille : deux chiffres également
 * affichés, également crédibles, qui ne se rejoignent jamais. Le promoteur, à
 * l'étranger, compare la marge de ses bandes au résultat de sa ferme et cherche
 * l'erreur dans les ventes.
 *
 * ─── AUCUN DOUBLE COMPTAGE, ET C'ÉTAIT DÉJÀ ÉCRIT ───
 *
 * Le modèle Batch le dit depuis toujours, en toutes lettres : « coûts de
 * traitement des INCIDENTS sanitaires (champ dédié, NON CAPTÉ AILLEURS → aucun
 * double comptage). Ferme la boucle financière incident → marge. »
 *
 * La boucle était fermée pour le lot. Elle restait ouverte pour la ferme.
 *
 * ─── LA MÊME BORNE DE PÉRIODE QUE LE RAPPORT SANITAIRE ───
 *
 * `incident_date`, comme le rapport des incidents — et non `diagnosed_at`. Deux
 * écrans qui chiffrent la même épidémie doivent la ranger dans le même mois.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id,
        'building_id' => $this->building->id,
    ]);
});

/** Déclare un incident sanitaire déjà diagnostiqué, avec son coût. */
function epidemie(int $farmId, Batch $lot, float $cout, ?string $date = null, ?int $userId = null): HealthIncident
{
    return HealthIncident::create([
        'farm_id'           => $farmId,
        'user_id'           => $userId,
        'building_id'       => $lot->building_id,
        'batch_id'          => $lot->id,
        'incident_date'     => $date ?? now()->toDateString(),
        'mortality_count'   => 40,
        'symptoms'          => 'Prostration, diarrhée verdâtre',
        'status'            => HealthIncident::STATUS_DIAGNOSED,
        'severity'          => HealthIncident::SEVERITY_CRITICAL,
        'suspected_disease' => 'Newcastle',
        'diagnosed_at'      => now(),
        'treatment_cost'    => $cout,
    ]);
}

test('le traitement d’une épidémie pèse sur le compte de résultat', function () {
    /*
     * LE défaut, chiffré : 2 000 000 de traitement qui n'apparaissaient dans
     * aucune charge de la ferme.
     */
    epidemie($this->farm->id, $this->lot, 2_000_000, null, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Santé / prophylaxie'])->toBe(2000000.0);
});

test('les actes du registre restent comptés, et s’additionnent', function () {
    /*
     * La borne principale : on AJOUTE une source, on n'en remplace pas une.
     * C'est exactement la composition que fait la marge du lot.
     */
    HealthCheck::create([
        'farm_id'            => $this->farm->id,
        'batch_id'           => $this->lot->id,
        'type'               => 'Vaccin',
        'product_name'       => 'Vaccin Newcastle',
        'mode_administration' => 'Nébulisation',
        'intervention_date'  => now()->toDateString(),
        'cost'               => 500_000,
        'user_id'            => $this->adminUser->id,
    ]);

    epidemie($this->farm->id, $this->lot, 2_000_000, null, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Santé / prophylaxie'])->toBe(2500000.0);
});

test('la marge du lot et le compte de résultat comptent la MÊME santé', function () {
    /*
     * L'enjeu réel : deux chiffres également affichés qui ne se rejoignaient
     * jamais. On mesure ici qu'ils portent désormais la même charge sanitaire.
     */
    HealthCheck::create([
        'farm_id'           => $this->farm->id,
        'batch_id'          => $this->lot->id,
        'type'              => 'Traitement',
        'product_name'      => 'Antibiotique',
        'mode_administration' => 'Eau de boisson',
        'intervention_date' => now()->toDateString(),
        'cost'              => 300_000,
        'user_id'           => $this->adminUser->id,
    ]);

    epidemie($this->farm->id, $this->lot, 2_000_000, null, $this->adminUser->id);

    $santeLot = (float) $this->lot->healthChecks()->sum('cost')
        + (float) $this->lot->healthIncidents()->sum('treatment_cost');

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Santé / prophylaxie'])->toBe($santeLot);
});

test('un incident SANS coût saisi ne change rien', function () {
    // Le coût est facultatif au diagnostic : un incident déclaré et non chiffré
    // ne doit pas inventer une charge.
    epidemie($this->farm->id, $this->lot, 0, null, $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Santé / prophylaxie'])->toBe(0.0);
});

test('un incident d’un AUTRE mois ne remonte pas', function () {
    /*
     * La borne de période, prise sur `incident_date` — la même que le rapport
     * sanitaire. Deux écrans qui chiffrent la même épidémie doivent la ranger
     * dans le même mois.
     */
    epidemie($this->farm->id, $this->lot, 2_000_000, now()->subMonth()->startOfMonth()->toDateString(), $this->adminUser->id);

    $charges = PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());

    expect($charges['Santé / prophylaxie'])->toBe(0.0);
});

test('le compte de résultat affiche la charge sanitaire complète', function () {
    // De bout en bout, sur l'écran que le promoteur ouvre.
    epidemie($this->farm->id, $this->lot, 2_000_000, null, $this->adminUser->id);

    $this->actingAs($this->adminUser);

    $pnl = $this->get(route('reports.profit_loss', [
        'date_from' => now()->startOfMonth()->toDateString(),
        'date_to'   => now()->endOfMonth()->toDateString(),
    ]))->assertOk();

    expect($pnl->viewData('costs')['Santé / prophylaxie'])->toBe(2000000.0);
});
