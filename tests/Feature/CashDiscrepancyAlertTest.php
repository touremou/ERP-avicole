<?php

use App\Models\CashRegisterSession;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN ÉCART DE CAISSE N'ÉTAIT ANNONCÉ À PERSONNE.
 *
 * À la clôture, l'écart entre le comptant physique et l'espèce attendue s'affichait
 * en message d'écran — pour celui-là même qui venait de clôturer la caisse,
 * c'est-à-dire, le cas échéant, la personne en cause.
 *
 * Le promoteur vit à l'étranger. Le signal de détournement le plus direct de toute
 * l'application ne lui parvenait pas, et rien n'en gardait trace ailleurs que dans la
 * ligne de session.
 *
 * ─── POURQUOI TOUT ÉCART ALERTE, SANS SEUIL DE TOLÉRANCE ───
 *
 * L'espèce attendue est calculée à partir des paiements RÉELLEMENT enregistrés :
 * fond d'ouverture + encaissements espèces nets. Un ticket arrondi l'est déjà à
 * l'enregistrement du paiement — `cash_round` porte sur le total de la vente, pas sur
 * le comptage. L'écart attendu est donc exactement ZÉRO.
 *
 * Inventer une tolérance reviendrait à laisser passer les petits détournements
 * réguliers, qui sont les plus fréquents et les plus difficiles à voir.
 *
 * ─── DEUX SÉVÉRITÉS, ET LA DISTINCTION COMPTE ───
 *
 *   • MANQUANT → critique : de l'argent n'est pas là. L'alerte passe les heures
 *     silencieuses et déclenche le filet admin ;
 *   • EXCÉDENT → normal : presque toujours une erreur de saisie (vente non
 *     enregistrée, rendu de monnaie faux). Il faut le savoir, pas s'en alarmer.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'état réel de cette exploitation : le WhatsApp ne sort pas. L'alerte doit
    // donc exister sur les autres canaux (cf. #216).
    Setting::set('whatsapp.driver', 'log');
});

/**
 * Contenu LISIBLE d'une notification.
 *
 * La colonne `data` stocke du JSON, où les accents sont échappés (\u00c9). Une
 * assertion sur la chaîne brute échoue donc sur « ÉCART » tout en passant sur
 * « MANQUANT » — un faux négatif qui ne dépend que de l'orthographe du mot cherché.
 */
function notificationText(int $userId): string
{
    $raw = DB::table('notifications')->where('notifiable_id', $userId)->latest('created_at')->value('data');

    return json_encode(json_decode((string) $raw, true), JSON_UNESCAPED_UNICODE) ?: '';
}

/** Session de caisse clôturée avec l'écart voulu. */
function closedSession(int $farmId, int $userId, float $expected, float $counted): CashRegisterSession
{
    return CashRegisterSession::create([
        'farm_id'       => $farmId,
        'user_id'       => $userId,
        'status'        => 'closed',
        'opening_float' => 0,
        'opened_at'     => now()->subHours(8),
        'closed_at'     => now(),
        'expected_cash' => $expected,
        'counted_cash'  => $counted,
        'difference'    => round($counted - $expected, 2),
    ]);
}

test('un MANQUANT alerte, et en CRITIQUE', function () {
    // Le cas qui compte : de l'argent n'est pas là.
    $session = closedSession($this->farm->id, $this->adminUser->id, 500000, 480000);

    app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session);

    $payload = notificationText($this->adminUser->id);

    expect($payload)->toContain('MANQUANT')
        ->and($payload)->toContain('critique');
});

test('un EXCÉDENT alerte, mais SANS crier au vol', function () {
    // Presque toujours une erreur de saisie. Le signaler en critique userait
    // l'attention qu'on veut garder pour les manquants.
    $session = closedSession($this->farm->id, $this->adminUser->id, 500000, 520000);

    app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session);

    $payload = notificationText($this->adminUser->id);

    expect($payload)->toContain('EXCÉDENT')
        ->and($payload)->not->toContain('critique');
});

test('une caisse JUSTE n’alerte pas', function () {
    // Le pendant indispensable : une alerte à chaque clôture finirait ignorée.
    $session = closedSession($this->farm->id, $this->adminUser->id, 500000, 500000);

    expect(app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session))->toBe(0);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(0);
});

test('l’alerte porte les TROIS chiffres — attendu, compté, écart', function () {
    // Un écart annoncé sans ses termes oblige à rouvrir l'écran pour comprendre.
    $session = closedSession($this->farm->id, $this->adminUser->id, 500000, 480000);

    app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session);

    $payload = notificationText($this->adminUser->id);

    expect($payload)->toContain('500 000')   // attendu
        ->and($payload)->toContain('480 000') // compté
        ->and($payload)->toContain('20 000'); // écart
});

test('l’alerte NOMME le tenant de la caisse', function () {
    // Sans nom, l'information n'est pas actionnable pour un promoteur à distance.
    $this->adminUser->update(['name' => 'Fatoumata Camara']);

    $session = closedSession($this->farm->id, $this->adminUser->id, 500000, 480000);

    app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session->fresh());

    $payload = notificationText($this->adminUser->id);

    expect($payload)->toContain('Fatoumata Camara');
});

test('l’alerte mène à la CAISSE, pas aux écarts d’expédition', function () {
    // Le type `alert_fraud` mène par défaut à l'écran des écarts d'EXPÉDITION — la
    // bonne destination pour son usage d'origine, la mauvaise ici. Une alerte qui dit
    // « écart de caisse » et ouvre les expéditions fait chercher au mauvais endroit.
    $session = closedSession($this->farm->id, $this->adminUser->id, 500000, 480000);

    app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session);

    expect(notificationText($this->adminUser->id))
        ->toContain(route('cash-register.index', absolute: false));
});

test('la clôture DÉCLENCHE réellement l’alerte', function () {
    // Le test de câblage : une méthode d'alerte que personne n'appelle est
    // exactement le défaut que cet audit corrige depuis le début.
    $controller = file_get_contents(app_path('Http/Controllers/CashRegisterController.php'));

    expect($controller)->toContain('alertCashDiscrepancy');
});

test('une alerte en échec n’empêche PAS la caisse de se clôturer', function () {
    // Le comptage physique ne doit jamais être perdu à cause d'un canal muet.
    // Éprouvé sur le COMPORTEMENT : on casse le hub au conteneur et on vérifie que
    // la clôture aboutit quand même — un test de forme sur le try/catch se
    // contenterait de la présence du mot-clé.
    $this->actingAs($this->adminUser);

    $session = CashRegisterSession::create([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id,
        'status' => 'open', 'opening_float' => 100000, 'opened_at' => now()->subHours(2),
    ]);

    app()->bind(\App\Services\NotificationHub::class, fn () => throw new \RuntimeException('canal mort'));

    $this->post(route('cash-register.close', $session), [
        // Le contrôleur attend `counts`, indexé par coupure (cf. sa validation).
        'counts' => ['20000' => 4],   // 80 000 comptés contre 100 000 attendus
    ])->assertRedirect();

    $session = $session->fresh();

    expect($session->status)->toBe('closed')
        ->and((float) $session->counted_cash)->toBe(80000.0);
});
