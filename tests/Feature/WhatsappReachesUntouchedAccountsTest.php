<?php

use App\Models\NotificationPreference;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TROU AVAIT ÉTÉ BOUCHÉ POUR LA CLOCHE, PAS POUR WHATSAPP.
 *
 * La ligne `notification_preferences` d'un utilisateur n'est créée QUE s'il ouvre
 * l'écran de ses préférences. Un technicien qui ne travaille que sur le mobile
 * n'y va jamais.
 *
 * Une correction antérieure l'avait vu, et son commentaire le dit encore :
 *
 *     « ATTENTION, c'était le trou : la requête exigeait une ligne de préférences
 *       ACTIVE (whereHas). Or cette ligne n'était créée qu'en ouvrant l'écran des
 *       réglages. Un compte qui n'y était jamais allé recevait donc ZÉRO alerte
 *       in-app — ni cloche web, ni centre d'alertes mobile — sans […] »
 *
 * `typeRecipients()` a donc été réparée : elle accepte désormais les comptes sans
 * ligne et leur applique les VALEURS LIVRÉES (NotificationPreference::DEFAULTS).
 *
 * `getSubscribers()` — la résolution du canal WHATSAPP — est restée telle quelle.
 * Le même trou, sur le canal qui atteint le terrain.
 *
 * ─── CE QUE CELA DONNAIT ───
 *
 * Les valeurs livrées activent WhatsApp (`channel_whatsapp => true`) et les
 * alertes de mortalité. Un technicien qui n'a jamais ouvert cet écran recevait
 * pourtant :
 *
 *   • la cloche et le push .................. oui
 *   • le WhatsApp ........................... AUCUN
 *
 * Pour une exploitation dont les agents vivent sur la PWA et dont le promoteur
 * est à l'étranger, c'est le canal le plus utile qui manquait — et il manquait en
 * silence, puisque rien ne distingue « pas abonné » de « jamais passé par là ».
 *
 * ─── LA NUANCE QU'IL NE FAUT PAS PERDRE ───
 *
 * Toutes les valeurs livrées ne sont pas « oui » : `alert_sales` vaut FAUX. Un
 * compte sans ligne ne doit donc PAS recevoir les alertes de vente. La réparation
 * reprend exactement la logique de `typeRecipients`, qui consulte les valeurs
 * livrées type par type, au lieu d'ouvrir en grand.
 */

beforeEach(function () {
    $this->setUpRbac();

    Setting::set('whatsapp.driver', 'twilio');
    Setting::set('whatsapp.api_key', 'AC-EXEMPLE-DE-TEST:jeton-factice');
    Setting::set('whatsapp.sender', 'whatsapp:+14155238886');
    Setting::clearCache();

    // Un technicien du terrain : numéro renseigné, JAMAIS passé par l'écran des
    // préférences — donc aucune ligne en base.
    $this->technicien = User::factory()->create([
        'role_id'        => $this->operatorUser->role_id,
        'whatsapp_phone' => '+224620111222',
        'is_active'      => true,
    ]);

    NotificationPreference::where('user_id', $this->technicien->id)->delete();
});

/** Diffuse une alerte et rend les numéros réellement appelés. */
function numerosAppeles(callable $envoi): array
{
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);

    $envoi();

    return Http::recorded()
        ->map(fn ($paire) => $paire[0]->data()['To'] ?? '')
        ->filter()
        ->values()
        ->all();
}

test('un compte SANS ligne de préférences reçoit le WhatsApp de mortalité', function () {
    /*
     * LE défaut : la cloche l'atteignait, WhatsApp non.
     */
    $numeros = numerosAppeles(fn () => app(NotificationHub::class)
        ->alertHaccp('Mortalité anormale au bâtiment A', 'Surmortalité', 'critique'));

    expect($numeros)->toContain('whatsapp:+224620111222');
});

test('une case DÉCOCHÉE reste respectée', function () {
    /*
     * LA borne : on rattrape les comptes sans ligne, on ne piétine pas un choix
     * exprimé. Couper WhatsApp doit couper WhatsApp.
     */
    NotificationPreference::create(array_merge(NotificationPreference::DEFAULTS, [
        'user_id'          => $this->technicien->id,
        'channel_whatsapp' => false,
    ]));

    $numeros = numerosAppeles(fn () => app(NotificationHub::class)
        ->alertHaccp('Mortalité anormale au bâtiment A', 'Surmortalité', 'critique'));

    expect($numeros)->not->toContain('whatsapp:+224620111222');
});

test('les valeurs livrées sont consultées TYPE PAR TYPE, pas ouvertes en grand', function () {
    /*
     * La nuance à ne pas perdre : `alert_sales` vaut FAUX dans les valeurs
     * livrées. Un compte sans ligne ne doit donc PAS recevoir les alertes de
     * vente — sans quoi la réparation deviendrait un élargissement, et un
     * élargissement payant (chaque WhatsApp consomme du crédit).
     *
     * Premier jet de ce test : il passait par l'ANNULATION de vente, en supposant
     * qu'elle relevait de `alert_sales`. Elle est diffusée en `alert_fraud`, dont
     * la valeur livrée est VRAIE — le technicien la recevait donc à juste titre,
     * et le test accusait à tort. On mesure ici un encaissement, qui relève bien
     * de `alert_sales`.
     */
    expect(NotificationPreference::DEFAULTS['alert_sales'])->toBeFalse();

    $client = \App\Models\Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-700',
        'name' => 'Client comptant', 'category' => 'detaillant', 'phone' => '620000111',
    ]);

    $vente = \App\Models\Sale::create([
        'farm_id' => $this->farm->id, 'client_id' => $client->id,
        'reference' => 'VTE-700', 'sale_date' => today()->toDateString(),
        'status' => 'valide', 'total_amount' => 100_000, 'paid_amount' => 100_000,
        'user_id' => $this->adminUser->id,
    ]);

    $paiement = \App\Models\Payment::create([
        'sale_id' => $vente->id, 'amount' => 100_000, 'method' => 'especes',
        'payment_date' => today()->toDateString(), 'received_by' => $this->adminUser->id,
    ]);

    $numeros = numerosAppeles(fn () => app(NotificationHub::class)
        ->notifyPaymentReceived($paiement->load('sale.client')));

    expect($numeros)->not->toContain('whatsapp:+224620111222');
});

test('un compte sans NUMÉRO n’est pas appelé', function () {
    // La borne évidente, qui doit survivre : pas de téléphone, pas d'envoi.
    $this->technicien->update(['whatsapp_phone' => null]);

    $numeros = numerosAppeles(fn () => app(NotificationHub::class)
        ->alertHaccp('Mortalité anormale', 'Surmortalité', 'critique'));

    expect($numeros)->toBeEmpty();
});

test('un compte DÉSACTIVÉ par is_active ne reçoit rien', function () {
    /*
     * Cohérence avec la résolution des administrateurs (#288) : un compte dont on
     * ne veut plus ne doit pas être appelé.
     */
    NotificationPreference::create(array_merge(NotificationPreference::DEFAULTS, [
        'user_id'   => $this->technicien->id,
        'is_active' => false,
    ]));

    $numeros = numerosAppeles(fn () => app(NotificationHub::class)
        ->alertHaccp('Mortalité anormale', 'Surmortalité', 'critique'));

    expect($numeros)->not->toContain('whatsapp:+224620111222');
});
