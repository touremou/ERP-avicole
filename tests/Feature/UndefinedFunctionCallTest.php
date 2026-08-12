<?php

uses(Tests\TestCase::class);

/*
 * UN SYMBOLE QUI N'EXISTE PAS, INVOQUÉ SEULEMENT À L'EXÉCUTION.
 *
 * `NotificationHub::buildDailySummary()` appelait `yesterday()`. Cette fonction
 * n'existe pas : `yesterday()` est une méthode STATIQUE de Carbon, pas une fonction
 * globale. PHP levait donc, à cette ligne :
 *
 *     Call to undefined function App\Services\yesterday()
 *
 * Conséquence, vérifiée à la main sur cette base avant correction : la commande
 * `avismart:daily-summary` PLANTAIT. Le résumé quotidien n'a jamais été envoyé —
 * sur aucun canal — depuis que ces deux lignes existent. La tâche de 07:00 mourait
 * chaque matin, en silence, et c'est précisément le message dont un promoteur qui
 * vit à l'étranger dépend le plus.
 *
 * ─── POURQUOI LES GARDE-FOUS EXISTANTS NE POUVAIENT PAS L'ATTRAPER ───
 *
 * ConsoleCommandSignatureTest vérifie que chaque commande se CHARGE. Celle-ci se
 * chargeait très bien : l'appel fautif est dans un service, et il ne s'évalue qu'à
 * l'exécution, une fois la journée assez avancée pour atteindre cette ligne.
 *
 * C'est la même famille que l'incident du 14 juillet, à un cran de plus : là,
 * l'erreur survenait au chargement de la classe ; ici, à mi-parcours d'une méthode.
 * Le point commun est le seul qui compte : la panne vit dans une tâche planifiée
 * que personne ne regarde tourner.
 *
 * ─── CE QUE FAIT CE TEST ───
 *
 * Il lit le code — application, routes, migrations, ET toutes les vues Blade une
 * fois compilées — et relève chaque appel de fonction GLOBALE qui n'existe pas.
 * L'analyse porte sur les jetons PHP, pas sur une expression régulière : il faut
 * distinguer `foo()` d'un appel de méthode `$x->foo()`, d'un appel statique
 * `C::foo()`, d'une déclaration, d'une instanciation.
 *
 * Il a trouvé exactement une famille de défauts sur cette base — celle ci-dessus.
 */

test('aucun appel à une fonction globale INEXISTANTE', function () {
    $directories = array_filter([
        base_path('app'),
        base_path('routes'),
        base_path('database'),
    ], 'is_dir');

    // Les vues comptent AUTANT que le reste : une page qui appelle une fonction
    // absente rend un 500, et c'est arrivé sur cette base (cf. le lot #204, où une
    // vue ne compilait plus). On les compile pour les analyser en PHP.
    \Illuminate\Support\Facades\Artisan::call('view:cache');

    if (is_dir($compiled = storage_path('framework/views'))) {
        $directories[] = $compiled;
    }

    $offenders = [];
    $scanned = 0;

    foreach ($directories as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $tokens = token_get_all(file_get_contents($file->getPathname()));
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                    continue;
                }

                $name = $tokens[$i][1];

                // Ce qui PRÉCÈDE dit s'il s'agit d'un appel de fonction globale.
                $prev = $i - 1;

                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }

                if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [
                    T_OBJECT_OPERATOR,              // $x->foo()
                    T_NULLSAFE_OBJECT_OPERATOR,     // $x?->foo()
                    T_DOUBLE_COLON,                 // C::foo()
                    T_FUNCTION,                     // function foo()
                    T_NEW,                          // new Foo()
                    T_CLASS,
                    T_STRING,                       // Type foo() — déclaration typée
                    T_NS_SEPARATOR,                 // \Foo()
                ], true)) {
                    continue;
                }

                // Doit être SUIVI d'une parenthèse ouvrante.
                $next = $i + 1;

                while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }

                if ($next >= $count || $tokens[$next] !== '(') {
                    continue;
                }

                if (function_exists($name) || class_exists($name) || interface_exists($name)) {
                    continue;
                }

                // Constructions du langage : elles ressemblent à des appels mais
                // n'en sont pas, et function_exists() les ignore.
                if (in_array(strtolower($name), [
                    'array', 'isset', 'unset', 'empty', 'list', 'echo', 'print', 'exit', 'die',
                    'include', 'include_once', 'require', 'require_once', 'match', 'fn',
                    'static', 'self', 'parent', 'clone', 'instanceof', 'yield',
                    'if', 'elseif', 'while', 'for', 'foreach', 'switch', 'catch', 'return', 'use',
                ], true)) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d — %s()', str_replace(base_path() . '/', '', $file->getPathname()), $tokens[$i][2], $name);
            }
        }
    }

    \Illuminate\Support\Facades\Artisan::call('view:clear');

    // Garde-fou du garde-fou : si le balayage ne lit plus rien, il passerait en ne
    // vérifiant rien.
    expect($scanned)->toBeGreaterThan(300);

    expect($offenders)->toBe([], "Appels à des fonctions inexistantes — erreur fatale à l’exécution :\n  " . implode("\n  ", $offenders));
});
