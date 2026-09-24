<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * LE FORMULAIRE « MOT DE PASSE OUBLIÉ » SERVAIT D'ANNUAIRE.
 *
 * Il répondait différemment selon que l'adresse ouvrait un compte ou non :
 * « aucun utilisateur n'a cette adresse » d'un côté, « lien envoyé » de
 * l'autre. Il suffisait donc d'essayer des adresses pour savoir lesquelles
 * existent. Et la route n'avait aucun plafond — le délai par compte
 * (`passwords.users.throttle`) ne vaut que pour les comptes EXISTANTS.
 *
 * Ce qui aggrave ici : les identifiants des employés sont GÉNÉRÉS selon un
 * motif (`EmployeeAccessController::uniqueLogin`), donc devinables — puis
 * confirmables un par un par ce formulaire.
 *
 * C'est le genre de constat qu'un test d'intrusion classe en sévérité
 * faible-à-moyenne, et qu'on corrige avant de commercialiser. Remède standard
 * (OWASP) : la même réponse dans tous les cas, et un plafond sur la route.
 */

/** Ce que l'écran rend pour une adresse donnée. */
function demanderUnLien(object $test, string $adresse): array
{
    $reponse = $test->post('/forgot-password', ['email' => $adresse]);

    // Les erreurs peuvent revenir sous plusieurs formes selon le chemin de
    // réponse. La première version de ce test supposait un `ViewErrorBag` et
    // PLANTAIT sur l'ancien code — elle échouait donc pour une mauvaise raison,
    // et sa « preuve avant correction » ne prouvait rien.
    $sac = session('errors');
    $erreurs = match (true) {
        $sac instanceof \Illuminate\Support\ViewErrorBag => $sac->getBag('default')->all(),
        $sac instanceof \Illuminate\Support\MessageBag   => $sac->all(),
        is_array($sac)                                   => $sac,
        default                                          => [],
    };

    return [
        'redirection' => $reponse->headers->get('Location'),
        'statut'      => session('status'),
        'erreurs'     => $erreurs,
    ];
}

test('une adresse INCONNUE et une adresse CONNUE reçoivent la même réponse', function () {
    /*
     * LE défaut. La comparaison porte sur tout ce que voit le visiteur : la
     * redirection, le message, et l'absence d'erreur. Une seule différence
     * suffit à reconstituer l'annuaire.
     */
    Notification::fake();
    User::factory()->create(['email' => 'patron@ferme.gn']);

    $connue   = demanderUnLien($this, 'patron@ferme.gn');
    $this->flushSession();
    $inconnue = demanderUnLien($this, 'personne@ferme.gn');

    expect($inconnue)->toBe($connue)
        ->and($connue['erreurs'])->toBe([]);
});

test('le lien PART bien pour un compte existant — non-régression', function () {
    // Répondre pareil ne doit pas faire cesser d'envoyer.
    Notification::fake();
    $patron = User::factory()->create(['email' => 'patron@ferme.gn']);

    demanderUnLien($this, 'patron@ferme.gn');

    Notification::assertSentTo($patron, ResetPassword::class);
});

test('et ne part vers personne pour une adresse inconnue', function () {
    Notification::fake();

    demanderUnLien($this, 'personne@ferme.gn');

    Notification::assertNothingSent();
});

test('un compte TROP SOLLICITÉ ne se trahit pas non plus', function () {
    /*
     * Le trou dans le trou. Seul un compte EXISTANT peut atteindre le délai
     * entre deux envois ; si ce cas renvoyait un message à part (« veuillez
     * patienter »), il confirmerait l'existence du compte aussi sûrement que
     * l'ancienne erreur.
     */
    Notification::fake();
    User::factory()->create(['email' => 'patron@ferme.gn']);

    demanderUnLien($this, 'patron@ferme.gn');
    $this->flushSession();
    $retenu = demanderUnLien($this, 'patron@ferme.gn');   // dans la minute : retenu
    $this->flushSession();
    $inconnue = demanderUnLien($this, 'personne@ferme.gn');

    expect($retenu)->toBe($inconnue);
});

test('la route est PLAFONNÉE : on ne peut plus essayer les adresses en série', function () {
    /*
     * L'autre moitié du remède. Sans plafond, une réponse uniforme ralentit
     * l'énumération sans l'empêcher — le compteur par compte ne s'applique
     * qu'aux adresses qui existent.
     */
    Notification::fake();

    // On ne compare que les CODES de réponse : `assertRedirect()` met en forme
    // les erreurs de session pour son message, et plantait sur l'ancien code —
    // le test aurait échoué, mais pas pour la raison qu'il est censé prouver.
    $codes = [];
    for ($i = 1; $i <= 7; $i++) {
        $codes[] = $this->post('/forgot-password', ['email' => "essai{$i}@ferme.gn"])->status();
    }

    expect(array_slice($codes, 0, 6))->not->toContain(429)
        ->and($codes[6])->toBe(429);
});

test('l’alerte WhatsApp ne transporte plus le jeton de réinitialisation', function () {
    /*
     * Le chemin `/reset-password/{token}` porte un titre d'accès valable une
     * heure. L'alerte d'erreur part vers un téléphone : elle garde le chemin,
     * pour le diagnostic, et masque le jeton. La chaîne de requête — où voyage
     * la signature d'un lien signé — est retirée.
     */
    $methode = new ReflectionMethod(\App\Services\ErrorAlertService::class, 'urlSansSecret');
    $methode->setAccessible(true);

    $this->app->instance('request', \Illuminate\Http\Request::create(
        'https://ferme.test/reset-password/abc123secret?email=patron%40ferme.gn&signature=zzz'
    ));

    $url = $methode->invoke(null);

    expect($url)->toBe('https://ferme.test/reset-password/•••')
        ->and(str_contains($url, 'abc123secret'))->toBeFalse()
        ->and(str_contains($url, 'signature'))->toBeFalse();
});
