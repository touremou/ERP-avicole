<?php

use App\Models\NotificationPreference;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « LE CHAMP QUIET START NE CORRESPOND PAS AU FORMAT H:I »
 *
 * Signalé depuis le terrain : l'écran des préférences devenait INENREGISTRABLE.
 * Pas seulement les heures silencieuses — le numéro WhatsApp aussi, puisque tout
 * le formulaire tombe avec une seule règle en échec.
 *
 * LA CAUSE : la colonne est un TIME. MySQL la relit « 22:00:00 ». Le champ du
 * formulaire réaffichait ces secondes, le navigateur les resoumettait, et
 * « date_format:H:i » les refusait.
 *
 * POURQUOI PERSONNE NE L'AVAIT VU : en local et en test, aucune ligne de
 * préférences n'existe au départ, la valeur est nulle, et la validation ne voit
 * jamais de secondes. Le défaut n'apparaît qu'APRÈS un premier enregistrement
 * réussi — donc jamais dans une suite qui part d'une base vide.
 *
 * DEUX CORRECTIFS, parce qu'un seul laisserait la moitié du problème : la
 * validation accepte les deux formes, ET le modèle normalise pour que ce qui est
 * affiché soit ce qui est attendu.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('enregistrer avec des secondes est ACCEPTÉ', function () {
    // Exactement ce que le navigateur resoumet quand MySQL a rendu « 22:00:00 ».
    $this->actingAs($this->adminUser)
        ->put(route('notifications.preferences.update'), [
            'quiet_start' => '22:00:00',
            'quiet_end'   => '06:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');
});

test('le format court reste accepté', function () {
    $this->actingAs($this->adminUser)
        ->put(route('notifications.preferences.update'), [
            'quiet_start' => '23:30',
            'quiet_end'   => '05:15',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('la valeur relue est TOUJOURS au format court', function () {
    // Le cœur de la boucle : sans normalisation, le champ réaffiche les secondes
    // et le défaut se reproduit au prochain enregistrement.
    $prefs = NotificationPreference::updateOrCreate(
        ['user_id' => $this->adminUser->id],
        array_merge(NotificationPreference::DEFAULTS, [
            'quiet_start' => '22:00:00',
            'quiet_end'   => '06:00:00',
        ])
    );

    expect($prefs->fresh()->quiet_start)->toBe('22:00')
        ->and($prefs->fresh()->quiet_end)->toBe('06:00');
});

test('vider les heures ne provoque plus une erreur 500', function () {
    // Second défaut, trouvé en écrivant ce test : les colonnes sont NOT NULL, et
    // un champ vidé envoyait null. L'écran tombait en erreur serveur.
    //
    // Un champ vide vaut « ne change rien » : le modèle n'a pas d'état « pas
    // d'heures silencieuses » (isQuietHour retombe toujours sur une fenêtre), et
    // l'inventer dans un contrôleur serait décider d'une règle métier au mauvais
    // endroit.
    NotificationPreference::updateOrCreate(
        ['user_id' => $this->adminUser->id],
        array_merge(NotificationPreference::DEFAULTS, ['quiet_start' => '21:00', 'quiet_end' => '07:00'])
    );

    $this->actingAs($this->adminUser)
        ->put(route('notifications.preferences.update'), [
            'quiet_start' => '',
            'quiet_end'   => '',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $prefs = NotificationPreference::where('user_id', $this->adminUser->id)->first();

    expect($prefs->quiet_start)->toBe('21:00')
        ->and($prefs->quiet_end)->toBe('07:00');
});

test('une heure absurde reste REFUSÉE', function () {
    // Élargir le format ne doit pas tout accepter : le garde-fou garde un sens.
    $this->actingAs($this->adminUser)
        ->put(route('notifications.preferences.update'), [
            'quiet_start' => '25h du matin',
        ])
        ->assertSessionHasErrors('quiet_start');
});

test('un échec sur les heures ne fait plus tomber le reste du formulaire', function () {
    // C'est ce qui rendait le défaut si coûteux : une règle en échec bloquait
    // l'enregistrement du numéro WhatsApp, sans rapport avec les heures.
    $this->actingAs($this->adminUser)
        ->put(route('notifications.preferences.update'), [
            'whatsapp_phone' => '+33666083598',
            'quiet_start'    => '22:00:00',
        ])
        ->assertSessionHasNoErrors();

    expect($this->adminUser->fresh()->whatsapp_phone)->toBe('+33666083598');
});
