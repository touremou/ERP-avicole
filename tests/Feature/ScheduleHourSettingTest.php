<?php

use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE FAUTE DE FRAPPE DANS UN CHAMP ARRÊTAIT LES 23 TÂCHES PLANIFIÉES.
 *
 * Quatre réglages portent une heure saisie à la main (unité HH:MM). L'écran des
 * Réglages n'a jamais rien validé : n'importe quelle chaîne partait en base. Et
 * routes/console.php passait la valeur BRUTE à dailyAt(), qui la découpe et la
 * confie au constructeur d'expression cron.
 *
 * Taper « 25:00 » dans les Réglages faisait donc lever ce constructeur, et
 * `schedule:run` échouait AVANT d'exécuter quoi que ce soit :
 *
 *     InvalidArgumentException : Invalid CRON field value 25 at position 1
 *
 * Vérifié à la main sur cette base avant correction. Toutes les tâches
 * s'arrêtaient — sauvegardes, alertes de contrat, pointages manquants,
 * péremptions, résumé quotidien — à chaque minute et indéfiniment. En silence :
 * la ligne de cron recommandée dans le runbook redirige sa sortie vers /dev/null.
 *
 * C'est la même forme de défaut que le reste de cet audit : UNE règle — « ce qu'est
 * une heure valide » — déclarée à deux endroits qui n'en tiraient pas le même
 * comportement. NotificationHub::isAfterHours() rattrapait l'échec et désactivait
 * la détection ; le planificateur, lui, mourait. Désormais une seule déclaration,
 * Setting::hour(), et les deux lecteurs y passent.
 *
 * LE CAS VIDE COMPTE AUTANT QUE LE CAS QUI CASSE : un champ effacé donnait
 * dailyAt('') → minuit. Le résumé quotidien partait à 00:00 sur les téléphones des
 * techniciens, et rien ne le disait.
 */

/**
 * Reconstruit le planificateur APRÈS le changement de réglage, et rend ses tâches.
 *
 * INDISPENSABLE, et découvert en éprouvant ces tests : sans cela ils passaient sans
 * rien vérifier. Le planificateur est un singleton construit une fois au démarrage
 * du noyau, donc AVANT que le test ne pose sa valeur fautive ; `schedule:list`
 * réutilisait la liste déjà bâtie avec l'ancienne valeur et rendait 0 quoi qu'on
 * fasse. Un test vert qui n'éprouve rien est pire qu'un test absent.
 *
 * On oublie donc l'instance et on relit routes/console.php, qui réenregistre tout
 * sur un planificateur neuf — le chemin exact qu'emprunte `schedule:run` en
 * production, à chaque minute.
 */
function rebuildSchedule(): \Illuminate\Console\Scheduling\Schedule
{
    Setting::clearCache();

    app()->forgetInstance(\Illuminate\Console\Scheduling\Schedule::class);

    // La façade garde SA propre référence à l'instance résolue. Sans cette purge,
    // les Schedule::command() du fichier réenregistraient sur l'objet oublié et
    // le planificateur relu ressortait VIDE — deuxième façon, pour ces tests, de
    // paraître verts sans rien éprouver.
    \Illuminate\Support\Facades\Facade::clearResolvedInstance(\Illuminate\Console\Scheduling\Schedule::class);

    require base_path('routes/console.php');

    return \Illuminate\Support\Facades\Schedule::getFacadeRoot();
}

/**
 * Ce que fait RÉELLEMENT `schedule:run` chaque minute : demander les tâches dues.
 *
 * Second piège de ces tests, également découvert en les éprouvant : `dailyAt()` ne
 * valide RIEN — il assemble une chaîne cron sans la relire. Enregistrer les tâches
 * ne lève donc jamais, et un test qui s'arrête là passe même sur le code fautif.
 * La levée vient de l'ÉVALUATION de l'expression, c'est-à-dire de isDue(), que
 * `schedule:run` appelle sur chaque tâche via dueEvents().
 */
function dueTasks(): int
{
    return count(rebuildSchedule()->dueEvents(app())->all());
}

test('le planificateur SURVIT à une heure impossible', function () {
    // LE test de ce lot. Avant correction, cet appel levait
    // « Invalid CRON field value 25 at position 1 » et emportait les 23 tâches.
    Setting::set('whatsapp.daily_summary_hour', '25:00');

    expect(dueTasks())->toBeGreaterThanOrEqual(0)
        ->and(count(rebuildSchedule()->events()))->toBeGreaterThan(20);
});

test('une heure en LETTRES ne rend pas une tâche qui ne part jamais', function () {
    // Cas plus sournois que « 25:00 » : le texte ne fait pas lever le constructeur
    // cron, il produit une expression que RIEN ne déclenche jamais. La tâche
    // existe, s'affiche dans la liste, et ne tourne pas. Vérifié : avec l'ancien
    // lecteur, l'expression valait « 0 tous les soirs * * * ».
    Setting::set('whatsapp.activity_digest_hour', 'tous les soirs');

    $event = collect(rebuildSchedule()->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'avismart:activity-digest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 20 * * *')
        ->and(dueTasks())->toBeGreaterThanOrEqual(0);
});

test('une heure impossible retombe sur l’heure par défaut, PAS sur minuit', function () {
    // Survivre ne suffit pas : la tâche doit partir à l'heure prévue par défaut.
    Setting::set('whatsapp.daily_summary_hour', '25:00');

    $event = collect(rebuildSchedule()->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'avismart:daily-summary'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 7 * * *');
});

test('les 23 tâches restent enregistrées malgré une heure fautive', function () {
    // Leur PRÉSENCE est ce qui importe : la panne d'origine les faisait toutes
    // disparaître d'un coup.
    Setting::set('whatsapp.daily_summary_hour', '25:00');

    $schedule = rebuildSchedule();

    // On évalue AUSSI les échéances : c'est l'étape qui échouait, et sans elle ce
    // test passerait sur le code fautif.
    $schedule->dueEvents(app())->all();

    $events = $schedule->events();

    expect(count($events))->toBeGreaterThan(20);

    $commands = collect($events)->map(fn ($e) => $e->command)->implode(' ');

    // Les tâches dont l'absence coûte le plus cher, nommées une à une.
    expect($commands)->toContain('backup:run')
        ->and($commands)->toContain('hr:check-contracts')
        ->and($commands)->toContain('hr:check-attendance')
        ->and($commands)->toContain('stock:check-expiry')
        ->and($commands)->toContain('haccp:check-registers');
});

test('une heure vide donne l’heure PAR DÉFAUT, pas minuit', function () {
    // Avant correction : dailyAt('') → « 0 0 * * * », le résumé quotidien partait
    // à minuit. Personne ne lit un résumé à minuit, et personne ne savait pourquoi.
    Setting::set('whatsapp.daily_summary_hour', '');
    Setting::clearCache();

    expect(Setting::hour('whatsapp.daily_summary_hour', '07:00'))->toBe('07:00');
});

test('les saisies humaines plausibles sont COMPRISES, pas rejetées', function (string $saisie, string $attendu) {
    // Refuser « 19h30 » serait techniquement défendable et pratiquement absurde :
    // c'est la notation que l'utilisateur écrit. On la comprend.
    Setting::set('whatsapp.daily_summary_hour', $saisie);
    Setting::clearCache();

    expect(Setting::hour('whatsapp.daily_summary_hour', '07:00'))->toBe($attendu);
})->with([
    ['07:00', '07:00'],
    ['7', '07:00'],          // heure seule
    ['7:5', '07:05'],        // complétée
    ['19h30', '19:30'],      // notation française
    ['19 h 30', '19:30'],
    ['19H', '19:00'],
    ['07:00:00', '07:00'],   // secondes ignorées
    ['23:59', '23:59'],      // borne haute
    ['00:00', '00:00'],      // minuit EXPLICITE : respecté, contrairement au vide
]);

test('les valeurs impossibles rendent le défaut', function (string $saisie) {
    Setting::set('whatsapp.daily_summary_hour', $saisie);
    Setting::clearCache();

    expect(Setting::hour('whatsapp.daily_summary_hour', '07:00'))->toBe('07:00');
})->with([
    '25:00',            // heure hors bornes — celle qui cassait tout
    '12:60',            // minute hors bornes
    'abc',
    'tous les soirs',
    '-1:00',
    '07:00 le matin',
]);

test('l’heure normalisée est toujours acceptée par le planificateur', function (string $saisie) {
    // Garde-fou du garde-fou : la normalisation pourrait rendre une chaîne « propre »
    // que cron refuse quand même. On le vérifie en construisant réellement la tâche.
    Setting::set('whatsapp.daily_summary_hour', $saisie);

    // dueEvents() évalue chaque expression : c'est là que l'ancienne version levait.
    expect(dueTasks())->toBeGreaterThanOrEqual(0);
})->with(['25:00', '19h30', '7', '', '00:00', '23:59', 'n’importe quoi', '12:60', '-1:00', '007', '0:0']);

// ─────────────────────────────────────────────────────────────
//  LE REFUS À LA SAISIE
// ─────────────────────────────────────────────────────────────

test('l’écran des Réglages REFUSE une heure impossible', function () {
    // La lecture est désormais défensive, mais enregistrer une valeur qu'on
    // remplacera ensuite dans le dos de l'utilisateur lui cache que son réglage
    // n'a pas pris effet.
    $this->setUpRbac();

    $avant = Setting::where('group', 'whatsapp')->where('key', 'daily_summary_hour')->value('value');

    $this->actingAs($this->adminUser)
        ->from(route('settings.index'))
        ->put(route('settings.update'), [
            '_group'   => 'whatsapp',
            'settings' => ['daily_summary_hour' => '25:00'],
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors('settings.daily_summary_hour');

    // Et rien n'a été écrit.
    expect(Setting::where('group', 'whatsapp')->where('key', 'daily_summary_hour')->value('value'))
        ->toBe($avant);
});

test('le refus NOMME le réglage et le format attendu', function () {
    // « Saisie refusée » sans dire lequel ni pourquoi renvoie l'utilisateur
    // chercher parmi des dizaines de champs.
    $this->setUpRbac();

    $this->actingAs($this->adminUser)
        ->put(route('settings.update'), [
            '_group'   => 'whatsapp',
            'settings' => ['daily_summary_hour' => '25:00'],
        ]);

    // La session porte ici la forme SÉRIALISÉE du sac d'erreurs, pas encore un
    // ViewErrorBag — celui-ci n'est constitué qu'au rendu de la vue suivante. On
    // désigne donc le message précisément : un aplatissement du tableau ramassait
    // la clé « format » (« :message ») et le test échouait sur son propre outil.
    $message = (string) (session('errors')['default']['messages']['settings.daily_summary_hour'][0] ?? '');

    expect($message)->toContain('25:00')
        ->and($message)->toContain('HH:MM')
        ->and($message)->toContain('Heure résumé quotidien');
});

test('un refus n’enregistre AUCUNE des valeurs du groupe', function () {
    // Pas d'enregistrement partiel : un groupe de réglages se lit comme un tout,
    // et n'en écrire que la moitié laisse un état que personne n'a voulu.
    $this->setUpRbac();

    $avant = Setting::where('group', 'whatsapp')->where('key', 'admin_phone')->value('value');

    $this->actingAs($this->adminUser)
        ->put(route('settings.update'), [
            '_group'   => 'whatsapp',
            'settings' => [
                'daily_summary_hour' => '25:00',            // fautif
                'admin_phone'        => '+224620000000',    // valide
            ],
        ]);

    expect(Setting::where('group', 'whatsapp')->where('key', 'admin_phone')->value('value'))
        ->toBe($avant);
});

test('une heure VALIDE passe et s’enregistre', function () {
    // Le pendant : un contrôle qui refuse tout serait pire que pas de contrôle.
    $this->setUpRbac();

    $this->actingAs($this->adminUser)
        ->put(route('settings.update'), [
            '_group'   => 'whatsapp',
            'settings' => ['daily_summary_hour' => '06:30'],
        ])
        ->assertSessionHasNoErrors();

    expect(Setting::where('group', 'whatsapp')->where('key', 'daily_summary_hour')->value('value'))
        ->toBe('06:30');
});

test('un champ vidé reste autorisé — effacer est un geste légitime', function () {
    // Les bornes d'heures ouvrées vides signifient « surveillance éteinte ». Ce
    // choix doit rester possible : le refus ne vise que l'impossible, pas l'absence.
    $this->setUpRbac();

    $this->actingAs($this->adminUser)
        ->put(route('settings.update'), [
            '_group'   => 'whatsapp',
            'settings' => ['business_hours_start' => ''],
        ])
        ->assertSessionHasNoErrors();
});

test('un réglage numérique renseigné en lettres est refusé, pas coulé en 0', function () {
    // castValue() rend 0 pour une valeur non numérique. Un seuil d'alerte à 0 ne
    // ressemble pas à une panne — il alerte sur tout, ou plus sur rien.
    // Peu atteignable depuis un navigateur (input type=number), mais cette route
    // n'est pas joignable que par le formulaire.
    $this->setUpRbac();

    $cible = Setting::whereNull('farm_id')->where('type', 'number')->firstOrFail();

    $this->actingAs($this->adminUser)
        ->put(route('settings.update'), [
            '_group'   => $cible->group,
            'settings' => [$cible->key => 'beaucoup'],
        ])
        ->assertSessionHasErrors("settings.{$cible->key}");

    expect(Setting::where('group', $cible->group)->where('key', $cible->key)->value('value'))
        ->toBe($cible->value);
});

// ─────────────────────────────────────────────────────────────
//  LA RÈGLE EST DÉCLARÉE UNE SEULE FOIS
// ─────────────────────────────────────────────────────────────

test('aucun lecteur d’heure ne contourne Setting::hour()', function () {
    // Le défaut d'origine n'était pas une erreur de calcul : c'était DEUX
    // déclarations de la même règle. Si un troisième lecteur relit un réglage
    // HH:MM par setting() puis le passe à dailyAt() ou setTimeFromTimeString(),
    // la divergence revient — et ce test doit tomber.
    $keys = Setting::whereNull('farm_id')->where('unit', 'HH:MM')->pluck('key')->all();

    expect($keys)->not->toBeEmpty();

    $suspects = [];

    $files = array_merge(
        glob(app_path('Services/*.php')),
        glob(app_path('Console/Commands/*.php')),
        [base_path('routes/console.php')]
    );

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach ($keys as $key) {
            if (! str_contains($source, $key)) {
                continue;
            }

            // On cherche la lecture par le helper générique. Celle par
            // Setting::hour() est la bonne, et le test vide de sens d'y voir un
            // défaut. Le seul contournement toléré est le test de PRÉSENCE de la
            // valeur (plage vide = surveillance éteinte), qui ne l'interprète pas
            // comme une heure.
            if (preg_match('/setting\(\s*[\'"][a-z_]+\.' . preg_quote($key, '/') . '[\'"]/', $source, $m)
                && ! str_contains($source, "Setting::hour('whatsapp.{$key}'")
                && ! str_contains($source, "Setting::hour(\"whatsapp.{$key}\"")) {
                $suspects[] = basename($file) . " lit {$key} par setting() sans passer par Setting::hour()";
            }
        }
    }

    expect($suspects)->toBe([], "Règle d’heure redéclarée :\n  " . implode("\n  ", $suspects));
});
