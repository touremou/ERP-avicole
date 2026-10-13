<?php

use App\Models\ProductionNorm;
use App\Models\ProductionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « IL Y A UN SOUCI DANS LE CHOIX DES SOUCHES » — ET LES AUTRES ESPÈCES.
 *
 * Signalé par l'exploitation, capture à l'appui : pour un mouton, la liste des
 * souches proposait « Poussin Standard ». Cette norme n'avait pas d'espèce,
 * donc valait pour toutes. En fiabilisant les autres espèces (décision de
 * l'exploitation), la même revue a trouvé des courbes qui ne décrivaient pas
 * l'animal de leur type : laitières décrites comme des jeunes en croissance,
 * poussinière de 90 j dont la courbe s'arrêtait à 28 j, canard et pintade dont
 * la courbe et le cycle divergeaient, et six types sans aucune courbe.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);
});

test('toute souche du référentiel appartient à une espèce', function () {
    expect(ProductionNorm::whereNull('species_id')->pluck('model_name')->unique()->values()->all())->toBe([]);
});

test('la liste des souches d’un mouton ne propose plus « Poussin Standard »', function () {
    $this->actingAs($this->adminUser);
    $page = $this->get(route('batches.create'))->assertOk()->getContent();

    preg_match('/<option value="Poussin Standard"[^>]*data-species="([^"]*)"/', $page, $m);
    expect($m[1] ?? null)->toBe('poulet');
});

test('chaque type de production a sa courbe (le dindon reproducteur, faute de repère, excepté)', function () {
    $sansCourbe = ProductionType::with('species')->get()
        ->reject(fn ($t) => ProductionNorm::where('species_id', $t->species_id)->where('batch_type', $t->slug)->exists())
        ->map(fn ($t) => "{$t->species->slug}/{$t->slug}")
        ->values()->all();

    expect($sansCourbe)->toBe(['dinde/reproducteur']);
});

test('une courbe et le cycle de sa bande décrivent le même animal', function () {
    $divergences = [];

    foreach (ProductionNorm::get()->groupBy('model_name') as $souche => $lignes) {
        if ($lignes->count() < 2) continue;   // repère adulte unique (reproducteur)

        $premiere = $lignes->first();
        $cycle = $lignes->max('cycle_days') ?: ProductionType::where('species_id', $premiere->species_id)
            ->where('slug', $premiere->batch_type)->value('cycle_days_default');
        $finCourbe = $lignes->max('week_number') * 7;

        // Tolérance : deux semaines avant, six après (plateau de ponte).
        if ($cycle < $finCourbe - 14 || $cycle > $finCourbe + 42) {
            $divergences[] = "{$souche} : courbe {$finCourbe} j, cycle {$cycle} j";
        }
    }

    expect($divergences)->toBe([]);
});

test('une laitière est un troupeau en lactation, pas un jeune en croissance', function () {
    foreach (['Chèvre Saanen', 'Vache Laitière (Métis)'] as $souche) {
        $poids = ProductionNorm::where('model_name', $souche)->orderBy('week_number')->pluck('target_weight');
        expect($poids->first() / $poids->last())->toBeGreaterThan(0.9);
    }
});

test('la reprise remplace une courbe livrée intacte, et respecte une courbe ajustée', function () {
    // État d'avant : poussinière livrée telle quelle ; Saanen ajustée par la ferme.
    DB::table('production_norms')->whereIn('model_name', ['Poussin Standard', 'Chèvre Saanen', 'Caille de chair'])->delete();
    foreach ([[1, 160], [2, 400], [3, 750], [4, 1100]] as [$s, $p]) {
        ProductionNorm::create(['batch_type' => 'poussiniere', 'week_number' => $s, 'phase_name' => 'Démarrage',
            'model_name' => 'Poussin Standard', 'target_weight' => $p, 'target_feed_daily' => 20, 'target_water_daily' => 40]);
    }
    ProductionNorm::create(['batch_type' => 'laitiere', 'week_number' => 1, 'phase_name' => 'Ma courbe',
        'model_name' => 'Chèvre Saanen', 'target_weight' => 55000, 'target_feed_daily' => 2100, 'target_water_daily' => 8000]);

    (require database_path('migrations/2026_10_13_000005_every_species_norm_describes_its_animal.php'))->up();

    expect(ProductionNorm::where('model_name', 'Poussin Standard')->max('week_number'))->toBe(13)
        ->and(ProductionNorm::where('model_name', 'Poussin Standard')->value('species_id'))->not->toBeNull()
        ->and(ProductionNorm::where('model_name', 'Chèvre Saanen')->value('phase_name'))->toBe('Ma courbe')
        ->and(ProductionNorm::where('model_name', 'Caille de chair')->count())->toBe(4);
});
