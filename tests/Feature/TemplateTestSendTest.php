<?php

use App\Models\NotificationTemplate;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ESSAYER UN MODÈLE DE MESSAGE — la configuration ET le texte.
 *
 * Les essais existants (WhatsApp, e-mail, SMS, push) envoient un texte CODÉ EN
 * DUR : ils prouvent que le canal fonctionne, et ne disent RIEN des modèles. On
 * pouvait donc enregistrer un modèle, le croire bon, et ne découvrir qu'au premier
 * vrai incident qu'il manquait un chiffre ou qu'un mot ne passait pas.
 *
 * Cet essai rend le modèle TEL QU'IL EST ENREGISTRÉ, avec des valeurs visiblement
 * fictives, et le fait partir sur les canaux de l'utilisateur. Le compte rendu dit
 * canal par canal ce qui est parti — un « envoyé » global couvrant quatre canaux
 * dont un muet est précisément ce qui a fait perdre le plus de temps ici.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->template = NotificationTemplate::firstOrCreate(
        ['key' => 'alert_mortality', 'channel' => 'whatsapp'],
        [
            'label'     => 'Alerte mortalité (cumul)',
            'body'      => NotificationTemplate::catalog()['alert_mortality']['default'],
            'is_active' => true,
        ]
    );
});

test('l’essai rend le TEXTE DU MODÈLE, pas un message générique', function () {
    // Le point entier : l'essai doit montrer le modèle enregistré. On le modifie
    // avec une phrase reconnaissable et on vérifie qu'elle arrive.
    $this->template->update(['body' => "Phrase reconnaissable — lot {{batch_code}}, {{deaths}} morts."]);
    NotificationTemplate::clearCache();

    $this->actingAs($this->adminUser)
        ->post(route('notifications.templates.test', $this->template->id))
        ->assertRedirect();

    $payload = DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)
        ->latest('created_at')->value('data');

    expect($payload)->toContain('Phrase reconnaissable')
        // Les variables sont substituées : un essai qui afficherait « {{deaths}} »
        // ne dirait rien du rendu réel.
        ->and($payload)->toContain('TEST-001')
        ->and($payload)->not->toContain('{{deaths}}');
});

test('les valeurs d’exemple sont VISIBLEMENT fictives', function () {
    // Un essai qui ressemble à une vraie alerte se confond avec elle. « TEST-001 »
    // se lit du premier coup d'œil ; un code de bande plausible se prend pour un
    // incident réel — et fait courir un technicien pour rien.
    $this->actingAs($this->adminUser)
        ->post(route('notifications.templates.test', $this->template->id))
        ->assertRedirect();

    $payload = (string) DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)
        ->latest('created_at')->value('data');

    expect($payload)->toContain('TEST-001')
        ->and($payload)->toContain('essai');
});

test('le compte rendu dit CANAL PAR CANAL ce qui est parti', function () {
    Mail::fake();

    $this->actingAs($this->adminUser)
        ->post(route('notifications.templates.test', $this->template->id))
        ->assertRedirect();

    $flash = (string) session('success');

    expect($flash)->toContain('cloche : envoyé')
        ->and($flash)->toContain('e-mail :')
        ->and($flash)->toContain('WhatsApp :');
});

test('WhatsApp en mode journal est ANNONCÉ ignoré, pas présenté comme envoyé', function () {
    // Le mode journal est l'état réel de cette exploitation. Prétendre avoir envoyé
    // serait le mensonge le plus coûteux de cet écran.
    Setting::set('whatsapp.driver', 'log');

    $this->actingAs($this->adminUser)
        ->post(route('notifications.templates.test', $this->template->id))
        ->assertRedirect();

    expect((string) session('success'))->toContain('aucun provider actif');
});

test('un compte sans adresse e-mail le dit, au lieu d’échouer en silence', function () {
    $this->adminUser->update(['email' => null]);

    $this->actingAs($this->adminUser->fresh())
        ->post(route('notifications.templates.test', $this->template->id))
        ->assertRedirect();

    expect((string) session('success'))->toContain('aucune adresse');
})->skip(fn () => true, 'users.email est NOT NULL : le cas n’est pas atteignable, et fabriquer une base fictive pour l’éprouver n’apprendrait rien.');

test('l’essai est réservé à l’administration', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('notifications.templates.test', $this->template->id))
        ->assertRedirect();

    // Aucune notification n'a été produite pour ce compte.
    expect(DB::table('notifications')->where('notifiable_id', $this->readonlyUser->id)->count())->toBe(0);
});

test('toute variable du catalogue a une valeur d’exemple, ou l’essai le signale', function () {
    // Garde-fou : une variable sans exemple s'afficherait VIDE, et l'utilisateur
    // conclurait que son modèle est fautif. On préfère nommer le manque.
    $reflection = new ReflectionClass(\App\Http\Controllers\NotificationTemplateController::class);
    $samples = array_keys($reflection->getConstant('SAMPLE_VARIABLES'));

    $catalogVariables = collect(NotificationTemplate::catalog())
        ->flatMap(fn ($meta) => $meta['variables'])
        ->unique()->values()->all();

    $missing = array_values(array_diff($catalogVariables, $samples));

    expect($missing)->toBe([], 'Variables du catalogue sans valeur d’exemple : ' . implode(', ', $missing));
});

test('aucune valeur d’exemple ne porte sur une variable INEXISTANTE', function () {
    // L'inverse : un exemple pour une variable hors catalogue donnerait à l'essai
    // une allure de réussite sur un champ que le vrai message n'a pas.
    $reflection = new ReflectionClass(\App\Http\Controllers\NotificationTemplateController::class);
    $samples = array_keys($reflection->getConstant('SAMPLE_VARIABLES'));

    $catalogVariables = collect(NotificationTemplate::catalog())
        ->flatMap(fn ($meta) => $meta['variables'])
        ->unique()->values()->all();

    $orphans = array_values(array_diff($samples, $catalogVariables));

    expect($orphans)->toBe([], 'Valeurs d’exemple sans variable correspondante : ' . implode(', ', $orphans));
});

test('l’écran des modèles porte le bouton d’essai', function () {
    $this->actingAs($this->adminUser)
        ->get(route('notifications.templates'))
        ->assertOk()
        ->assertSee(e(__('Envoyer un essai')), false);
});
