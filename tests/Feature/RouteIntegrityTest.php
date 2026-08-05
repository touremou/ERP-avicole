<?php

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

/*
 * DES ROUTES QUI MÈNENT À UNE ERREUR.
 *
 * Une route déclarée vers une méthode de contrôleur qui n'existe pas ne se
 * signale nulle part : `route:list` l'affiche comme les autres, la vue génère
 * son lien sans broncher, et l'erreur n'apparaît qu'au clic de l'utilisateur.
 *
 * Huit routes étaient dans cet état, dont deux réellement cliquables : le bouton
 * « Export » de l'écran des stocks, et le crayon de rectification en face de
 * chaque achat d'aliment sur la fiche de bande. Un bouton visible qui échoue est
 * pire qu'un bouton absent : on le reclique, on doute de sa saisie, on appelle.
 *
 * Trois causes distinctes, aucune détectable à la lecture :
 *   • une méthode jamais écrite alors que sa vue, sa validation et son action
 *     l'étaient (rectification d'un achat d'aliment) ;
 *   • un mauvais câblage — la route pointait sur `store`, la méthode s'appelle
 *     `storeMovement` ;
 *   • `Route::resource()`, qui déclare les sept verbes même quand le contrôleur
 *     n'en implémente que six.
 *
 * Ces deux tests coûtent une seconde et ferment la porte définitivement.
 */

test('toute route pointe vers une action qui existe', function () {
    $broken = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();

        // Closures et vues inline : rien à résoudre.
        if (! str_contains($action, '@')) {
            continue;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class)) {
            $broken[] = ($route->getName() ?: $route->uri()) . '  →  CLASSE INTROUVABLE ' . $class;
            continue;
        }

        if (! method_exists($class, $method)) {
            $broken[] = ($route->getName() ?: $route->uri()) . '  →  ' . $action . ' (méthode absente)';
        }
    }

    // Garde-fou du garde-fou : si l'énumération ne trouve plus rien à vérifier,
    // c'est elle qui est cassée, et le test passerait en ne vérifiant rien.
    expect(count(Route::getRoutes()))->toBeGreaterThan(300);

    expect($broken)->toBe([], "Routes menant à une erreur serveur :\n  " . implode("\n  ", $broken));
});

test('aucune vue ne fabrique un lien vers une route inexistante', function () {
    // L'autre bout du même défaut : `route('machin.show')` sur un nom qui
    // n'existe pas lève une exception au RENDU de la page — la page entière
    // tombe, pas seulement le lien.
    $declared = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter()->flip();

    $missing = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($files as $file) {
        if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $no => $line) {
            if (! preg_match_all("/(?<![\\\$>])\\broute\(\s*'([a-zA-Z0-9_.-]+)'/", $line, $matches)) {
                continue;
            }

            foreach ($matches[1] as $name) {
                // `$request->route('token')` / `->route('mail')` : lecture d'un
                // paramètre d'URL ou canal de notification, pas un nom de route.
                // Le motif ci-dessus les écarte déjà ; ceci couvre les variantes.
                if (! str_contains($name, '.')) {
                    continue;
                }

                if (! $declared->has($name)) {
                    $missing[] = $name . '  (' . str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($no + 1) . ')';
                }
            }
        }
    }

    expect(array_unique($missing))->toBe([], "Vues référençant une route inexistante :\n  " . implode("\n  ", array_unique($missing)));
});
