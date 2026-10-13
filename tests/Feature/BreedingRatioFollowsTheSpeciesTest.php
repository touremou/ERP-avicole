<?php

use App\Models\Batch;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « RATIO DE COQUAGE » POUR DES MOUTONS.
 *
 * Signalé par l'exploitation, capture à l'appui : la création d'une bande de
 * béliers et brebis affichait « Ratio de coquage 40 % — En attente… ». Le
 * vocabulaire était celui du poulet, le jugement ne venait jamais ; et la
 * planification, elle, jugeait TOUTES les espèces sur la norme du poulet
 * (8–12 %) : 1 bélier pour 30 brebis y était « hors norme », 4 béliers pour
 * 10 brebis passaient à la création sans un mot.
 *
 * La référence vient de l'espèce (Species::ratioReproducteurs) ; le jugement
 * de resources/js/breeding-ratio.js (éprouvé par node --test) ; les trois
 * écrans partagent le même bandeau.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
});

test('chaque espèce a SA référence ; le poulet garde le réglage de la ferme', function () {
    $mouton = Species::where('slug', 'mouton')->first()->ratioReproducteurs();
    expect($mouton)->toMatchArray(['min' => 2.5, 'max' => 4.0])
        ->and($mouton['male'])->toBe('bélier');

    \App\Models\Setting::set('elevage.mating_ratio_min', 9);
    expect(Species::where('slug', 'poulet')->first()->ratioReproducteurs()['min'])->toBe(9.0);
});

test('les trois écrans portent le même bandeau, sans vocabulaire du poulet', function () {
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif']);

    foreach ([route('batches.create'), route('planning.create'), route('batches.edit', $lot)] as $ecran) {
        $page = $this->get($ecran)->assertOk()->getContent();

        expect($page)->toContain('Proportion mâles / femelles')
            ->and($page)->toContain('window.AVISMART_RATIOS')
            ->and($page)->not->toContain('Ratio de Coquage');
    }
});

test('aucun écran ne recalcule ni ne juge la proportion à sa façon', function () {
    foreach (['batches/create', 'batches/edit', 'planning/create'] as $vue) {
        $source = file_get_contents(resource_path("views/{$vue}.blade.php"));

        expect($source)->not->toContain('m / f * 100')
            ->and($source)->not->toContain('mating_ratio_min');
    }
});
