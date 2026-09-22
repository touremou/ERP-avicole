<?php

use App\Models\TaskTemplate;
use Carbon\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN MODÈLE DE TÂCHE « MENSUEL » NE SE DÉCLENCHAIT JAMAIS.
 *
 * `TaskTemplate::shouldRunOnDay()` tranche le cas mensuel par une seule
 * comparaison :
 *
 *     'mensuel' => $date->day === $this->day_of_month,
 *
 * Or NI la création NI la modification ne renseignaient `day_of_month` :
 * l'écran proposait « Mensuel » dans la liste des fréquences, le contrôleur
 * l'acceptait en validation, et le champ n'existait dans aucun des deux
 * formulaires.
 *
 * ─── MESURÉ ───
 *
 * Un modèle « Contrôle mensuel des installations » créé par l'écran :
 * `frequency = mensuel`, `is_active = true`, `day_of_month = null`. Sur les
 * trente et un jours de juillet 2026, `shouldRunOnDay` rend faux TRENTE ET UNE
 * FOIS. La tâche n'est générée aucun jour, aucun mois, indéfiniment.
 *
 * C'est la pire forme de panne : rien ne casse, rien n'alerte, et le
 * responsable croit avoir programmé un contrôle qui n'existe pas. Le
 * générateur tourne chaque nuit (`tasks:generate`, 05:00) et ne dit rien,
 * puisqu'il n'a rien à dire.
 *
 * ─── LE 31 NE TOMBE PAS TOUS LES MOIS ───
 *
 * La même comparaison cachait une seconde façon de ne jamais se déclencher : un
 * inventaire réglé au 31 saute février, avril, juin, septembre et novembre —
 * cinq mois sur douze, sans rien dire non plus. Le jour demandé est désormais
 * ramené au dernier jour du mois : « le 31 » veut dire « le dernier ».
 *
 * ─── ET LES MOIS D'ACTIVITÉ, PROPOSÉS PUIS IGNORÉS ───
 *
 * `edit-template.blade.php` rend douze cases `months[]`, pré-cochées depuis le
 * modèle. La création les enregistre ; la MODIFICATION ne les validait ni ne
 * les écrivait. Décocher « saison des pluies » puis enregistrer ne changeait
 * rien, sans le moindre message — la saisonnalité ne pouvait se régler qu'à la
 * création, et jamais se corriger.
 *
 * C'est le même défaut vu de l'autre côté : un écran qui propose un réglage que
 * la porte n'enregistre pas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Crée un modèle par la VRAIE porte, avec ces attributs en plus du socle. */
function modeleParLEcran(object $test, array $attributs = []): TaskTemplate
{
    $test->post(route('tasks.templates.store'), array_merge([
        'name'             => 'Contrôle mensuel des installations',
        'category'         => 'controle',
        'frequency'        => 'mensuel',
        'duration_minutes' => 60,
        'priority'         => 'normale',
        'day_of_month'     => 1,
    ], $attributs));

    return TaskTemplate::latest('id')->firstOrFail();
}

/** Les jours d'un mois où ce modèle se déclencherait. */
function joursDeclenches(TaskTemplate $tpl, int $annee, int $mois): array
{
    $jours = [];

    foreach (range(1, Carbon::create($annee, $mois, 1)->daysInMonth) as $j) {
        if ($tpl->shouldRunOnDay(Carbon::create($annee, $mois, $j))) {
            $jours[] = $j;
        }
    }

    return $jours;
}

test('un modèle mensuel créé par l’écran se déclenche enfin', function () {
    /*
     * LE défaut : zéro jour sur trente et un, tous les mois, indéfiniment.
     */
    $tpl = modeleParLEcran($this, ['day_of_month' => 15]);

    expect((int) $tpl->day_of_month)->toBe(15)
        ->and(joursDeclenches($tpl, 2026, 7))->toBe([15]);
});

test('le formulaire REFUSE un modèle mensuel sans jour', function () {
    /*
     * La garde qui empêche de recréer le défaut. Sans elle, le champ pourrait
     * être vidé et le modèle redeviendrait muet — panne sans symptôme.
     */
    $this->post(route('tasks.templates.store'), [
        'name'             => 'Sans jour',
        'category'         => 'controle',
        'frequency'        => 'mensuel',
        'duration_minutes' => 60,
        'priority'         => 'normale',
    ])->assertSessionHasErrors('day_of_month');

    expect(TaskTemplate::where('name', 'Sans jour')->exists())->toBeFalse();
});

test('le 31 tombe au dernier jour des mois plus courts', function () {
    /*
     * LA seconde façon de ne jamais se déclencher : un inventaire réglé au 31
     * sautait cinq mois sur douze. « Le 31 » veut dire « le dernier ».
     */
    $tpl = modeleParLEcran($this, ['day_of_month' => 31]);

    expect(joursDeclenches($tpl, 2026, 2))->toBe([28])    // février
        ->and(joursDeclenches($tpl, 2026, 4))->toBe([30]) // avril
        ->and(joursDeclenches($tpl, 2026, 7))->toBe([31]); // juillet, intact
});

test('et une seule fois par mois — jamais deux', function () {
    /*
     * La borne du ramenage : si le 31 tombait au 28 SANS remplacer le jour
     * normal, un modèle au 28 et un modèle au 31 se déclencheraient le même
     * jour — et un modèle au 29 se déclencherait deux fois en février.
     */
    $tpl = modeleParLEcran($this, ['day_of_month' => 29]);

    expect(joursDeclenches($tpl, 2026, 2))->toBe([28])
        ->and(joursDeclenches($tpl, 2026, 3))->toBe([29]);
});

test('un modèle mensuel ANCIEN, sans jour, retombe sur le 1er', function () {
    /*
     * Les modèles créés AVANT la garde portent `day_of_month` à null. Ils ont
     * été voulus mensuels : les laisser invisibles pour toujours serait leur
     * préférer le défaut. Le 1er est la lecture conservatrice de « mensuel ».
     */
    $tpl = TaskTemplate::create([
        'name' => 'Ancien modèle', 'category' => 'controle', 'frequency' => 'mensuel',
        'day_of_month' => null, 'duration_minutes' => 60, 'priority' => 'normale',
        'is_active' => true, 'target_type' => 'farm',
    ]);

    expect(joursDeclenches($tpl, 2026, 7))->toBe([1]);
});

test('modifier un modèle enregistre enfin les MOIS d’activité', function () {
    /*
     * L'écran rendait douze cases pré-cochées et la porte les ignorait :
     * décocher la saison des pluies ne changeait rien, sans message. La
     * saisonnalité ne pouvait se régler qu'à la création.
     */
    $tpl = modeleParLEcran($this, ['day_of_month' => 5, 'months' => [1, 2, 3]]);

    $this->post(route('tasks.templates.update', $tpl), [
        '_method'          => 'PUT',
        'name'             => $tpl->name,
        'category'         => 'controle',
        'frequency'        => 'mensuel',
        'day_of_month'     => 5,
        'duration_minutes' => 60,
        'priority'         => 'normale',
        'months'           => [7, 8],
    ]);

    expect($tpl->fresh()->months)->toBe([7, 8]);
});

test('et la saisonnalité commande vraiment le déclenchement', function () {
    /*
     * Le réglage ne vaut que par son effet : hors des mois retenus, le modèle
     * doit se taire. C'est la raison d'être du champ — « un arrosage quotidien
     * généré toute l'année tourne aussi en pleine saison des pluies ».
     */
    $tpl = modeleParLEcran($this, ['day_of_month' => 5, 'months' => [7]]);

    expect(joursDeclenches($tpl, 2026, 7))->toBe([5])
        ->and(joursDeclenches($tpl, 2026, 8))->toBe([]);
});

test('modifier un modèle enregistre aussi le JOUR', function () {
    // Le pendant de la création : corriger le jour doit marcher.
    $tpl = modeleParLEcran($this, ['day_of_month' => 5]);

    $this->post(route('tasks.templates.update', $tpl), [
        '_method'          => 'PUT',
        'name'             => $tpl->name,
        'category'         => 'controle',
        'frequency'        => 'mensuel',
        'day_of_month'     => 20,
        'duration_minutes' => 60,
        'priority'         => 'normale',
    ]);

    expect((int) $tpl->fresh()->day_of_month)->toBe(20)
        ->and(joursDeclenches($tpl->fresh(), 2026, 7))->toBe([20]);
});

test('passer un modèle mensuel en QUOTIDIEN efface son jour', function () {
    /*
     * Un jour du mois sur un modèle quotidien est un reliquat qui ment sur la
     * configuration. Le rendre au régime : la fréquence commande.
     *
     * Le champ est ENVOYÉ, et c'est le point : `x-show` ne fait que masquer en
     * CSS — l'input reste dans le formulaire et part avec lui. Un test qui
     * l'omettrait ne prouverait rien, puisque la valeur serait nulle de toute
     * façon.
     */
    $tpl = modeleParLEcran($this, ['day_of_month' => 5]);

    $this->post(route('tasks.templates.update', $tpl), [
        '_method'          => 'PUT',
        'name'             => $tpl->name,
        'category'         => 'controle',
        'frequency'        => 'quotidien',
        'day_of_month'     => 5,          // le champ masqué part quand même
        'duration_minutes' => 60,
        'priority'         => 'normale',
    ]);

    expect($tpl->fresh()->day_of_month)->toBeNull();
});

test('un modèle QUOTIDIEN se déclenche toujours tous les jours — non-régression', function () {
    // Le cas de très loin le plus courant : on ne touche pas à son régime.
    $tpl = modeleParLEcran($this, ['frequency' => 'quotidien', 'day_of_month' => null]);

    expect(joursDeclenches($tpl, 2026, 7))->toHaveCount(31);
});

test('un modèle HEBDO garde ses jours de semaine — non-régression', function () {
    // L'autre régime, intact : mercredi (3) seulement.
    $tpl = modeleParLEcran($this, [
        'frequency' => 'hebdo', 'day_of_month' => null, 'days_of_week' => [3],
    ]);

    // Juillet 2026 : les mercredis sont les 1, 8, 15, 22, 29.
    expect(joursDeclenches($tpl, 2026, 7))->toBe([1, 8, 15, 22, 29]);
});
