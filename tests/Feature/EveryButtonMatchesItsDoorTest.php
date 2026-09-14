<?php

use App\Models\Module;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * UN BOUTON DOIT DEMANDER LE DROIT QUE SA PORTE EXIGE.
 *
 * Ce défaut a été corrigé quatre fois dans cette campagne, à quatre endroits
 * sans rapport entre eux — le « Référentiel Normes », l'« Achat direct aliment »,
 * les étapes de protocole, le bouton « Payer » de la paie. À chaque fois la même
 * forme : un `@can` d'écran et un verrou de route qui ne disent pas la même
 * chose, et le défaut frappe dans les DEUX sens :
 *
 *   • l'écran OFFRE un geste que la porte refuse — l'utilisateur remplit un
 *     formulaire entier et se fait renvoyer. Un bouton qui échoue toujours vaut
 *     moins que pas de bouton ;
 *   • l'écran CACHE un geste que la porte accepte — le titulaire du droit ne
 *     peut pas s'en servir, et quand cet écran est l'unique porte, son droit est
 *     purement inutilisable.
 *
 * Quatre corrections ponctuelles ne valent pas une règle. Ce test balaie donc
 * TOUS les formulaires de TOUTES les vues et vérifie que le `@can` qui les
 * entoure fait partie de ce que leur route exige.
 *
 * ─── CE QUE LE BALAYAGE NE PREND PAS ───
 *
 * • Les simples LIENS (`<a href>`) : un bloc « modifier » peut légitimement
 *   contenir un lien vers l'écran de lecture d'un autre module. Seuls les
 *   FORMULAIRES sont retenus — là où un geste part vraiment.
 * • Les routes SANS verrou : leur garde est portée par le contrôleur, et ce
 *   balayage ne lit pas les contrôleurs.
 * • Une route peut exiger PLUSIEURS droits (une resource porte `can:L` de groupe
 *   plus `can:C` sur `store`) : on vérifie l'APPARTENANCE du garde à cet
 *   ensemble, pas l'égalité. Sans quoi le test crierait sur des écrans justes.
 *
 * ─── DEUX PIÈGES RENCONTRÉS EN L'ÉCRIVANT, notés pour qui le reprendra ───
 *
 * 1. Déduire le module du NOM de la route est faux quand la route porte un
 *    verrou EXPLICITE (`can:admin.S`) : il faut lire le middleware réel. Une
 *    première version signalait 25 écarts dont la plupart n'en étaient pas.
 * 2. Ne lire que le PREMIER `can:` d'une route est faux pour les mêmes raisons
 *    qu'au point précédent. Il en restait 19, encore majoritairement faux.
 *
 * Le balayage correct en trouve cinq, tous vérifiés à la main.
 */

/** Tous les gates qu'une route exige réellement (une route peut en cumuler). */
function gatesExigesPar(string $nomDeRoute): array
{
    $route = Route::getRoutes()->getByName($nomDeRoute);

    if (! $route) {
        return [];
    }

    $prefixes = Module::routePrefixMap();
    $gates = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
            continue;
        }

        $argument = substr($middleware, 4);

        // Verrou EXPLICITE : « can:admin.S ».
        if (str_contains($argument, '.')) {
            $gates[] = $argument;
            continue;
        }

        // Verrou NU : « can:M », « can:L|C » — le module vient du nom de route.
        if (preg_match('/^[LCMS](\|[LCMS])*$/', $argument)) {
            foreach ($prefixes as $prefixe => $module) {
                if (str_starts_with($nomDeRoute, $prefixe)) {
                    foreach (explode('|', $argument) as $lettre) {
                        $gates[] = "{$module}.{$lettre}";
                    }
                    break;
                }
            }
        }
    }

    return array_values(array_unique($gates));
}

/**
 * Chaque formulaire de chaque vue, avec le `@can` qui l'entoure.
 *
 * Lu fichier par fichier, sans jamais construire une chaîne géante : la suite
 * complète tient dans une enveloppe mémoire bornée, et un balayage de vues est
 * exactement le genre de test qui la fait déborder.
 *
 * @return list<array{fichier: string, ligne: int, garde: string, route: string}>
 */
function formulairesGardesDesVues(): array
{
    $racine = resource_path('views');
    $trouves = [];

    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));

    foreach ($fichiers as $fichier) {
        if (! $fichier->isFile() || ! str_ends_with($fichier->getFilename(), '.blade.php')) {
            continue;
        }

        $pile = [];
        $numero = 0;

        foreach (file($fichier->getPathname()) as $ligne) {
            $numero++;

            if (preg_match("/@can\('([a-z_]+)\.([LCMS])'\)/", $ligne, $m)) {
                $pile[] = "{$m[1]}.{$m[2]}";
                continue;
            }

            if (str_contains($ligne, '@endcan')) {
                array_pop($pile);
                continue;
            }

            if (! $pile) {
                continue;
            }

            if (preg_match("/action=\"\{\{\s*route\('([a-zA-Z0-9_.\-]+)'/", $ligne, $m)) {
                $trouves[] = [
                    'fichier' => str_replace($racine . '/', '', $fichier->getPathname()),
                    'ligne'   => $numero,
                    'garde'   => end($pile),
                    'route'   => $m[1],
                ];
            }
        }
    }

    return $trouves;
}

test('aucun formulaire ne demande un droit que sa route n’exige pas', function () {
    $desaccords = [];

    foreach (formulairesGardesDesVues() as $f) {
        $gates = gatesExigesPar($f['route']);

        // Route sans verrou : sa garde est au contrôleur, hors de portée d'ici.
        if (! $gates) {
            continue;
        }

        if (! in_array($f['garde'], $gates, true)) {
            $desaccords[] = sprintf(
                "%s:%d — @can('%s') autour d'un formulaire vers %s, qui exige [%s]",
                $f['fichier'], $f['ligne'], $f['garde'], $f['route'], implode(' + ', $gates),
            );
        }
    }

    expect($desaccords)->toBe([], "Écran et porte en désaccord :\n  " . implode("\n  ", $desaccords));
});

test('le balayage trouve bien des formulaires — il ne se tait pas par erreur', function () {
    /*
     * Un test qui ne mesure rien passe toujours. Si une évolution de Blade, un
     * changement de mise en forme ou une erreur de chemin faisait rendre zéro
     * formulaire au balayage, le test précédent deviendrait vert et MUET.
     *
     * On exige donc qu'il en trouve un nombre plausible, et que les routes qu'il
     * relève portent bien des verrous — sans quoi il ne compare rien.
     */
    $formulaires = formulairesGardesDesVues();

    expect(count($formulaires))->toBeGreaterThan(100);

    $avecVerrou = array_filter($formulaires, fn ($f) => gatesExigesPar($f['route']) !== []);

    expect(count($avecVerrou))->toBeGreaterThan(50);
});
