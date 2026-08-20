<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * UNE RÈGLE ÉNONCÉE EN COMMENTAIRE, QUE RIEN NE FAISAIT RESPECTER.
 *
 * `AppServiceProvider` définit les droits d'un module sous la forme
 * « slug.L / slug.C / slug.M / slug.S ». La liste des slugs vient de la table
 * `modules`, avec une liste de repli en dur quand la table est indisponible
 * (installation, mode hors-ligne).
 *
 * Le commentaire qui l'accompagne est explicite :
 *
 *   « Il DOIT rester synchronisé avec ModuleSeeder […] sinon les gates du
 *     module manquant ne sont pas définies. »
 *
 * Rien ne le vérifiait. Or une capacité NON DÉFINIE est refusée par défaut :
 * un module absent de la liste ne devient pas permissif, il devient
 * INACCESSIBLE — silencieusement, et pour tout le monde sauf l'administrateur,
 * que `Gate::before` laisse passer. Le genre de panne qu'on ne voit qu'en
 * production, sur le poste d'un technicien.
 *
 * ─── CE QUE CE TEST FIXE ───
 *
 * Les trois listes doivent rester d'accord : ce que le code demande, ce que le
 * seeder installe, et ce sur quoi l'application se replie quand la base ne
 * répond pas.
 *
 * À la première écriture, elles l'étaient. Ce test est là pour qu'elles le
 * restent — un module ajouté au seeder sans être ajouté au repli passerait
 * inaperçu jusqu'à la prochaine installation.
 */

/** Les slugs de la liste de repli, lus dans la source du provider. */
function slugsDeRepli(): array
{
    $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain('$fallbackSlugs');

    preg_match('/\$fallbackSlugs\s*=\s*\[(.*?)\];/s', $source, $m);

    expect($m)->not->toBeEmpty('La liste de repli doit rester lisible depuis la source.');

    preg_match_all("/'([a-z_]+)'/", $m[1], $noms);

    return $noms[1];
}

/** Les slugs installés par le seeder. */
function slugsDuSeeder(): array
{
    $source = file_get_contents(database_path('seeders/ModuleSeeder.php'));

    preg_match_all("/'slug'\s*=>\s*'([a-z_]+)'/", $source, $m);

    return array_values(array_unique($m[1]));
}

test('tout module installé par le seeder figure dans la liste de repli', function () {
    /*
     * LE sens qui compte. Un module seedé mais absent du repli perd ses gates
     * dès que la table `modules` est illisible — c'est-à-dire pendant
     * l'installation et en mode hors-ligne, précisément les moments où
     * personne ne surveille.
     */
    $manquants = array_diff(slugsDuSeeder(), slugsDeRepli());

    expect($manquants)->toBe([], 'Modules seedés absents de $fallbackSlugs : ' . implode(', ', $manquants));
});

test('les droits de chaque module seedé sont bien définis', function () {
    /*
     * La preuve par le comportement, et pas seulement par les listes : on
     * interroge le registre des capacités tel que l'application l'a construit.
     *
     * Une capacité non définie est refusée par défaut : le module ne devient
     * pas permissif, il devient inaccessible.
     */
    $absents = [];

    foreach (slugsDuSeeder() as $slug) {
        foreach (['L', 'C', 'M', 'S'] as $droit) {
            if (! Gate::has("{$slug}.{$droit}")) {
                $absents[] = "{$slug}.{$droit}";
            }
        }
    }

    expect($absents)->toBe([], 'Capacités jamais définies (donc refusées à tous) : ' . implode(', ', $absents));
});

test('tout slug employé dans le code est un module connu', function () {
    /*
     * Le sens inverse : une faute de frappe dans « production.M » ne lève
     * aucune erreur — elle refuse l'accès, en silence.
     */
    $connus = array_unique(array_merge(slugsDuSeeder(), slugsDeRepli()));
    $employes = [];

    $racines = [app_path(), base_path('resources/views'), base_path('routes')];

    foreach ($racines as $racine) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));

        foreach ($it as $fichier) {
            if (! $fichier->isFile()) {
                continue;
            }

            if (! preg_match('/\.(php|blade\.php)$/', $fichier->getFilename())) {
                continue;
            }

            preg_match_all("/'([a-z_]+)\.(?:L|C|M|S)'/", file_get_contents($fichier->getPathname()), $m);

            foreach ($m[1] as $slug) {
                $employes[$slug] = true;
            }
        }
    }

    // Le balayage doit trouver quelque chose, sans quoi il passerait à vide.
    expect(count($employes))->toBeGreaterThan(5);

    $inconnus = array_diff(array_keys($employes), $connus);

    expect($inconnus)->toBe([], 'Slugs employés mais inconnus des modules : ' . implode(', ', $inconnus));
});
