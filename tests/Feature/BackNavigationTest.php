<?php

use Illuminate\Support\Facades\Route;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SEULE FLÈCHE DE RETOUR PAR PAGE.
 *
 * Deux composants peuvent en poser une, et rien ne les coordonnait :
 *   - le layout rend <x-hub-back />, qui déduit le parent du PRÉFIXE de route ;
 *   - une page peut passer `:back` à <x-page-header />, avec sa cible explicite.
 *
 * Douze pages en affichaient donc DEUX, côte à côte — et sur certaines, vers des
 * destinations DIFFÉRENTES (le hub du module d'un côté, la liste parente de
 * l'autre). Le lecteur ne sait plus laquelle remonte où, et le défaut ne se voit
 * qu'à l'œil, page par page : personne ne le trouve en relisant du code.
 *
 * Chaque ancre porte donc `data-back="hub"` ou `data-back="page"`, ce qui rend le
 * défaut MESURABLE. Ce test balaie toutes les pages authentifiées sans paramètre
 * et échoue dès qu'une en porte deux — y compris sur une page créée plus tard.
 */

test('aucune page ne porte deux flèches de retour', function () {
    $this->setUpRbac();

    $doubles = [];
    $scanned = 0;

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if (! $name || str_starts_with($name, 'api.') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }
        if (! in_array('auth', $route->gatherMiddleware(), true)) {
            continue;
        }
        // Les routes paramétrées demanderaient un jeu de données par module ;
        // elles sont de toute façon traitées en « feuille » par hub-back, qui ne
        // s'y affiche jamais. Les exports binaires n'ont pas de HTML.
        if (str_contains($route->uri(), '{') || preg_match('/pdf|export|download|logout|csv|print/i', $name)) {
            continue;
        }

        try {
            $response = $this->actingAs($this->adminUser)->get(route($name));
        } catch (\Throwable) {
            continue; // page qui exige un contexte métier absent : hors périmètre
        }

        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            continue;
        }
        if ($response->getStatusCode() !== 200) {
            continue;
        }

        $scanned++;
        $count = substr_count($response->getContent(), 'data-back="');

        if ($count > 1) {
            $doubles[] = "{$name} ({$count} flèches)";
        }
    }

    // Garde-fou : si le balayage ne voit plus rien, le test passerait à vide.
    expect($scanned)->toBeGreaterThan(40);

    sort($doubles);
    expect($doubles)->toBe([], "Page(s) affichant DEUX flèches de retour :\n  " . implode("\n  ", $doubles)
        . "\n\nUne page qui passe `:back` à x-page-header n'a pas besoin de x-hub-back :"
        . "\nvérifiez que page-header pose bien le drapeau « page-header.has-back » et que hub-back le lit.");
});

test('une page qui déclare son retour explicite efface celui du layout', function () {
    $this->setUpRbac();

    // « Contrats à terme » remonte vers la liste du personnel, pas vers le hub
    // RH : c'est la page qui connaît son parent réel, elle doit gagner.
    $html = $this->actingAs($this->adminUser)->get(route('employees.contracts.index'))->getContent();

    expect(substr_count($html, 'data-back="'))->toBe(1);
    expect($html)->toContain('data-back="page"');
    expect($html)->not->toContain('data-back="hub"');
});

test('une page sans retour explicite garde bien celui du layout', function () {
    $this->setUpRbac();

    // Non-régression : supprimer les doublons ne doit pas supprimer la
    // navigation là où le layout est le SEUL à la fournir.
    $html = $this->actingAs($this->adminUser)->get(route('batches.index'))->getContent();

    expect($html)->toContain('data-back="hub"');
    expect(substr_count($html, 'data-back="'))->toBe(1);
});
