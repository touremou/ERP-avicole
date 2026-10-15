<?php

use App\Models\Batch;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA CIBLE DE GMQ EST CELLE DE L'ANIMAL — ET LA MÊME SUR CHAQUE ÉCRAN.
 *
 * La fiche du lot prenait le réglage OVIN (120 g/j) pour toute espèce autre
 * que la chèvre : un porc à 750 g/j y était « au-dessus de la cible », un
 * lapin à 45 g/j en rouge, un taureau à l'entretien aussi. Le rapport GMQ
 * appliquait 150 / 80 g/j en dur pour tous : un agneau à 130 g/j, vert sur sa
 * fiche (cible 120), sortait orange au rapport. Une déclaration :
 * Batch::gmqCible, et une règle de couleur : Batch::niveauGmq.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);
});

/**
 * Un lot né il y a $ageJours jours, pesé il y a $ecartJours jours puis
 * aujourd'hui. Par défaut : semaine 16 aujourd'hui, semaine 10 à la 1re pesée.
 */
function lotPese(int $ferme, int $batiment, string $espece, string $type, ?string $souche, float $poidsDebutKg, float $gmqGrammes, int $ageJours = 111, int $ecartJours = 42): Batch
{
    $sp = Species::where('slug', $espece)->first();
    $naissance = today()->subDays($ageJours);
    $lot = Batch::factory()->create([
        'farm_id' => $ferme, 'building_id' => $batiment, 'status' => 'Actif',
        'species_id' => $sp->id, 'type' => $type, 'model_name' => $souche,
        'production_type_id' => ProductionType::where('slug', $type)->where('species_id', $sp->id)->value('id'),
        'birth_date' => $naissance->toDateString(), 'arrival_date' => $naissance->toDateString(),
        'initial_quantity' => 20, 'current_quantity' => 20, 'avg_weight_start' => 0,
    ]);
    foreach ([[$ecartJours, $poidsDebutKg], [0, $poidsDebutKg + $ecartJours * $gmqGrammes / 1000]] as [$ilYA, $kg]) {
        DB::table('daily_checks')->insert([
            'farm_id' => $ferme, 'batch_id' => $lot->id, 'check_date' => today()->subDays($ilYA)->toDateString(),
            'avg_weight' => $kg, 'mortality' => 0, 'feed_consumed' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $lot->fresh();
}

function ligneGmq(Tests\TestCase $test, Batch $lot): array
{
    return collect($test->get(route('reports.gmq'))->assertOk()->viewData('batchStats'))
        ->first(fn ($s) => $s['batch']->id === $lot->id);
}

test('un porc à l’engrais est jugé sur la courbe de sa souche, pas sur 120 g/j', function () {
    $porc = lotPese($this->farm->id, $this->building->id, 'porc', 'engraissement', 'Porc Large White', 30, 750);

    $courbe = ProductionNorm::where('model_name', 'Porc Large White')->get()->keyBy('week_number');
    $attendu = round(($courbe[16]->target_weight - $courbe[10]->target_weight) / 42);

    expect($porc->gmqCible())->toEqual($attendu)
        ->and(ligneGmq($this, $porc)['gmq_cible'])->toEqual($attendu)
        ->and(ligneGmq($this, $porc)['gmq_niveau'])->toBe('bon');

    $this->get(route('batches.show', $porc))->assertOk()
        ->assertSee(__('cible') . ' ' . number_format($attendu, 0) . 'g', false);
});

test('un lapin qui pousse normalement n’est plus en rouge', function () {
    // Semaines 5 → 8 de sa courbe (cycle de 11 semaines).
    $lapin = lotPese($this->farm->id, $this->building->id, 'lapin', 'engraissement', 'Lapin Néo-Zélandais', 1.0, 40, ageJours: 55, ecartJours: 21);

    expect($lapin->gmqCible())->toBeGreaterThan(20)->toBeLessThan(60)
        ->and(Batch::niveauGmq($lapin->gmq(), $lapin->gmqCible()))->not->toBe('faible')
        ->and(ligneGmq($this, $lapin)['gmq_niveau'])->not->toBe('faible');
});

test('l’agneau garde le réglage de la ferme, et le rapport dit la même chose que sa fiche', function () {
    $agneau = lotPese($this->farm->id, $this->building->id, 'mouton', 'engraissement', 'Mouton Djallonké', 20, 130);

    expect($agneau->gmqCible())->toEqual((float) setting('elevage.gmq_cible_ovin', 120))
        ->and(ligneGmq($this, $agneau)['gmq_niveau'])->toBe('bon');   // 150 g/j en dur : « moyen »
});

test('un reproducteur à l’entretien n’a pas de cible de croissance', function () {
    $taureau = lotPese($this->farm->id, $this->building->id, 'vache', 'reproducteur', "Taureau N'Dama", 400, 10);

    expect($taureau->gmqCible())->toBeNull()
        ->and(ligneGmq($this, $taureau)['gmq_niveau'])->toBeNull();
});
