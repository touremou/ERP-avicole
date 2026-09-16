<?php

use App\Models\Module;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * LE VERROU D'UNE ROUTE DOIT DIRE CE QUE SA MÉTHODE EXIGE.
 *
 * Quinze routes annonçaient un droit et en exigeaient un autre : `batches.reopen`
 * portait `can:M` et son contrôleur refusait sans `elevage.S` ; `trash.index`
 * portait `can:L` et exigeait `admin.S` ; le rapport mensuel de trésorerie
 * héritait d'un `elevage.L` et demandait `admin.L`.
 *
 * L'exigence RÉELLE est la conjonction des deux — le middleware s'exécute, puis
 * le contrôleur revérifie. Le verrou de route n'était donc pas faux au sens de
 * la sécurité : il était INCOMPLET. Mais c'est lui que lisent les écrans pour
 * décider d'afficher un bouton, et c'est lui que lit `EveryButtonMatchesItsDoorTest`.
 * Une route qui sous-déclare fait donc offrir des gestes qui retombent sur un
 * refus — le défaut corrigé cinq fois dans cette campagne, vu d'un cran plus haut.
 *
 * Ce test compare, pour chaque route, les droits déclarés par son middleware à
 * celui que sa méthode de contrôleur va vérifier. Il n'y a pas d'exception
 * autorisée : une divergence est soit une route à compléter, soit un contrôleur
 * à corriger.
 *
 * ─── DEUX PIÈGES, NOTÉS POUR QUI REPRENDRA CE BALAYAGE ───
 *
 * 1. `can:M|S` N'EST PAS UN « OU ». Laravel lit l'argument de `can:` comme UN
 *    nom de droit : « M|S » désigne un droit qui n'existe pas, et une route ainsi
 *    écrite refuserait TOUT LE MONDE. Pour exiger deux droits on empile deux
 *    middlewares : `->middleware(['can:M', 'can:S'])`. La première version de ce
 *    correctif employait le pipe — elle aurait fermé huit routes.
 *
 * 2. TOUT `Gate::denies` N'EST PAS UN VERROU. `ClientController::store` en pose
 *    un pour retirer le PLAFOND DE CRÉDIT à un créateur venu de l'Annuaire —
 *    c'est un filtre de champ, pas un refus d'accès, et le comparer au middleware
 *    produit un faux écart. On ne retient donc que les gardes suivies d'un
 *    `return` ou d'un `abort`.
 */

/** Les droits que le middleware d'une route exige réellement. */
function droitsDeclaresParLaRoute($route, string $nom): array
{
    $prefixes = Module::routePrefixMap();
    $gates = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
            continue;
        }

        $argument = substr($middleware, 4);

        if (str_contains($argument, '.')) {
            $gates[] = $argument;
            continue;
        }

        // Verrou NU : le module vient du préfixe du nom de route.
        if (preg_match('/^[LCMS]$/', $argument)) {
            foreach ($prefixes as $prefixe => $module) {
                if (str_starts_with($nom, $prefixe)) {
                    $gates[] = "{$module}.{$argument}";
                    break;
                }
            }
        }
    }

    return array_values(array_unique($gates));
}

/**
 * Le droit qu'une méthode de contrôleur VÉRIFIE avant d'agir.
 *
 * Seules comptent les gardes qui REFUSENT — suivies d'un `return` ou d'un
 * `abort`. Un `Gate::denies` qui se contente de retirer un champ n'est pas un
 * verrou d'accès et n'a pas à figurer sur la route.
 */
function droitVerifieParLeControleur(string $classe, string $methode): ?string
{
    if (! class_exists($classe)) {
        return null;
    }

    try {
        $reflexion = new ReflectionMethod($classe, $methode);
    } catch (Throwable) {
        return null;
    }

    $fichier = $reflexion->getFileName();

    if (! $fichier || ! is_readable($fichier)) {
        return null;
    }

    $lignes = file($fichier);
    $corps  = implode('', array_slice(
        $lignes,
        $reflexion->getStartLine() - 1,
        $reflexion->getEndLine() - $reflexion->getStartLine() + 1,
    ));

    if (preg_match("/Gate::(?:denies|allows)\('([a-z_]+\.[LCMS])'\)\s*\)?\s*\{?\s*(?:return|abort)/", $corps, $m)) {
        return $m[1];
    }

    return null;
}

test('aucune route n’annonce un droit différent de celui que son contrôleur exige', function () {
    $desaccords = [];

    foreach (Route::getRoutes() as $route) {
        $nom = $route->getName();

        if (! $nom) {
            continue;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            continue;   // closure : rien à comparer
        }

        [$classe, $methode] = explode('@', $action);

        $declares = droitsDeclaresParLaRoute($route, $nom);
        $verifie  = droitVerifieParLeControleur($classe, $methode);

        // Route sans verrou, ou méthode sans garde : hors du champ de ce test.
        if (! $declares || ! $verifie) {
            continue;
        }

        if (! in_array($verifie, $declares, true)) {
            $desaccords[] = sprintf(
                '%s — la route annonce [%s], %s::%s exige %s',
                $nom, implode(' + ', $declares), class_basename($classe), $methode, $verifie,
            );
        }
    }

    expect($desaccords)->toBe([], "Verrou de route et contrôleur en désaccord :\n  "
        . implode("\n  ", $desaccords));
});

test('le balayage compare bien quelque chose — il ne se tait pas par erreur', function () {
    /*
     * Un test qui ne mesure rien passe toujours. Si un changement de Laravel ou
     * de mise en forme faisait rendre zéro couple (route, garde) au balayage, le
     * test précédent deviendrait vert et MUET.
     */
    $compares = 0;

    foreach (Route::getRoutes() as $route) {
        $nom = $route->getName();

        if (! $nom || ! str_contains($route->getActionName(), '@')) {
            continue;
        }

        [$classe, $methode] = explode('@', $route->getActionName());

        if (droitsDeclaresParLaRoute($route, $nom) && droitVerifieParLeControleur($classe, $methode)) {
            $compares++;
        }
    }

    expect($compares)->toBeGreaterThan(30);
});
