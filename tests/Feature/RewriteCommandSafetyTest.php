<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Console\Command;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE MÊME GESTE MONTRAIT DANS UN CAS, ET RÉÉCRIVAIT LES COMPTES DANS L'AUTRE.
 *
 * Quatre commandes réécrivent des chiffres comptables. Elles portaient DEUX
 * conventions de sûreté opposées :
 *
 *   feed:recompute-costs             simulation par défaut, --force pour écrire
 *   stocks:sync                      simulation par défaut, --force pour écrire
 *   couvoir:recompute-chick-costs    ÉCRIT par défaut, --dry-run pour simuler
 *   batches:rebuild-quantities       ÉCRIT par défaut, --dry-run pour simuler
 *
 * `php artisan <commande>` affichait donc des corrections dans un cas et réécrivait
 * les comptes dans l'autre, selon celle qu'on avait tapée. Quelqu'un qui a appris
 * « on lance pour voir, puis --force » sur les deux premières réécrivait ses coûts
 * de poussin sans le vouloir avec la troisième.
 *
 * C'est la même famille de défaut que tout le reste de cet audit : une règle — ici
 * une convention de sûreté — déclarée de deux façons opposées. Sauf qu'ici la
 * divergence ne fausse pas un chiffre, elle fait écrire quelqu'un qui voulait
 * regarder.
 *
 * ─── LE PIÈGE QUE CETTE CORRECTION FAILLIT CRÉER ───
 *
 * `batches:rebuild-quantities` est planifiée chaque nuit (elle a repris cette charge
 * de `stocks:sync`, cf. #215). Basculer sa convention SANS toucher à la ligne du
 * planificateur aurait transformé la réconciliation nocturne en simulation muette :
 * elle aurait continué de tourner, de journaliser, et de ne rien corriger. Soit
 * exactement la panne silencieuse que cet audit passe son temps à débusquer.
 *
 * La ligne passe donc `--force` explicitement, et le dernier test de ce fichier
 * vérifie qu'elle le fait — parce que se souvenir d'un couplage ne suffit pas.
 *
 * `--dry-run` reste ACCEPTÉ par les deux commandes basculées, et vaut le
 * comportement par défaut : une habitude ou un script existant ne doit pas se mettre
 * à écrire du jour au lendemain.
 */

/**
 * Les commandes de réécriture CONNUES — vérifiées nommément, en plus du balayage.
 *
 * Elles ne servent PAS à définir la famille : ce serait reproduire le défaut que ce
 * dépôt corrige partout. Elles servent à détecter l'inverse — qu'on retire un drapeau
 * de sûreté à l'une d'elles, ce qu'un balayage sur les drapeaux ne verrait pas
 * puisqu'elle sortirait alors de la famille.
 */
const KNOWN_REWRITE_COMMANDS = [
    'feed:recompute-costs',
    'stocks:sync',
    'couvoir:recompute-chick-costs',
    'batches:rebuild-quantities',
];

/**
 * La famille, DÉRIVÉE : toute commande de l'application déclarant --force ou --dry-run.
 *
 * Ma première version de ce fichier énumérait quatre noms à la main. Une cinquième
 * commande de réécriture y aurait échappé en silence — exactement le défaut que je
 * venais de reprocher au garde-fou des index uniques (#223), qui reposait lui aussi
 * sur une liste écrite de mémoire. Un garde-fou tenu à la main a la même faille que
 * le code qu'il protège.
 *
 * LIMITE ASSUMÉE, et il vaut mieux l'écrire que la laisser croire couverte : une
 * commande qui écrirait SANS déclarer aucun des deux drapeaux échappe à ce balayage.
 * Elle n'est détectable que par son intention, pas par sa structure. Le contrôle
 * nommé ci-dessus borne le risque sur les quatre qu'on connaît.
 *
 * @return array<int, string>
 */
function safetyFlagCommands(): array
{
    $family = [];

    foreach (\Illuminate\Support\Facades\Artisan::all() as $name => $command) {
        if (! str_starts_with(get_class($command), 'App\\Console\\Commands')) {
            continue;
        }

        $definition = $command->getDefinition();

        if ($definition->hasOption('force') || $definition->hasOption('dry-run')) {
            $family[] = $name;
        }
    }

    return $family;
}

beforeEach(function () {
    $this->setUpRbac();
});

test('aucune commande ne fait de --dry-run son SEUL garde-fou', function () {
    // Le cœur de la divergence : `--dry-run` seul signifie « j'écris par défaut ».
    // Balayage DÉRIVÉ : une commande ajoutée demain avec ce seul drapeau tombera ici.
    $family = safetyFlagCommands();

    // Garde-fou du garde-fou : famille vide = balayage cassé, test creux.
    expect(count($family))->toBeGreaterThan(4);

    $offenders = [];

    foreach ($family as $name) {
        $definition = \Illuminate\Support\Facades\Artisan::all()[$name]->getDefinition();

        if ($definition->hasOption('dry-run') && ! $definition->hasOption('force')) {
            $offenders[] = "{$name} n’a que --dry-run : elle écrit donc par défaut";
        }
    }

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

test('les commandes de réécriture connues portent TOUJOURS --force', function () {
    // Le pendant du balayage : retirer son drapeau à l'une d'elles la ferait SORTIR
    // de la famille dérivée, donc échapper au test précédent. On les nomme donc, non
    // pour définir la règle, mais pour détecter qu'on les en sort.
    $missing = [];

    foreach (KNOWN_REWRITE_COMMANDS as $name) {
        $definition = \Illuminate\Support\Facades\Artisan::all()[$name]->getDefinition();

        if (! $definition->hasOption('force')) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], 'Commandes de réécriture sans --force : ' . implode(', ', $missing));
});

test('batches:rebuild-quantities NE corrige PAS sans --force', function () {
    // Éprouvé sur le comportement, pas sur la signature : une option déclarée mais
    // non lue laisserait le test de forme passer et la commande écrire.
    $batch = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'initial_quantity' => 1000, 'current_quantity' => 400,
    ]);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $batch->id,
        'user_id' => $this->adminUser->id, 'check_date' => now()->subDay()->toDateString(),
        'mortality' => 10,
    ]);

    // Note : créer le pointage déduit déjà la mortalité (400 → 390) — c'est le
    // comportement normal de l'application, pas celui de la commande. Ce qu'on
    // éprouve ici, c'est que la RÉCONCILIATION (qui donnerait 1000 − 10 = 990) ne
    // s'applique pas.
    expect((int) $batch->fresh()->current_quantity)->toBe(390);

    $this->artisan('batches:rebuild-quantities')->assertExitCode(0);

    expect((int) $batch->fresh()->current_quantity)->toBe(390);
});

test('batches:rebuild-quantities corrige AVEC --force', function () {
    // Le pendant indispensable : un garde-fou qui empêche aussi le cas voulu ne
    // protège rien, il casse.
    $batch = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'initial_quantity' => 1000, 'current_quantity' => 400,
    ]);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $batch->id,
        'user_id' => $this->adminUser->id, 'check_date' => now()->subDay()->toDateString(),
        'mortality' => 10,
    ]);

    $this->artisan('batches:rebuild-quantities --force')->assertExitCode(0);

    expect((int) $batch->fresh()->current_quantity)->toBe(990);
});

test('--dry-run reste accepté et vaut le comportement par défaut', function () {
    // Compatibilité : une habitude ou un script existant ne doit ni échouer, ni se
    // mettre à écrire.
    $batch = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'initial_quantity' => 1000, 'current_quantity' => 400,
    ]);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $batch->id,
        'user_id' => $this->adminUser->id, 'check_date' => now()->subDay()->toDateString(),
        'mortality' => 10,
    ]);

    $this->artisan('batches:rebuild-quantities --dry-run')->assertExitCode(0);

    // 390 : la déduction du pointage, sans la réconciliation (qui donnerait 990).
    expect((int) $batch->fresh()->current_quantity)->toBe(390);
});

test('couvoir:recompute-chick-costs annonce sa simulation', function () {
    // Sans données de couvoir la commande n'a rien à corriger — ce qu'on éprouve
    // ici, c'est qu'elle DIT ne rien écrire. Une commande muette laisse croire
    // qu'elle a appliqué.
    $this->artisan('couvoir:recompute-chick-costs')
        ->expectsOutputToContain('Simulation')
        ->assertExitCode(0);
});

test('la tâche de nuit passe --force EXPLICITEMENT', function () {
    /*
     * LE test qui compte dans ce fichier.
     *
     * batches:rebuild-quantities est planifiée chaque nuit. Basculer sa convention
     * sans toucher à la ligne du planificateur l'aurait transformée en simulation
     * muette : elle aurait continué de tourner, de journaliser, et de ne rien
     * corriger. Se souvenir de ce couplage ne suffit pas — on l'écrit.
     */
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->toContain("Schedule::command('batches:rebuild-quantities --force')");

    // Et la tâche est réellement enregistrée avec son drapeau.
    $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command)
        ->implode(' ');

    expect($commands)->toContain('batches:rebuild-quantities')
        ->and($commands)->toContain('--force');
});

test('les commandes de réécriture ne sont pas planifiées SANS --force', function () {
    // Généralisation du test précédent : si l'une des quatre est planifiée un jour,
    // elle doit l'être avec son drapeau, sinon elle tournera pour rien.
    $source = file_get_contents(base_path('routes/console.php'));

    $offenders = [];

    foreach (KNOWN_REWRITE_COMMANDS as $name) {
        if (! preg_match("/Schedule::command\('" . preg_quote($name, '/') . "([^']*)'\)/", $source, $m)) {
            continue;   // non planifiée : rien à vérifier
        }

        if (! str_contains($m[1], '--force')) {
            $offenders[] = "{$name} est planifiée sans --force : elle tournera en simulation, chaque nuit, pour rien";
        }
    }

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});
