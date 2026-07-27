<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Species;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX RÉGLAGES POUR UN SEUL SEUIL, ET CINQ ÉCRANS QUI CONTOURNAIENT L'ACCESSEUR.
 *
 * Paramètres › Élevage proposait côte à côte deux champs pour le même seuil de
 * mortalité cumulée :
 *
 *   « Seuil alerte mortalité »         → elevage.mortality_alert
 *   « Seuil alerte mortalité cumulée » → elevage.cumulative_mortality_alert_pct
 *
 * `Batch::cumulativeMortalityThreshold()` se présente comme la « SOURCE DE VÉRITÉ
 * UNIQUE » et lit le second, avec repli sur le premier. Mais CINQ consommateurs
 * lisaient le premier EN DIRECT : le rapport technique, l'analyse financière
 * santé, la vue consolidée cross-sites, l'écran de pointage et la fiche
 * hebdomadaire par technicien.
 *
 * Éditer le champ « cumulée » changeait donc l'alerte de l'observer, le tableau de
 * bord et le filtre surmortalité — et laissait les cinq autres écrans sur
 * l'ancienne valeur. Deux vérités pour un seul seuil, selon l'écran regardé.
 *
 * Le facteur 60 % de la zone « Alerte » était lui aussi recopié dans le
 * contrôleur et dans trois vues, l'une arrondissant et les autres non.
 */

beforeEach(function () {
    $this->setUpRbac();
    Setting::clearCache();
});

function watchedBatch(int $initial = 1000, int $deaths = 80): Batch
{
    $species = Species::firstOrCreate(['slug' => 'poulet'], ['name_fr' => 'Poulet', 'is_active' => true]);

    $batch = Batch::factory()->create([
        'farm_id'            => session('current_farm_id'),
        'building_id'        => Building::factory()->create(['farm_id' => session('current_farm_id')])->id,
        'species_id'         => $species->id,
        'production_type_id' => ProductionType::resolveOrCreate('chair', $species->id)->id,
        'status'             => 'Actif',
        'arrival_date'       => now()->subDays(30)->toDateString(),
        'initial_quantity'   => $initial,
        'current_quantity'   => $initial,
        'qty_dead'           => 0,
    ]);

    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => today()->subDay(),
        'mortality' => $deaths, 'feed_consumed' => 2500, 'avg_weight' => 1.8,
        'user_id' => \App\Models\User::query()->value('id'),
    ]);

    return $batch->fresh();
}

test('il ne reste qu’UN champ de seuil de mortalité cumulée', function () {
    $keys = DB::table('settings')->where('group', 'elevage')
        ->whereIn('key', ['mortality_alert', 'cumulative_mortality_alert_pct'])
        ->pluck('key');

    expect($keys)->toHaveCount(1)
        ->and($keys->first())->toBe('cumulative_mortality_alert_pct');
});

test('éditer le seuil se répercute sur TOUS les écrans', function () {
    // Le cas qui divergeait : cinq écrans restaient sur l'ancienne clé.
    Setting::set('elevage.cumulative_mortality_alert_pct', 7);
    Setting::clearCache();

    expect(Batch::cumulativeMortalityThreshold())->toBe(7.0)
        ->and(Batch::cumulativeMortalityWarningThreshold())->toBe(4.2);

    $batch = watchedBatch();

    // Rapport technique : la légende ET le classement suivent le nouveau seuil.
    $this->actingAs($this->adminUser)->get(route('reports.technical'))
        ->assertOk()
        ->assertSee('4.2', false);

    // Fiche hebdomadaire par technicien.
    $week = app(\App\Services\TechnicianWeekService::class);
    $reflection = new ReflectionClass($week);
    foreach ($reflection->getMethods() as $method) {
        if ($method->getName() === 'mortalityThreshold') {
            $method->setAccessible(true);
            expect($method->invoke($week))->toBe(7.0);
        }
    }
});

test('le seuil d’ATTENTION est calculé en un seul endroit', function () {
    Setting::set('elevage.cumulative_mortality_alert_pct', 5);
    Setting::clearCache();

    // 5 × 0,6 = 3,0. Le contrôleur ne l'arrondissait pas, les vues si.
    expect(Batch::cumulativeMortalityWarningThreshold())->toBe(3.0);

    Setting::set('elevage.cumulative_mortality_alert_pct', 5.5);
    Setting::clearCache();
    expect(Batch::cumulativeMortalityWarningThreshold())->toBe(3.3);
});

test('aucun écran ne lit plus l’ancienne clé en direct', function () {
    foreach ([
        app_path('Http/Controllers/ReportController.php'),
        app_path('Services/TechnicianWeekService.php'),
        resource_path('views/reports/technical.blade.php'),
        resource_path('views/reports/health_finance.blade.php'),
        resource_path('views/consolide/index.blade.php'),
        resource_path('views/daily-checks/edit.blade.php'),
    ] as $file) {
        expect(file_get_contents($file))
            ->not->toContain("setting('elevage.mortality_alert'");
    }
});

test('le facteur 0,6 n’est plus recopié dans les vues', function () {
    foreach ([
        resource_path('views/reports/technical.blade.php'),
        resource_path('views/reports/health_finance.blade.php'),
        resource_path('views/consolide/index.blade.php'),
    ] as $file) {
        expect(file_get_contents($file))->not->toContain('* 0.6');
    }
});

test('la migration préserve le réglage que la ferme avait fait', function () {
    // Cas réel : la ferme avait réglé l'ANCIEN champ à 8 sans toucher au nouveau.
    // Supprimer le doublon sans reporter sa valeur aurait effacé son choix.
    DB::table('settings')->where('group', 'elevage')
        ->where('key', 'cumulative_mortality_alert_pct')
        ->update(['value' => '5']);

    DB::table('settings')->insert([
        'group' => 'elevage', 'key' => 'mortality_alert', 'value' => '8',
        'type' => 'number', 'label' => 'Seuil alerte mortalité', 'unit' => '%',
        'display_order' => 99, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_08_09_000000_reconcile_duplicate_mortality_threshold.php');
    $migration->up();
    Setting::clearCache();

    expect(Batch::cumulativeMortalityThreshold())->toBe(8.0)
        ->and(DB::table('settings')->where('group', 'elevage')
            ->where('key', 'mortality_alert')->exists())->toBeFalse();
});

test('les rapports partagent leur calcul avec leur PDF', function () {
    // Contrôle de bonne santé : chaque rapport doit produire son écran et son PDF
    // depuis le MÊME constructeur de statistiques. C'est le cas, et ce test le
    // fige pour que ça le reste.
    $source = file_get_contents(app_path('Http/Controllers/ReportController.php'));

    foreach ([
        'nurseryReportPdf'          => 'buildNurseryStats',
        'profitLossPdf'             => 'buildProfitLossStats',
        'healthFinancialReportPdf'  => 'buildHealthFinanceStats',
        'technicalPerformancePdf'   => 'buildTechnicalStats',
        'monthlyExpensesPdf'        => 'buildMonthlyExpensesStats',
        'gmqReportPdf'              => 'buildGmqStats',
        'aquacultureReportPdf'      => 'buildAquacultureStats',
    ] as $pdfMethod => $builder) {
        $start = strpos($source, "function {$pdfMethod}(");
        expect($start)->not->toBeFalse("la méthode {$pdfMethod} doit exister");

        $body = substr($source, $start, 800);
        expect($body)->toContain($builder);
    }
});
