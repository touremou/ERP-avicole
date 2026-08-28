<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE MODULE QUI FAIT ÉCLORE LES ŒUFS EXCLUAIT LES BANDES QUI LES PRODUISENT.
 *
 * `production_types.metrics_enabled` porte ce que mesure un type de production.
 * Le type « reproducteur » des espèces AVICOLES y était semé avec
 * `eggs => false`.
 *
 * Quatre endroits du code affirment pourtant l'inverse :
 *
 *   • `IncubationController` : « Lots éligibles à l'incubation :
 *     pondeurs/REPRODUCTEURS avicoles uniquement — on s'appuie donc sur
 *     tracksEggs() et l'espèce » ;
 *   • le repli legacy de `Batch::tracksEggs()` liste ponte/repro/reproducteur ;
 *   • `Batch::minLayingAgeDays()` traite explicitement repro/reproducteur ;
 *   • `ProductionType::feedSector()` range les reproducteurs en « Ponte » — ils
 *     mangent de l'aliment pondeuse, précisément parce qu'ils pondent.
 *
 * Le code déclarait la règle, la donnée la contredisait, et c'est la donnée qui
 * gagnait : `tracks('eggs')` lit la colonne, sans repli.
 *
 * Conséquence : une bande de reproducteurs ne pouvait ni faire l'objet d'une
 * collecte d'œufs, ni être choisie comme origine d'une incubation — alors
 * qu'elle est la source même des œufs à couver.
 *
 * ─── LE PÉRIMÈTRE EST LA MOITIÉ DU CORRECTIF ───
 *
 * Le slug « reproducteur » est partagé avec les ovins, caprins, bovins, lapins
 * et porcs. Une brebis ne pond pas : leur ligne ne doit pas bouger. C'est la
 * distinction que `IncubationController` fait déjà, et qu'on tient ici.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Le type de production d'une espèce, par slugs. */
function typeDeProduction(string $especeSlug, string $typeSlug): ?ProductionType
{
    $espece = Species::where('slug', $especeSlug)->first();

    return $espece
        ? ProductionType::where('species_id', $espece->id)->where('slug', $typeSlug)->first()
        : null;
}

test('une POULE reproductrice suit ses œufs', function () {
    /*
     * LE défaut : la bande qui fournit les œufs à couver était déclarée comme
     * n'en produisant pas.
     */
    $type = typeDeProduction('poulet', 'reproducteur');

    expect($type)->not->toBeNull()
        ->and($type->tracks('eggs'))->toBeTrue();
});

test('une DINDE reproductrice aussi', function () {
    $type = typeDeProduction('dinde', 'reproducteur');

    expect($type)->not->toBeNull()
        ->and($type->tracks('eggs'))->toBeTrue();
});

test('une BREBIS reproductrice ne pond toujours pas', function () {
    /*
     * LA borne. Le slug est partagé entre familles : élargir aux ruminants
     * aurait ouvert une collecte d'œufs sur un troupeau de moutons — un défaut
     * plus visible, mais de même nature que celui qu'on corrige.
     */
    foreach (['mouton', 'chevre', 'bovin', 'lapin', 'porc'] as $espece) {
        $type = typeDeProduction($espece, 'reproducteur');

        if ($type) {
            expect($type->tracks('eggs'))->toBeFalse();
        }
    }
})->skip(fn () => typeDeProduction('mouton', 'reproducteur') === null
    && typeDeProduction('chevre', 'reproducteur') === null,
    'Aucune espèce non avicole avec un type reproducteur dans ce jeu de données.');

test('un LOT de reproducteurs avicoles est éligible à la collecte et à l’incubation', function () {
    /*
     * Le bout de la chaîne : ce que le défaut empêchait réellement. Un lot
     * d'âge adulte doit passer les deux filtres — celui de la collecte d'œufs
     * (`tracksEggs`) et celui de l'incubation (`isVolaille() && tracksEggs()`).
     */
    $type = typeDeProduction('poulet', 'reproducteur');

    $lot = Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => $this->building->id,
        'production_type_id' => $type->id,
        'species_id'         => $type->species_id,
        'arrival_date'       => today()->subDays(300)->toDateString(),
        'birth_date'         => today()->subDays(300)->toDateString(),
        'initial_quantity'   => 400,
        'current_quantity'   => 400,
        'status'             => 'Actif',
    ]);

    expect($lot->tracksEggs())->toBeTrue()
        ->and($lot->isVolaille())->toBeTrue()
        ->and($lot->canCollectEggs())->toBeTrue();

    // Le filtre exact de l'écran d'incubation.
    $eligibles = Batch::active()->live()->with(['species', 'productionType'])->get()
        ->filter(fn (Batch $b) => $b->isVolaille() && $b->tracksEggs());

    expect($eligibles->pluck('id'))->toContain($lot->id);
});

test('l’écran d’incubation PROPOSE la bande de reproducteurs', function () {
    // Par la route, pour que le lien soit prouvé atteignable — et pas seulement
    // vrai au niveau du modèle.
    $type = typeDeProduction('poulet', 'reproducteur');

    $lot = Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => $this->building->id,
        'production_type_id' => $type->id,
        'species_id'         => $type->species_id,
        'arrival_date'       => today()->subDays(300)->toDateString(),
        'birth_date'         => today()->subDays(300)->toDateString(),
        'initial_quantity'   => 400,
        'current_quantity'   => 400,
        'status'             => 'Actif',
    ]);

    $this->get(route('incubations.index'))
        ->assertOk()
        ->assertSee($lot->code);
});

test('la MIGRATION répare une installation déjà en service', function () {
    /*
     * Le semis ne vaut que pour une base neuve. Sur une installation en
     * service, la ligne fautive est DÉJÀ écrite : c'est la migration qui doit
     * la corriger, et rien d'autre ne le fera.
     *
     * On remet donc la donnée dans son état d'avant, puis on rejoue la
     * migration — sans quoi ce fichier ne testerait que le semis, et la
     * migration partirait en production sans qu'une seule assertion l'ait
     * exercée.
     */
    $poule = typeDeProduction('poulet', 'reproducteur');

    $casse = array_merge($poule->metrics_enabled, ['eggs' => false]);
    \Illuminate\Support\Facades\DB::table('production_types')
        ->where('id', $poule->id)
        ->update(['metrics_enabled' => json_encode($casse)]);

    expect($poule->fresh()->tracks('eggs'))->toBeFalse();   // l'état d'avant

    (require base_path('database/migrations/2026_08_28_000001_poultry_breeders_track_their_eggs.php'))->up();

    expect($poule->fresh()->tracks('eggs'))->toBeTrue();
});

test('la migration ne touche PAS les autres métriques de la ligne', function () {
    /*
     * Elle réécrit un JSON entier : si elle le reconstruisait au lieu de le
     * modifier, elle effacerait au passage le suivi du poids ou de l'aliment.
     */
    $poule = typeDeProduction('poulet', 'reproducteur');

    $avant = $poule->metrics_enabled;

    \Illuminate\Support\Facades\DB::table('production_types')
        ->where('id', $poule->id)
        ->update(['metrics_enabled' => json_encode(array_merge($avant, ['eggs' => false]))]);

    (require base_path('database/migrations/2026_08_28_000001_poultry_breeders_track_their_eggs.php'))->up();

    $apres = $poule->fresh()->metrics_enabled;

    foreach ($avant as $cle => $valeur) {
        if ($cle === 'eggs') continue;
        expect($apres[$cle])->toBe($valeur, "métrique « {$cle} » altérée");
    }
});

test('la migration laisse les RUMINANTS reproducteurs intacts', function () {
    /*
     * La borne du périmètre, exercée sur la migration elle-même : c'est elle
     * qui touche la donnée des installations existantes.
     */
    $brebis = typeDeProduction('mouton', 'reproducteur') ?? typeDeProduction('chevre', 'reproducteur');

    if (! $brebis) {
        $this->markTestSkipped('Aucune espèce non avicole avec un type reproducteur.');
    }

    (require base_path('database/migrations/2026_08_28_000001_poultry_breeders_track_their_eggs.php'))->up();

    expect($brebis->fresh()->tracks('eggs'))->toBeFalse();
});
