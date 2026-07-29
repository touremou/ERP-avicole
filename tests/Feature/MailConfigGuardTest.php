<?php

/*
 * CONFIGURATION SMTP — supprimer le piège plutôt que le diagnostiquer.
 *
 * Signalé depuis le terrain, capture à l'appui :
 *
 *   « Failed to authenticate on SMTP server … [host=node118-eu.n0c.com:465
 *     scheme=auto user=s.avismart@biocrest.fr from=no-reply@biocrest.fr] »
 *
 * Deux réglages contredisaient la documentation de déploiement, et chacun suffit
 * à faire échouer l'authentification :
 *
 *   1. PORT 465 SANS « smtps ». Le 465 attend une poignée de main chiffrée AVANT
 *      toute commande. Sans le schéma, le client envoie un EHLO en clair et le
 *      serveur refuse — en parlant d'IDENTIFIANTS, alors que le mot de passe est
 *      bon. Le diagnostic du serveur oriente donc vers la mauvaise cause.
 *
 *   2. EXPÉDITEUR ≠ COMPTE AUTHENTIFIÉ. La plupart des hébergements mutualisés
 *      le refusent. Invisible dans le message brut.
 *
 * Le premier est désormais DÉDUIT du port : le chiffrement est une conséquence
 * du port, pas une décision indépendante — le redemander, c'est offrir deux
 * façons de se contredire. Le second ne peut pas être deviné (l'adresse voulue
 * est une décision), mais il est NOMMÉ quand on le voit.
 */

uses(Tests\TestCase::class);

test('le port 465 implique le chiffrement implicite, sans réglage de plus', function () {
    config(['mail.mailers.smtp' => null]);
    putenv('MAIL_SCHEME=');
    putenv('MAIL_PORT=465');

    $config = require base_path('config/mail.php');

    expect($config['mailers']['smtp']['scheme'])->toBe('smtps');
});

test('le port 587 reste en STARTTLS', function () {
    putenv('MAIL_SCHEME=');
    putenv('MAIL_PORT=587');

    $config = require base_path('config/mail.php');

    expect($config['mailers']['smtp']['scheme'])->toBeNull();
});

test('un schéma explicite garde la priorité — serveur non standard', function () {
    // La déduction est une commodité, pas une contrainte : un hôte exotique doit
    // rester configurable.
    putenv('MAIL_SCHEME=smtp');
    putenv('MAIL_PORT=465');

    $config = require base_path('config/mail.php');

    expect($config['mailers']['smtp']['scheme'])->toBe('smtp');

    putenv('MAIL_SCHEME=');
    putenv('MAIL_PORT=');
});

test('le diagnostic NOMME l’expéditeur incohérent', function () {
    // Une liste de choses à vérifier fait relire ; nommer l'incohérence constatée
    // fait corriger. C'est exactement le cas signalé : from=no-reply@…,
    // user=s.avismart@…
    $controller = file_get_contents(app_path('Http/Controllers/NotificationController.php'));

    expect($controller)->toContain("strcasecmp(\$from, \$user) !== 0")
        ->and($controller)->toContain("diffère du compte authentifié");
});

test('la documentation de déploiement dit déjà les deux règles', function () {
    // Elle était juste — le .env du site ne la suivait pas. On la gèle pour que
    // le correctif de configuration ne la rende pas obsolète.
    $doc = file_get_contents(base_path('docs/mobile/deploiement-planethoster.md'));

    expect($doc)->toContain('MAIL_FROM_ADDRESS')
        ->and($doc)->toContain('465');
});
