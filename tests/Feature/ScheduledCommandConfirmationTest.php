<?php

use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * UNE TÂCHE PLANIFIÉE QUI POSE UNE QUESTION NE TOURNE JAMAIS.
 *
 * `activitylog:clean` était planifiée chaque semaine pour purger le journal d'audit
 * au-delà de sa rétention. Le commentaire du planificateur l'affirmait : « borne la
 * croissance de la table activity_log ».
 *
 * Elle ne tournait PAS. La commande de la bibliothèque emploie le ConfirmableTrait de
 * Laravel : `confirmToProceed()` demande une confirmation et, sans réponse, ANNULE.
 * Vérifié à la main sur cette base — `php artisan activitylog:clean` comme
 * `--no-interaction` rendent tous deux « Command cancelled ». Le planificateur
 * l'appelait sans drapeau : la table grossissait donc sans borne, indéfiniment.
 *
 * ─── CE QUI REND CE DÉFAUT PARTICULIÈREMENT SOURNOIS ───
 *
 * `confirmToProceed()` ne bloque QU'EN PRODUCTION. En développement, la commande
 * s'exécute normalement — on la teste, elle marche, on la planifie, et elle
 * s'annule silencieusement là où elle compte. Le seul endroit où elle échoue est le
 * seul qu'on ne regarde pas.
 *
 * (Je n'ai pu le reproduire que parce que l'environnement de cette machine est réglé
 * sur `production`. Sur une machine de développement ordinaire, ce défaut serait
 * resté invisible.)
 *
 * ─── LE GARDE-FOU EST DÉRIVÉ ───
 *
 * Il n'énumère aucune commande : il parcourt le planificateur, retrouve la classe de
 * chaque tâche, et exige `--force` dès qu'elle emploie le ConfirmableTrait. Une
 * commande ajoutée demain — de la bibliothèque ou d'ici — tombera ici plutôt que de
 * s'annuler en silence pendant des mois.
 */

test('aucune tâche planifiée ne peut s’annuler faute de confirmation', function () {
    $source = file_get_contents(base_path('routes/console.php'));
    $registered = Artisan::all();

    $offenders = [];
    $checked = 0;

    foreach (app(Schedule::class)->events() as $event) {
        if (! preg_match('/artisan.?\s+([a-z0-9:_-]+)/', (string) $event->command, $m)) {
            continue;
        }

        $command = $registered[$m[1]] ?? null;

        if (! $command) {
            continue;
        }

        $checked++;

        if (! in_array(ConfirmableTrait::class, class_uses_recursive($command), true)) {
            continue;
        }

        if (! str_contains((string) $event->command, '--force')) {
            $offenders[] = "{$m[1]} demande une confirmation et est planifiée SANS --force : elle s’annulera à chaque passage, en production seulement.";
        }
    }

    // Garde-fou du garde-fou : sans tâche examinée, le test passerait à vide.
    expect($checked)->toBeGreaterThan(15);

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

test('la purge du journal d’audit passe bien --force', function () {
    // Le cas concret, nommé : c'est celui qui ne tournait pas.
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->toContain("Schedule::command('activitylog:clean --force')");
});

test('la purge s’exécute réellement quand elle est forcée', function () {
    // Le test de comportement : la signature ne dit pas qu'elle aboutit.
    $this->artisan('activitylog:clean --force')
        ->expectsOutputToContain('All done!')
        ->assertExitCode(0);
});

test('sans --force et EN PRODUCTION, elle s’annule — c’est bien la cause', function () {
    /*
     * On établit la CAUSE plutôt que de la supposer.
     *
     * `confirmToProceed()` ne bloque QU'EN PRODUCTION : c'est ce qui rend le défaut
     * si sournois, puisqu'il ne se manifeste que là où personne ne regarde tourner
     * les tâches. On bascule donc l'environnement le temps de l'éprouver.
     *
     * Si la bibliothèque changeait un jour ce comportement, ce test tomberait — et
     * c'est souhaitable : le drapeau deviendrait inutile, et on veut le savoir.
     */
    app()['env'] = 'production';

    // Appel NON INTERACTIF — exactement ce que fait le planificateur, qui n'a
    // personne pour répondre.
    \Illuminate\Support\Facades\Artisan::call('activitylog:clean');

    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('cancelled');
});
