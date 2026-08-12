<?php

use Illuminate\Console\Command;

uses(Tests\TestCase::class);

/*
 * UNE MÉTHODE PRIVÉE QUI MASQUE UNE MÉTHODE PUBLIQUE DE LARAVEL = ERREUR FATALE.
 *
 * PHP refuse de réduire la visibilité d'une méthode héritée. Déclarer
 * `private function fail()` dans une classe qui étend Illuminate\Console\Command
 * — où `fail()` est publique — provoque une erreur FATALE au CHARGEMENT de la
 * classe, avant même son exécution :
 *
 *     Access level to App\Console\Commands\X::fail() must be public
 *
 * Ce n'est pas une hypothèse. Journal de production du 14 juillet :
 *
 *     [production.ERROR] Access level to
 *     App\Console\Commands\CheckHaccpRegisters::alert() must be public
 *
 * La commande planifiée de contrôle des registres HACCP était donc MORTE — et,
 * comble de tout, l'alerte qui devait signaler cette panne a elle-même échoué
 * (cf. ErrorReporterResilienceTest). Personne n'a rien su.
 *
 * Le défaut ne se voit ni à la relecture, ni à l'analyse statique ordinaire : le
 * nom `alert()` paraît anodin, et rien ne rappelle qu'il appartient déjà au parent.
 * On l'apprend quand la tâche planifiée cesse silencieusement de tourner.
 *
 * J'ai reproduit ce défaut à l'identique en écrivant la commande de diagnostic —
 * `private function fail()`. C'est ce qui a motivé ce test.
 *
 * ─── CE QUE CE FICHIER FAIT RÉELLEMENT ───
 *
 * Une erreur FATALE de PHP n'est pas rattrapable : lorsqu'une commande porte le
 * défaut, la suite ne récite pas la liste ci-dessous — elle S'ARRÊTE, en affichant
 * « Access level to ... must be public ». Vérifié en réintroduisant le défaut
 * volontairement.
 *
 * Ce n'est pas un défaut de ce test, c'en est tout l'intérêt : le simple fait de
 * CHARGER chaque commande déplace la panne du planificateur silencieux (où personne
 * ne l'a vue pendant des semaines) vers l'intégration continue, qui refuse de
 * passer. Le message soigné ne sert donc qu'aux cas non fatals — une méthode
 * PROTÉGÉE masquant une publique, que PHP accepte parfois selon la version.
 */

test('aucune commande ne réduit la visibilité d’une méthode de Laravel', function () {
    $publicOnCommand = [];

    foreach ((new ReflectionClass(Command::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $publicOnCommand[$method->getName()] = true;
    }

    // Garde-fou du garde-fou : si la réflexion ne trouve plus rien, le test
    // passerait en ne vérifiant rien.
    expect(count($publicOnCommand))->toBeGreaterThan(20);

    $offenders = [];

    foreach (glob(app_path('Console/Commands/*.php')) as $file) {
        $class = 'App\\Console\\Commands\\' . basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isSubclassOf(Command::class)) {
            continue;
        }

        foreach ($reflection->getMethods() as $method) {
            // Seules les méthodes DÉCLARÉES par la commande comptent : celles
            // héritées portent la visibilité du parent.
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if ($method->isPublic()) {
                continue;
            }

            if (isset($publicOnCommand[$method->getName()])) {
                $offenders[] = sprintf(
                    '%s::%s() est %s alors que Command::%s() est publique',
                    basename($file, '.php'),
                    $method->getName(),
                    $method->isPrivate() ? 'privée' : 'protégée',
                    $method->getName()
                );
            }
        }
    }

    expect($offenders)->toBe([], "Erreur fatale au chargement — visibilité réduite :\n  " . implode("\n  ", $offenders));
});

test('toute commande se CHARGE réellement', function () {
    // Le test ci-dessus cible une cause précise. Celui-ci vérifie l'effet, quelle
    // qu'en soit la cause : une commande qui ne s'instancie pas est une tâche
    // planifiée morte, et le planificateur échoue en silence.
    $broken = [];

    foreach (glob(app_path('Console/Commands/*.php')) as $file) {
        $class = 'App\\Console\\Commands\\' . basename($file, '.php');

        try {
            if (! class_exists($class)) {
                $broken[] = basename($file, '.php') . ' : classe introuvable';
                continue;
            }

            app($class);
        } catch (\Throwable $e) {
            $broken[] = basename($file, '.php') . ' : ' . $e->getMessage();
        }
    }

    expect($broken)->toBe([], "Commandes qui ne se chargent pas :\n  " . implode("\n  ", $broken));
});

test('chaque commande déclarée est réellement enregistrée par Artisan', function () {
    // Une commande dont la signature est mal formée n'apparaît pas dans la liste
    // d'Artisan : elle n'est appelable ni à la main ni par le planificateur, et
    // aucune erreur ne le dit.
    $registered = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());

    $missing = [];

    foreach (glob(app_path('Console/Commands/*.php')) as $file) {
        $class = 'App\\Console\\Commands\\' . basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isSubclassOf(Command::class) || $reflection->isAbstract()) {
            continue;
        }

        $signature = $reflection->getDefaultProperties()['signature'] ?? null;

        if (! $signature) {
            continue;   // commande à `name` plutôt qu'à `signature` : hors sujet
        }

        $name = strtok((string) $signature, " \n");

        if (! in_array($name, $registered, true)) {
            $missing[] = basename($file, '.php') . " (signature « {$name} »)";
        }
    }

    expect($missing)->toBe([], "Commandes non enregistrées par Artisan :\n  " . implode("\n  ", $missing));
});
