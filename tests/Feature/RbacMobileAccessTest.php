<?php

use App\Services\Sync\SyncService;

uses(Tests\TestCase::class);

/** Racine du dépôt — sans dépendre du conteneur (ce test ne touche pas la base). */
function repoPath(string $relative): string
{
    return dirname(__DIR__, 2) . '/' . $relative;
}

/*
 * MIROIR MOBILE DU RBAC — le contrat entre SyncService et la PWA.
 *
 * La PWA garde ses écrans et son outbox avec `mobile/src/offline/access.ts`,
 * qui recopie les `Gate::denies()` de SyncService. Une copie dérive toujours :
 * le jour où une op est ajoutée côté serveur (ou son niveau durci), la table
 * mobile reste en arrière, et le symptôme est silencieux —
 *   - op absente du miroir : l'écran s'ouvre, la saisie monte, le serveur la
 *     refuse, elle atterrit dans « À corriger », un bac que l'agent ne peut
 *     pas vider ;
 *   - niveau trop faible côté mobile : idem, mais on l'a laissé travailler
 *     pour rien ;
 *   - niveau trop fort côté mobile : on lui refuse un droit qu'il a.
 *
 * Ce test lit le fichier TypeScript et le confronte au serveur. Il échoue à
 * l'ajout d'une op non déclarée, avant la mise en production.
 */

/** Table OP_ACCESS du miroir mobile : op → droit exigé. */
function mobileOpAccess(): array
{
    $path = repoPath('mobile/src/offline/access.ts');
    expect(file_exists($path))->toBeTrue("Miroir mobile absent : {$path}");

    $source = file_get_contents($path);
    $start = strpos($source, 'export const OP_ACCESS');
    $end = strpos($source, '}', $start);
    $block = substr($source, $start, $end - $start);

    preg_match_all("/'([a-z_]+\.[a-z_]+)':\s*'([^']+)'/", $block, $m, PREG_SET_ORDER);

    return array_column($m, 2, 1);
}

/** Gates réellement exigées par chaque handler de SyncService. */
function serverOpGates(): array
{
    $source = file_get_contents(repoPath('app/Services/Sync/SyncService.php'));
    $gates = [];

    foreach (SyncService::types() as $op => $method) {
        $at = strpos($source, "function {$method}(array");
        expect($at)->not->toBeFalse("Handler introuvable pour {$op}");

        // Les gates d'un handler sont posées en tête de méthode ; on borne la
        // lecture au début du corps pour ne pas capter celles du handler suivant.
        preg_match_all("/Gate::denies\('([\w.]+)'\)/", substr($source, $at, 1200), $m);
        $gates[$op] = array_values(array_unique($m[1]));
    }

    return $gates;
}

test('chaque opération de synchro est déclarée dans le miroir mobile', function () {
    $missing = array_diff(array_keys(SyncService::types()), array_keys(mobileOpAccess()));

    expect($missing)->toBe([], "Opération(s) absente(s) de mobile/src/offline/access.ts :\n  "
        . implode("\n  ", $missing)
        . "\nSans déclaration, la PWA ne peut ni cacher l'écran ni refuser la mise en file.");
});

test('le miroir mobile ne déclare aucune opération inexistante', function () {
    $extra = array_diff(array_keys(mobileOpAccess()), array_keys(SyncService::types()));

    expect($extra)->toBe([], "Opération(s) déclarée(s) côté mobile mais inconnue(s) du serveur :\n  "
        . implode("\n  ", $extra));
});

test('le droit déclaré côté mobile correspond à la gate du serveur', function () {
    $mobile = mobileOpAccess();
    $server = serverOpGates();

    // Ops à PORTÉE PERSONNELLE : le serveur n'y exige aucun droit de module
    // (gérer sa propre liste de tâches), il vérifie la propriété réelle de la
    // tâche. Le mobile exige '@owner' — avoir une fiche employé.
    $ownerScoped = ['task.complete', 'task.start', 'task.release', 'task.create'];

    // batch.upsert choisit sa gate à l'exécution (elevage.C en création,
    // elevage.M sur un lot existant) : le mobile retient le minimum, le cas du
    // terrain étant la création.
    $expected = ['batch.upsert' => 'elevage.C'];

    $mismatch = [];

    foreach ($server as $op => $gates) {
        if (in_array($op, $ownerScoped, true)) {
            if ($mobile[$op] !== '@owner') {
                $mismatch[] = "{$op} : mobile « {$mobile[$op]} », attendu « @owner » (portée personnelle)";
            }
            continue;
        }

        // Plusieurs Gate::denies enchaînées par && = accès si l'UNE passe :
        // le miroir l'écrit « a|b ».
        $want = $expected[$op] ?? implode('|', $gates);

        if ($want !== '' && $mobile[$op] !== $want) {
            $mismatch[] = "{$op} : mobile « {$mobile[$op]} », serveur « {$want} »";
        }
    }

    expect($mismatch)->toBe([], "Divergence(s) entre le miroir mobile et SyncService :\n  "
        . implode("\n  ", $mismatch));
});

test('chaque écran de la PWA déclare un droit d’accès', function () {
    $app = file_get_contents(repoPath('mobile/src/app/App.tsx'));
    $access = file_get_contents(repoPath('mobile/src/offline/access.ts'));

    // Les écrans sont déclarés dans une table typée par ROUTE_ACCESS : le
    // compilateur refuse déjà un chemin non déclaré. On vérifie ici que
    // personne n'a réintroduit un <Route> littéral hors de la table — ce qui
    // contournerait la garde sans que TypeScript s'en plaigne.
    preg_match_all('/<Route\s+path="([^"]+)"/', $app, $m);
    $literal = array_diff($m[1], ['*']);

    expect(array_values($literal))->toBe([], "<Route> posé(s) hors de la table SCREENS :\n  "
        . implode("\n  ", $literal)
        . "\nAjoutez le chemin à ROUTE_ACCESS et l'écran à SCREENS pour qu'il soit gardé.");

    // Et que la table de droits couvre bien tous les écrans listés.
    preg_match_all("/\['([^']+)',\s*<\w+ \/>\]/", $app, $screens);
    $declared = [];
    $start = strpos($access, 'export const ROUTE_ACCESS');
    preg_match_all("/'([^']+)':\s*'([^']+)'/", substr($access, $start, strpos($access, '} as const', $start) - $start), $rm, PREG_SET_ORDER);
    foreach ($rm as $one) {
        $declared[$one[1]] = $one[2];
    }

    $undeclared = array_diff($screens[1], array_keys($declared));

    expect(array_values($undeclared))->toBe([], "Écran(s) sans droit déclaré :\n  " . implode("\n  ", $undeclared));
    expect(count($screens[1]))->toBeGreaterThan(40); // garde-fou : la table n'a pas été vidée
});
