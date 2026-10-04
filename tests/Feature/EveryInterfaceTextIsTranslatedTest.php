<?php

use Symfony\Component\Finder\Finder;

uses(Tests\TestCase::class);

/*
 * AUCUN TEXTE D'INTERFACE SANS TRADUCTION ANGLAISE.
 *
 * L'application se dit bilingue (`app.supported_locales` = fr, en ; chaque
 * compte choisit sa langue). Mesuré par l'audit : 1 643 des 4 730 textes
 * passés à `__()` n'avaient AUCUNE entrée dans lang/en.json — un utilisateur
 * réglé en anglais lisait « Tableau de bord », « Trésorerie », les registres
 * HACCP de l'abattoir… en français. Rien ne le signalait : `__()` rend la clé,
 * c'est-à-dire le français, sans erreur.
 *
 * La convention de l'application : la clé EST le texte français. Ce test
 * relève chaque texte LITTÉRAL passé à `__()`, `trans()` ou `@lang()` dans les
 * vues, le code et les routes, et exige sa traduction. Restent hors de sa
 * portée : les clés de fichiers PHP (`auth.failed`) et les clés calculées
 * (`__($variable)`) — qu'on ne peut lire sans exécuter le code.
 */

/** @return array<string, string> clé => premier emplacement */
function textesDInterface(): array
{
    $appel = '/(?:\b__|\btrans|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")\s*[,)]/s';
    $textes = [];

    $fichiers = Finder::create()->files()->name('*.php')
        ->in([resource_path('views'), app_path(), base_path('routes')]);

    foreach ($fichiers as $fichier) {
        preg_match_all($appel, $fichier->getContents(), $trouves, PREG_SET_ORDER);

        foreach ($trouves as $m) {
            $cle = isset($m[2]) && $m[2] !== ''
                ? stripcslashes($m[2])                                    // "…" : échappements PHP
                : str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);       // '…'

            if ($cle === '' || preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $cle) || str_contains($cle, '{{')) {
                continue; // clé de fichier PHP, ou fragment Blade
            }

            $textes[$cle] ??= $fichier->getRelativePathname();
        }
    }

    return $textes;
}

test('chaque texte d’interface a sa traduction anglaise', function () {
    $en = json_decode(file_get_contents(lang_path('en.json')), true);
    $fr = json_decode(file_get_contents(lang_path('fr.json')), true); // textes sources anglais (paquets)

    $textes = textesDInterface();
    expect(count($textes))->toBeGreaterThan(1000); // le relevé fonctionne

    $manquants = collect($textes)
        ->reject(fn ($ou, $cle) => array_key_exists($cle, $en) || array_key_exists($cle, $fr))
        ->map(fn ($ou, $cle) => "{$ou} : {$cle}")
        ->values()->all();

    expect($manquants)->toBe([]);
});

test('aucune traduction ne perd un paramètre (:name, :count…)', function () {
    $en = json_decode(file_get_contents(lang_path('en.json')), true);

    $perdus = collect($en)->filter(function ($traduction, $cle) {
        // Un paramètre n'est jamais collé à un mot (« nom:quantité » est un
        // format affiché tel quel, pas trois paramètres).
        preg_match_all('/(?<![\\pL\\d]):([a-zA-Z_]+)/u', $cle, $p);

        // `:daysj` = `:days` suivi de « j » (jours) : Laravel remplace `:days`
        // à l'intérieur. Un paramètre est donc gardé si la traduction porte le
        // nom, ou ce nom amputé d'une lettre d'unité finale.
        return collect($p[1])->contains(fn ($nom) => ! preg_match('/:'.$nom.'(?![\\pL\\d_])/u', (string) $traduction)
            && ! preg_match('/:'.substr($nom, 0, -1).'(?![\\pL\\d_])/u', (string) $traduction));
    })->keys()->all();

    expect($perdus)->toBe([]);
});
