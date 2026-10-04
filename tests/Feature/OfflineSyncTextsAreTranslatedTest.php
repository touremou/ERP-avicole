<?php

use App\Support\OfflineSyncTexts;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES MESSAGES DU MOTEUR HORS-LIGNE PARLENT LA LANGUE DE L'UTILISATEUR.
 *
 * Le bandeau des saisies refusées était écrit en dur, en français, dans le
 * JavaScript — hors de portée de `__()` et de lang/en.json. Trois maillons
 * doivent désormais tenir ensemble ; chacun a son test.
 */

/** Toutes les clés littérales passées à `traduire('…')` dans le moteur. */
function clesAppeleesParLeMoteur(): array
{
    $cles = [];
    foreach (glob(resource_path('js/sync-*.js')) as $fichier) {
        preg_match_all("/traduire\\('((?:[^'\\\\]|\\\\.)*)'/u", file_get_contents($fichier), $m);
        $cles = array_merge($cles, $m[1]);
    }

    return array_values(array_unique($cles));
}

test('chaque texte appelé par le moteur est déclaré — sinon il resterait en français pour tous', function () {
    $cles = clesAppeleesParLeMoteur();

    expect($cles)->not->toBeEmpty();
    expect(array_values(array_diff($cles, OfflineSyncTexts::CLES)))->toBe([]);
});

test('chaque texte déclaré a sa traduction anglaise', function () {
    $en = json_decode(file_get_contents(lang_path('en.json')), true);

    expect(array_values(array_diff(OfflineSyncTexts::CLES, array_keys($en))))->toBe([]);
});

test('la page dépose les textes traduits dans la langue de l’utilisateur', function () {
    $this->setUpRbac();
    $this->adminUser->forceFill(['locale' => 'en'])->save();

    $html = $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'window.AVISMART_TEXTES'))->toBeTrue('textes absents de la page');
    expect(str_contains($html, '"Compris, retirer":"Got it, remove"'))->toBeTrue('textes non traduits');
});
