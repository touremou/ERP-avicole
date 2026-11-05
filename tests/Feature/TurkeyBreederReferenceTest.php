<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\EggProduction;
use App\Models\Incubation;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Services\EggAnalysisService;
use App\Services\ReferentielReproducteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RÉFÉRENTIEL DES DINDONS REPRODUCTEURS : LE RÉEL CONTRE LE GUIDE.
 *
 * Décision de l'exploitation : la semaine du guide porte le poids des mâles et
 * des femelles, la ponte HD, les œufs incubables, la fertilité, l'éclosion,
 * l'aliment, l'eau et la mortalité + réforme. La fiche du lot compare chaque
 * semaine au guide, et alerte si la ponte passe 5 points sous la courbe.
 *
 * Les valeurs ci-dessous sont celles d'un guide FICTIF, posé pour le test :
 * aucun guide de souche n'est livré, la ferme saisit ou importe le sien.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $type = ProductionType::where('slug', 'reproducteur')
        ->whereHas('species', fn ($q) => $q->where('slug', 'dinde'))->first();
    if (! $type) {
        $this->seed(Database\Seeders\SpeciesSeeder::class);
        $type = ProductionType::where('slug', 'reproducteur')
            ->whereHas('species', fn ($q) => $q->where('slug', 'dinde'))->firstOrFail();
    }

    // Guide fictif : semaines 30 à 34.
    foreach ([30 => 60, 31 => 62, 32 => 64, 33 => 65, 34 => 65] as $semaine => $ponte) {
        ProductionNorm::create([
            'species_id' => $type->species_id, 'batch_type' => 'reproducteur', 'model_name' => 'Dindon Guide Test',
            'week_number' => $semaine, 'phase_name' => 'Ponte',
            'target_weight' => 11000, 'target_laying_rate' => $ponte,
            'target_feed_daily' => 300, 'target_water_daily' => 600,
            'target_weight_male' => 20000, 'target_weight_female' => 9500,
            'target_hatching_egg_rate' => 95, 'target_fertility_rate' => 90,
            'target_hatchability_rate' => 85, 'target_mortality_weekly' => 0.30,
        ]);
    }

    // Semaine 33 du cycle : 230 jours depuis l'éclosion.
    $this->dindons = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'species_id' => $type->species_id, 'production_type_id' => $type->id,
        'type' => 'reproducteur', 'model_name' => 'Dindon Guide Test', 'status' => 'Actif',
        'arrival_date' => today()->subDays(230)->toDateString(),
        'birth_date'   => today()->subDays(230)->toDateString(),
        'initial_quantity' => 110, 'current_quantity' => 110, 'qty_males' => 10, 'qty_females' => 100,
    ]);
});

function pontesDeLaSemaine(Batch $lot, float $taux, int $jours = 3): void
{
    for ($i = 1; $i <= $jours; $i++) {
        EggProduction::create([
            'farm_id' => $lot->farm_id, 'batch_id' => $lot->id,
            'production_date' => today()->subDays($i)->toDateString(),
            // 2 œufs déclassés par jour : incubables = collectés − 2 (EggProduction::incubables).
            'total_eggs_collected' => (int) round($taux), 'downgraded_eggs' => 2,
            'laying_rate' => $taux,
        ]);
    }
}

function semaineCourante(array $comparaison): array
{
    return collect($comparaison['semaines'])->firstWhere('semaine', 33);
}

test('la fiche compare chaque semaine au guide : ponte, incubables, fertilité, éclosion, aliment, mortalité', function () {
    expect($this->dindons->semaineDuCycle())->toBe(33);

    pontesDeLaSemaine($this->dindons, 64);
    Incubation::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->dindons->id, 'source_type' => 'internal',
        'code_incubation' => 'INC-DT-1', 'start_date' => today()->toDateString(),
        'incubation_duration' => 28, 'hatch_date_expected' => today()->addDays(28)->toDateString(),
        'eggs_count' => 200, 'fertile_eggs' => 176, 'hatched_chicks' => 150, 'status' => 'clos',
    ]);
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->dindons->id,
        'check_date' => today()->subDays(1)->toDateString(), 'mortality' => 1, 'feed_consumed' => 33,
    ]);

    // Le pointage a ôté le mort de l'effectif : 109 présents, 110 en début de semaine.
    $semaine = semaineCourante(app(ReferentielReproducteur::class)->comparer($this->dindons->fresh()));

    expect($semaine['guide']['laying'])->toEqual(65.0)
        ->and($semaine['guide']['weight_male'])->toEqual(20000.0)
        ->and($semaine['guide']['fertility'])->toEqual(90.0)
        ->and($semaine['reel']['laying'])->toEqual(64.0)
        ->and($semaine['reel']['hatching_eggs'])->toEqual(round(62 * 3 / (64 * 3) * 100, 1))
        ->and($semaine['reel']['fertility'])->toEqual(88.0)                  // 176 / 200
        ->and($semaine['reel']['hatchability'])->toEqual(75.0)               // 150 / 200 mis à couver
        ->and($semaine['reel']['mortality'])->toEqual(round(1 / 110 * 100, 2));
});

test('ponte sous norme × 0,95 : alerte sur la fiche du lot', function () {
    pontesDeLaSemaine($this->dindons, 59);   // guide 65 → seuil 61,75

    $comparaison = app(ReferentielReproducteur::class)->comparer($this->dindons);
    expect($comparaison['alerte'])->toMatchArray(['semaine' => 33, 'reel' => 59.0, 'guide' => 65.0, 'seuil' => 61.75, 'ecart_pct' => 9.2]);

    $page = $this->get(route('batches.show', $this->dindons))->assertOk();
    expect(collect($page->viewData('batchAdvisories'))->pluck('title'))->toContain('Ponte sous le référentiel');
});

test('dans les 5 %, pas d’alerte ; et le seuil se règle', function () {
    pontesDeLaSemaine($this->dindons, 62);   // 62 ≥ 61,75

    expect(app(ReferentielReproducteur::class)->comparer($this->dindons)['alerte'])->toBeNull();

    Setting::set('elevage.breeder_laying_gap_pct', '3');   // seuil 63,05
    expect(app(ReferentielReproducteur::class)->comparer($this->dindons)['alerte']['ecart_pct'])->toEqual(4.6);
});

test('le seuil suit le stade du lot : 5 % de la norme, pas 5 points', function () {
    // Décision de l'exploitation : 5 points sur une ponte de 20 % valaient une
    // chute de 25 %. Norme 65 → 61,75 ; norme 20 → 19.
    expect(ProductionNorm::seuilDePonte($this->dindons, 65))->toEqualWithDelta(61.75, 0.001)
        ->and(ProductionNorm::seuilDePonte($this->dindons, 20))->toEqualWithDelta(19.0, 0.001)
        ->and(ProductionNorm::ponteSousLaCourbe($this->dindons, 18.9, 20))->toBeTrue()
        ->and(ProductionNorm::ponteSousLaCourbe($this->dindons, 19.5, 20))->toBeFalse();
});

test('le résumé du matin juge la ponte du reproducteur à la même règle que la fiche', function () {
    // Hier : 59 œufs pour 100 femelles, 59 %. Le guide dit 65 %, seuil 61,75 %.
    // La règle des autres bandes (85 % de la norme, 55,25 %) se taisait.
    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->dindons->id,
        'production_date' => today()->subDay()->toDateString(), 'total_eggs_collected' => 59,
    ]);

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect(collect($rapport['irregularities'])->where('type', 'hdp_below_norm')->pluck('batch'))
        ->toContain($this->dindons->code);
});

test('une souche sans référentiel reproducteur garde la règle des 85 % — non-régression', function () {
    ProductionNorm::where('model_name', 'Dindon Guide Test')->update(array_fill_keys(array_values(ProductionNorm::CIBLES_REPRODUCTEUR), null));

    expect(app(ReferentielReproducteur::class)->comparer($this->dindons))->toBeNull()
        ->and(ProductionNorm::ponteSousLaCourbe($this->dindons, 59, 65))->toBeFalse()
        ->and(ProductionNorm::ponteSousLaCourbe($this->dindons, 55, 65))->toBeTrue();
});

test('l’éditeur de normes enregistre la matrice du guide, et l’import CSV ses colonnes facultatives', function () {
    $this->post(route('batches.norms.store'), [
        'batch_type' => 'reproducteur', 'week_number' => 40, 'phase_name' => 'Ponte', 'model_name' => 'Dindon Guide Test',
        'target_laying_rate' => 55, 'target_weight_male' => 21000, 'target_weight_female' => 9800,
        'target_fertility_rate' => 88, 'target_hatchability_rate' => 82, 'target_mortality_weekly' => 0.4,
    ])->assertSessionHasNoErrors();

    $semaine40 = ProductionNorm::where('model_name', 'Dindon Guide Test')->where('week_number', 40)->first();
    expect((float) $semaine40->target_weight_female)->toEqual(9800.0)
        ->and((float) $semaine40->target_fertility_rate)->toEqual(88.0);

    $csv = "week_number,phase_name,target_weight,target_laying_rate,target_feed_daily,target_water_daily,model_name,m,f,inc,fert,ecl,mort\n"
        . "41,Ponte,11000,54,300,600,Dindon Guide Test,21000,9800,94,87,81,0.45\n";
    $fichier = \Illuminate\Http\UploadedFile::fake()->createWithContent('guide.csv', $csv);

    $this->post(route('batches.norms.import'), ['file' => $fichier, 'batch_type' => 'reproducteur'])
        ->assertSessionHasNoErrors();

    $semaine41 = ProductionNorm::where('model_name', 'Dindon Guide Test')->where('week_number', 41)->first();
    expect((float) $semaine41->target_hatching_egg_rate)->toEqual(94.0)
        ->and((float) $semaine41->target_mortality_weekly)->toEqual(0.45);
});

test('les incubables : collectés moins cassés et anormaux, sans ajustement', function () {
    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->dindons->id,
        'production_date' => today()->subDay()->toDateString(),
        'total_eggs_collected' => 64, 'broken_eggs' => 2, 'small_eggs' => 1, 'laying_rate' => 64,
    ]);

    $semaine = semaineCourante(app(ReferentielReproducteur::class)->comparer($this->dindons));

    expect($semaine['reel']['hatching_eggs'])->toEqual(round(61 / 64 * 100, 1));
});
