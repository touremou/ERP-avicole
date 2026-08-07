<?php

uses(Tests\TestCase::class);

/*
 * LA RELEASE LIVRÉE FIGEAIT SA CONFIGURATION SANS LE .ENV DU CLIENT.
 *
 * `scripts/package-release.sh` exclut volontairement `.env` de la copie — les
 * secrets du fournisseur n'ont rien à faire chez le client. Puis il lançait
 * `php artisan optimize` DANS cette copie dépourvue de .env. Or `optimize`
 * inclut `config:cache`.
 *
 * VÉRIFIÉ EMPIRIQUEMENT en rejouant l'empaquetage : le cache produit contient
 * app.key = NULL, app.debug = false, database.default = « sqlite » — les valeurs
 * par défaut du code, pas celles du client.
 *
 * Et le pire est la conséquence : dès qu'un cache de configuration existe,
 * Laravel NE LIT PLUS le .env du tout (Bootstrap\LoadEnvironmentVariables sort
 * immédiatement si la configuration est en cache). Le .env que l'exploitant
 * remplit consciencieusement reste donc sans le moindre effet.
 *
 * SYMPTÔME MESURÉ, et il est plus sournois qu'un plantage franc : les pages
 * redirigent vers l'installeur, et l'installeur échoue en tentant d'ouvrir une
 * base sqlite au chemin de la machine du FOURNISSEUR — derrière une page
 * d'erreur muette, app.debug étant figé à false.
 *
 * HONNÊTETÉ SUR LA PORTÉE : le runbook demande bien à l'exploitant de lancer
 * `config:cache` après avoir posé son .env, ce qui écrase le cache fautif. Ce
 * n'est donc pas une livraison morte à coup sûr — c'est une mine, qui n'explose
 * que si l'étape documentée est omise ou faite dans le mauvais ordre. On ne fait
 * pas dépendre le démarrage d'un ordre de manipulations : on ne livre plus de
 * cache de configuration du tout.
 */

function packagingScript(): string
{
    return file_get_contents(base_path('scripts/package-release.sh'));
}

/**
 * Le script PRIVÉ de ses lignes de commentaire : ce qui s'exécute réellement.
 *
 * Sans ce filtrage, la longue explication du défaut — qui doit nommer
 * `config:cache` pour être compréhensible — ferait échouer le test censé
 * interdire son APPEL. Un garde-fou qui interdit de parler du défaut qu'il
 * prévient serait absurde.
 */
function packagingCommands(): string
{
    return collect(explode("\n", packagingScript()))
        ->reject(fn ($line) => str_starts_with(ltrim($line), '#'))
        ->implode("\n");
}

test('l’empaquetage ne fabrique JAMAIS de cache de configuration', function () {
    $commands = packagingCommands();

    // `optimize` inclut config:cache : c'était la cause exacte.
    expect($commands)->not->toContain('artisan optimize')
        ->and($commands)->not->toContain('config:cache');

    // Les caches qui ne dépendent pas du .env restent bienvenus : ils font
    // gagner du temps au démarrage sans rien figer de l'environnement.
    expect($commands)->toContain('route:cache')
        ->and($commands)->toContain('view:cache');
});

test('le .env reste exclu de la copie — c’est bien le comportement voulu', function () {
    // Le défaut n'était pas d'exclure le .env, mais de mettre la config en cache
    // SANS lui. On vérifie donc que l'exclusion, elle, n'a pas été « corrigée »
    // par erreur : livrer le .env du fournisseur serait bien pire.
    $script = packagingCommands();

    expect($script)->toContain("--exclude '.env'")
        ->and($script)->toContain("--exclude '.env.*'");
});

test('les caches de la machine du fournisseur ne partent pas dans la copie', function () {
    // Même défaut par une autre porte : si le poste d'empaquetage possède son
    // propre bootstrap/cache/config.php, rsync le recopiait tel quel — avec les
    // identifiants de base du fournisseur et son app.key.
    expect(packagingCommands())->toContain("--exclude 'bootstrap/cache/*.php'");
});

test('l’empaquetage REFUSE de finir si un cache de configuration a été produit', function () {
    // Un garde-fou dans le script lui-même : les invariants ci-dessus portent sur
    // le texte, celui-ci porte sur le résultat. Si une commande future réintroduit
    // un config:cache, la livraison s'arrête au lieu de partir chez le client.
    $script = packagingCommands();

    expect($script)->toContain('bootstrap/cache/config.php')
        ->and($script)->toContain('exit 1');
});

test('le script reste syntaxiquement valide', function () {
    // Un script de livraison cassé ne se voit qu'au moment de livrer.
    exec('bash -n ' . escapeshellarg(base_path('scripts/package-release.sh')) . ' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
});
