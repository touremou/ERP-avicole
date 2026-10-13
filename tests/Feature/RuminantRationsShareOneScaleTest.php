<?php

use App\Models\ProductionNorm;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * UNE ÉCHELLE DE RATION POUR TOUS LES RUMINANTS.
 *
 * Les normes bovines étaient en fourrage FRAIS (12 kg/j pour un veau de
 * 80 kg, 15 % du poids vif), celles des ovins et caprins en MATIÈRE SÈCHE
 * (~4 %) : deux échelles sous la même étiquette « ration ». Décision de
 * l'exploitation : tout en matière sèche.
 */

beforeEach(function () {
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);
});

test('toute norme de ruminant donne une ration de matière sèche plausible (1,5 à 5 % du poids vif)', function () {
    $ruminants = Species::whereIn('family', ['petit_ruminant', 'grand_ruminant'])->pluck('id');

    $horsEchelle = ProductionNorm::whereIn('species_id', $ruminants)
        ->where('target_weight', '>', 0)->whereNotNull('target_feed_daily')->get()
        ->filter(fn ($n) => ($n->target_feed_daily / $n->target_weight) < 0.015 || ($n->target_feed_daily / $n->target_weight) > 0.05)
        ->map(fn ($n) => "{$n->model_name} S{$n->week_number} : " . round(100 * $n->target_feed_daily / $n->target_weight, 1) . ' %')
        ->values()->all();

    expect($horsEchelle)->toBe([]);
});

test('la reprise aligne les normes bovines livrées, et respecte une valeur ajustée', function () {
    DB::table('production_norms')->where('model_name', "Bovin N'Dama")->where('week_number', 1)
        ->update(['target_feed_daily' => 12000, 'target_water_daily' => 25000]);
    DB::table('production_norms')->where('model_name', 'Zébu Gobra')->where('week_number', 1)
        ->update(['target_feed_daily' => 3100]);   // ajustée par la ferme

    (require database_path('migrations/2026_10_13_000004_cattle_ration_in_dry_matter.php'))->up();

    $ndama = ProductionNorm::where('model_name', "Bovin N'Dama")->where('week_number', 1)->first();
    expect((float) $ndama->target_feed_daily)->toBe(2400.0)
        ->and((float) $ndama->target_water_daily)->toBe(10000.0)
        ->and((float) ProductionNorm::where('model_name', 'Zébu Gobra')->where('week_number', 1)->value('target_feed_daily'))->toBe(3100.0);
});
