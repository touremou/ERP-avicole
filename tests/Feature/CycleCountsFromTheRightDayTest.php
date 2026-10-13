<?php

use App\Models\Batch;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEPUIS QUAND SE COMPTE LE CYCLE ?
 *
 * Deux conventions coexistaient sans être dites : la volaille en ÂGE
 * (poussin d'un jour), les mammifères et poissons en DURÉE depuis l'arrivée
 * (embouche 90 j, reproducteur 180 j, lactation…). Le code comptait tout
 * depuis la naissance :
 *   • un agneau né cinq mois avant son arrivée avait une fin « naissance +
 *     90 j » — déjà passée, ramenée au jour de l'arrivée : échu en arrivant ;
 *   • ses courbes (semaine 1 = 12 kg) le mettaient d'emblée en fin de courbe.
 *
 * Décision de l'exploitation : l'engraissement des ovins, caprins, porcs et
 * lapins passe en âge (courbes ré-exprimées, cycle = âge à la vente) ; le
 * reste se compte depuis l'arrivée. Une règle : Batch::debutDuCycle().
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);
});

function bandeAuCycle(string $espece, string $type, array $attributs): Batch
{
    $pt = ProductionType::where('slug', $type)->whereHas('species', fn ($q) => $q->where('slug', $espece))->firstOrFail();

    return Batch::factory()->create(array_merge([
        'production_type_id' => $pt->id, 'type' => $type, 'status' => 'Actif',
        'initial_quantity' => 20, 'current_quantity' => 20,
    ], $attributs));
}

test('un agneau d’embouche acheté à 5 mois : sa courbe et sa fin se lisent à son âge', function () {
    $agneau = bandeAuCycle('mouton', 'engraissement', [
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'model_name' => 'Mouton Djallonké',
        'arrival_date' => today()->subDays(10), 'birth_date' => today()->subDays(160),
    ]);

    expect($agneau->cycleCompteDepuisLaNaissance())->toBeTrue()
        ->and($agneau->semaineDuCycle())->toBe(23);

    // Entre 13 sem. (10 kg) et 26 sem. (18 kg) : ~16 kg — pas la fin de courbe.
    $cible = ProductionNorm::cibleA($agneau);
    expect($cible['weight'])->toBeGreaterThan(14000)->toBeLessThan(18000);

    // Fin : naissance + 364 j (âge à la vente), et non l'arrivée.
    expect($agneau->expected_end_date->toDateString())->toBe(today()->subDays(160)->addDays(364)->toDateString());
});

test('des béliers reproducteurs adultes : le cycle part de leur ARRIVÉE', function () {
    $beliers = bandeAuCycle('mouton', 'reproducteur', [
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'model_name' => 'Bélier Djallonké',
        'arrival_date' => today()->subDays(10), 'birth_date' => today()->subYears(2),
    ]);

    expect($beliers->cycleCompteDepuisLaNaissance())->toBeFalse()
        ->and($beliers->semaineDuCycle())->toBe(2)
        // Auparavant : naissance + 180 j, déjà passé → fin = arrivée (échu en arrivant).
        ->and($beliers->expected_end_date->toDateString())->toBe(today()->subDays(10)->addDays($beliers->cycleDays())->toDateString());
});

test('la volaille ne change pas : poussins d’un jour, cycle en âge', function () {
    $chair = bandeAuCycle('poulet', 'chair', [
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'arrival_date' => today()->subDays(14), 'birth_date' => today()->subDays(14),
    ]);

    expect($chair->cycleCompteDepuisLaNaissance())->toBeTrue()
        ->and($chair->semaineDuCycle())->toBe($chair->semaineDAge());
});

test('les courbes d’engraissement des mammifères sont en semaines d’ÂGE', function () {
    // Un agneau de 12 kg n'est pas « semaine 1 » ; un porcelet de 7 kg est sevré à 4 semaines.
    expect(ProductionNorm::where('model_name', 'Mouton Djallonké')->min('week_number'))->toBe(13)
        ->and((float) ProductionNorm::where('model_name', 'Porc Large White')->where('week_number', 4)->value('target_weight'))->toBe(7000.0);
});
