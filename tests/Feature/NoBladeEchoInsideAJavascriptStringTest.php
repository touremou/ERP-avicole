<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * `'{{ … }}'` DANS DU JAVASCRIPT : UNE APOSTROPHE SUFFIT.
 *
 * `{{ }}` échappe pour le HTML : l'apostrophe devient `&#039;`. Mais dans un
 * attribut (`onsubmit`, `x-data`, `@click`…), le navigateur DÉCODE l'entité
 * avant d'exécuter le code : l'apostrophe revient, et ferme la chaîne
 * JavaScript qui l'entourait.
 *
 *   • TRADUCTIONS : « Suspendre cet accès ? L'utilisateur… », « …rien n'est
 *     supprimé » — en français, presque toujours une apostrophe. Le
 *     gestionnaire ne compilait pas, la confirmation n'était PAS posée, et
 *     l'action partait (suspendre un utilisateur, supprimer un compte de
 *     trésorerie, une source d'énergie…) ;
 *   • URL : `x-data="{ activeTab: '{{ request('category') }}' }"`. Un lien
 *     piégé (`/inventory?category=x'+…+'`) EXÉCUTAIT du code dans la session
 *     de celui qui l'ouvrait — vérifié dans Chromium. Faille XSS réfléchie.
 *
 * `@js(…)` produit une chaîne JavaScript échappée pour HTML : c'est la seule
 * façon sûre d'y poser une valeur.
 */

test('aucune traduction ni saisie n’est posée entre apostrophes dans du JavaScript', function () {
    $fautifs = [];
    $echo = "/'\\{\\{\\s*((?:__|old|request)\\((?:[^()]|\\([^()]*\\))*\\))\\s*\\}\\}'/";
    $attributJs = '/(?:\son[a-z]+|\sx-[a-z.:-]+|\s@[a-z.-]+|\s:[a-z-]+)="[^>]*$/s';

    $vues = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($vues as $fichier) {
        if (! str_ends_with($fichier->getFilename(), '.blade.php')) continue;
        $source = file_get_contents($fichier->getPathname());

        preg_match_all('/<script\b.*?<\/script>/s', $source, $scripts, PREG_OFFSET_CAPTURE);
        $dansUnScript = fn (int $p) => collect($scripts[0])->contains(fn ($s) => $p >= $s[1] && $p < $s[1] + strlen($s[0]));

        preg_match_all($echo, $source, $occurrences, PREG_OFFSET_CAPTURE);
        foreach ($occurrences[0] as [, $position]) {
            $debutBalise = strrpos(substr($source, 0, $position), '<');
            $dansUnAttribut = $debutBalise !== false
                && preg_match($attributJs, substr($source, $debutBalise, $position - $debutBalise));

            if ($dansUnScript($position) || $dansUnAttribut) {
                $ligne = substr_count(substr($source, 0, $position), "\n") + 1;
                $fautifs[] = str_replace(resource_path('views') . '/', '', $fichier->getPathname()) . ':' . $ligne;
            }
        }
    }

    expect($fautifs)->toBe([]);
});

test('un lien piégé ne glisse plus de code dans l’écran des stocks', function () {
    $this->setUpRbac();
    $piege = "oeufs'+(window.__pwned=1)+'";

    foreach (['stocks.index', 'stocks.create'] as $ecran) {
        $page = $this->actingAs($this->adminUser)
            ->get(route($ecran, ['category' => $piege]))
            ->assertOk()->getContent();

        expect($page)->not->toContain('__pwned');
    }
});

test('une confirmation à apostrophe reste du JavaScript valide', function () {
    $this->setUpRbac();
    $autre = App\Models\User::factory()->create(['role_id' => $this->adminUser->role_id]);

    $page = $this->actingAs($this->adminUser)->get(route('users.index'))->assertOk()->getContent();

    // L'apostrophe de « L'utilisateur » est échappée POUR JavaScript ('),
    // plus seulement pour HTML (&#039;, que le navigateur redécode).
    expect($page)->not->toContain("confirm('Suspendre cet accès ? L&#039;utilisateur")
        ->and($page)->toContain('Suspendre cet acc');
});
