<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Species;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MORTALITÉ — UNE SEULE MESURE, ET UN SEUIL QUE LA FERME PEUT RÉGLER.
 *
 * Le taux de mortalité du JOUR était calculé de TROIS façons différentes :
 *
 *   • DailyCheck (l'alerte à la saisie) : morts / (effectif + impact NET du
 *     pointage) — la seule qui reconstituait vraiment l'effectif du matin,
 *     puisqu'un pointage déplace aussi des sujets en quarantaine et en trie ;
 *   • DashboardController : morts / (effectif + morts) — ignore ces mouvements,
 *     donc surévalue le taux dès qu'il y a eu un tri ;
 *   • DashboardService : morts / effectif COURANT — dénominateur déjà amputé des
 *     morts du jour, donc surévaluation systématique.
 *
 * Ce dernier service portait AUSSI sa propre mortalité cumulée (sans les morts
 * en infirmerie au numérateur, sans les morts au transport au dénominateur) et un
 * seuil de 5 % codé en dur, alors que Batch::cumulativeMortalityThreshold() se
 * présente en commentaire comme « SOURCE DE VÉRITÉ UNIQUE […] ET le tableau de
 * bord ». Il n'était appelé par AUCUN code de l'application : seul son propre
 * test le touchait. Il est supprimé.
 *
 * Mais il contenait une bonne idée, la seule à n'avoir jamais servi : le seuil
 * d'alerte quotidien dépend de l'ÂGE. 0,8 %/jour est normal sur des poussins de
 * chair à J3 et alarmant en finition ; un seuil plat se trompe dans les deux
 * sens. La courbe est reprise et exposée au paramétrage.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->building = Building::factory()->create(['farm_id' => $this->farm->id]);
});

function mortalityFlock(string $speciesSlug, string $productionType, int $age, int $quantity, int $transportDeaths = 0): Batch
{
    $species = Species::firstOrCreate(
        ['slug' => $speciesSlug],
        ['name_fr' => ucfirst($speciesSlug), 'is_active' => true]
    );

    return Batch::factory()->create([
        'farm_id'             => session('current_farm_id'),
        'building_id'         => Building::factory()->create(['farm_id' => session('current_farm_id')])->id,
        'species_id'          => $species->id,
        'production_type_id'  => ProductionType::resolveOrCreate($productionType, $species->id)->id,
        'status'              => 'Actif',
        'arrival_date'        => now()->subDays($age)->toDateString(),
        'initial_quantity'    => $quantity,
        'current_quantity'    => $quantity,
        'qty_dead'            => $transportDeaths,
    ]);
}

test('le taux du jour se calcule sur l’effectif du MATIN, tris compris', function () {
    $batch = mortalityFlock('poulet', 'chair', 10, 1000);

    // 10 morts et 20 sujets triés : l'observer retire 30 de l'effectif courant.
    $check = DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => today(), 'mortality' => 10,
        'qty_sorted_out' => 20, 'user_id' => $this->adminUser->id,
    ]);

    $batch->refresh();
    expect((int) $batch->current_quantity)->toBe(970);

    // 10 / 1000 = 1,00 %. Les deux autres formules donnaient 10/980 = 1,02 %
    // (effectif + morts) et 10/970 = 1,03 % (effectif courant).
    expect($batch->dailyMortalityRate($check))->toBe(1.0);
});

test('le seuil du jour dépend de la phase', function () {
    // C'est tout l'intérêt de la courbe : 0,8 %/jour à J3 n'est pas une alerte.
    expect(mortalityFlock('poulet', 'chair', 3, 1000)->dailyMortalityThreshold())->toBe(1.0);
    expect(mortalityFlock('poulet', 'chair', 20, 1000)->dailyMortalityThreshold())->toBe(0.5);
    expect(mortalityFlock('poulet', 'chair', 40, 1000)->dailyMortalityThreshold())->toBe(0.2);
    expect(mortalityFlock('poulet', 'ponte', 30, 1000)->dailyMortalityThreshold())->toBe(0.5);
    expect(mortalityFlock('poulet', 'ponte', 200, 1000)->dailyMortalityThreshold())->toBe(0.1);
});

test('la ferme peut régler chaque seuil de phase', function () {
    Setting::set('elevage.mortality_pct_chair_demarrage', 1.5);

    expect(mortalityFlock('poulet', 'chair', 3, 1000)->dailyMortalityThreshold())->toBe(1.5);
});

test('un seuil de phase vidé retombe sur le réglage général', function () {
    Setting::set('elevage.mortality_pct_chair_demarrage', '');
    Setting::set('elevage.daily_mortality_alert_pct', 0.7);

    expect(mortalityFlock('poulet', 'chair', 3, 1000)->dailyMortalityThreshold())->toBe(0.7);
});

test('mettre 0,5 partout restaure le comportement d’avant', function () {
    // La courbe est un choix, pas une fatalité : la ferme peut revenir au seuil
    // plat qui s'appliquait jusqu'ici.
    foreach (['chair_demarrage', 'chair_croissance', 'chair_finition',
              'ponte_poulette', 'ponte_production', 'autres'] as $phase) {
        Setting::set("elevage.mortality_pct_{$phase}", 0.5);
    }

    expect(mortalityFlock('poulet', 'chair', 3, 1000)->dailyMortalityThreshold())->toBe(0.5);
    expect(mortalityFlock('poulet', 'ponte', 200, 1000)->dailyMortalityThreshold())->toBe(0.5);
});

test('les seuils de phase sont créés aux valeurs de la courbe d’origine', function () {
    $seeded = DB::table('settings')->where('group', 'elevage')
        ->where('key', 'LIKE', 'mortality_pct_%')->pluck('value', 'key');

    expect($seeded)->toHaveCount(6)
        ->and($seeded['mortality_pct_chair_demarrage'])->toBe('1.0')
        ->and($seeded['mortality_pct_chair_finition'])->toBe('0.2')
        ->and($seeded['mortality_pct_ponte_production'])->toBe('0.1');
});

test('la mortalité CUMULÉE compte les morts d’infirmerie et le transport', function () {
    // La formule du service mort oubliait les morts d'infirmerie au numérateur
    // ET les morts au transport au dénominateur : deux erreurs de sens contraire
    // dans une même expression, impossibles à rapprocher de la fiche du lot.
    $batch = mortalityFlock('poulet', 'chair', 20, 1000, transportDeaths: 50);

    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => today(), 'mortality' => 30,
        'mortality_infirmary' => 20, 'user_id' => $this->adminUser->id,
    ]);

    // (50 + 30 + 20) / (1000 + 50) = 9,52 %
    expect($batch->fresh()->mortality_rate)->toBe(9.52);
});

test('le tableau de bord et la fiche du lot annoncent le MÊME taux', function () {
    $batch = mortalityFlock('poulet', 'chair', 20, 1000, transportDeaths: 50);
    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => today(), 'mortality' => 30,
        'mortality_infirmary' => 20, 'user_id' => $this->adminUser->id,
    ]);

    $response = $this->actingAs($this->adminUser)->get(route('dashboard'))->assertOk();

    // La dérive technique du tableau de bord s'appuie sur l'accessor du lot.
    $underperforming = collect($response->viewData('underperformingBatches') ?? []);
    Setting::set('elevage.cumulative_mortality_alert_pct', 5);

    expect($batch->fresh()->mortality_rate)->toBeGreaterThan(5.0);
    expect($underperforming->pluck('id'))->toContain($batch->id);
});

test('le seuil du résumé WhatsApp n’est plus codé en dur', function () {
    $hub = file_get_contents(app_path('Services/NotificationHub.php'));

    expect($hub)->not->toContain("\$rate > 0.5 ? '🔴'")
        ->and($hub)->toContain("setting('elevage.daily_mortality_alert_pct', 0.5) ? '🔴'");
});

test('le service tableau de bord mort a disparu', function () {
    // 342 lignes jamais appelées, portant une quatrième formule de mortalité, un
    // seuil de 5 % codé en dur et un calcul d'autonomie des silos supplanté par
    // celui du contrôleur.
    expect(file_exists(app_path('Services/DashboardService.php')))->toBeFalse();

    // Plus aucune UTILISATION dans l'application : on cherche les appels, pas le
    // mot — les commentaires d'historique ont leur utilité pour le prochain lecteur.
    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
        $source = $file->getContents();
        expect($source)->not->toContain('use App\\Services\\DashboardService');
        expect($source)->not->toContain('new DashboardService');
    }
});
