<?php

use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « AUTHENTICATION ERROR - INVALID USERNAME », ET RIEN POUR DIRE POURQUOI.
 *
 * Signalé depuis l'exploitation : tous les envois WhatsApp échouaient, et
 * l'historique des notifications affichait la réponse brute de Twilio :
 *
 *     401 — {"code":20003,"message":"Authentication Error - invalid username"}
 *
 * La cause est une confusion que Twilio provoque chez à peu près tout le monde.
 * Sa console propose DEUX identifiants qui se ressemblent :
 *
 *   • l'ACCOUNT SID, qui commence par « AC » — le seul que ce driver peut
 *     utiliser, parce qu'il sert à LA FOIS d'utilisateur d'authentification ET
 *     de segment d'URL : /2010-04-01/Accounts/{SID}/Messages.json ;
 *   • un API KEY SID, qui commence par « SK » — utilisable en authentification,
 *     mais JAMAIS dans l'URL du compte.
 *
 * Saisir le second donne exactement ce 401, dont le texte ne dit pas ce qu'il
 * faut corriger. Le code, lui, savait dès la lecture du réglage qu'un SID en
 * « SK » ne pouvait pas aboutir : il envoyait quand même, et laissait
 * l'exploitant devant un message de Twilio.
 *
 * ─── CE QUE LE CODE FAIT MAINTENANT ───
 *
 * Il refuse AVANT l'appel, en nommant le remède. Aucune requête n'est émise, et
 * l'écran affiche une phrase actionnable plutôt qu'un code d'erreur d'un tiers.
 *
 * ─── CE QU'IL NE FAIT PAS ───
 *
 * Il n'ajoute pas la prise en charge des API Keys. Elle demanderait de porter
 * DEUX identifiants (Account SID pour l'URL, clé + secret pour l'authentification)
 * là où l'écran offre un champ unique. Tant que ce champ est unique, le seul
 * couple qui fonctionne est « ACxxxx:auth_token » — et c'est ce que le message
 * demande.
 */

beforeEach(function () {
    $this->setUpRbac();

    Setting::set('whatsapp.driver', 'twilio');
    Setting::set('whatsapp.sender', 'whatsapp:+14155238886');
    Setting::clearCache();
});

/*
 * Les identifiants de ces tests sont VOLONTAIREMENT non réalistes.
 *
 * Un premier jet utilisait des chaînes de la bonne forme (« SK » + 32 caractères
 * hexadécimaux) : la protection anti-secrets de GitHub a refusé la poussée, et
 * elle avait raison — rien ne distingue une fausse clef d'une vraie pour un
 * scanner. Seul le préfixe compte pour la garde testée ici.
 */

/** Tente un envoi avec la clé API donnée, sans laisser sortir de requête. */
function envoiTwilio(string $cleApi): array
{
    Setting::set('whatsapp.api_key', $cleApi);
    Setting::clearCache();

    Http::fake(['*' => Http::response(['sid' => 'SM123'], 201)]);

    $envoye = app(WhatsAppService::class)->send('+33666083598', 'Test');

    return [$envoye, Http::recorded()->count()];
}

test('un API Key SID (SK…) est refusé AVANT l’appel, avec le remède', function () {
    /*
     * LE défaut vécu : ce SID produisait un 401 opaque côté Twilio.
     */
    [$envoye, $requetes] = envoiTwilio('SK-EXEMPLE-DE-TEST:secret-factice');

    expect($envoye)->toBeFalse()
        ->and($requetes)->toBe(0);   // rien n'est parti chez Twilio
});

test('un ACCOUNT SID (AC…) passe normalement', function () {
    /*
     * LA borne : on refuse ce qui ne peut pas marcher, pas la configuration
     * correcte.
     */
    [$envoye, $requetes] = envoiTwilio('AC-EXEMPLE-DE-TEST:jeton-factice');

    expect($envoye)->toBeTrue()
        ->and($requetes)->toBe(1);
});

test('une clé sans deux-points reste refusée comme avant', function () {
    // Le format attendu est « SID:TOKEN ». Ce cas était déjà couvert ; il ne
    // doit pas régresser.
    [$envoye, $requetes] = envoiTwilio('juste_un_token_sans_sid');

    expect($envoye)->toBeFalse()
        ->and($requetes)->toBe(0);
});

test('le motif du refus est enregistré, pas seulement l’échec', function () {
    /*
     * L'historique des notifications est le seul endroit où l'exploitant, à
     * l'étranger, peut comprendre pourquoi rien ne part. Un échec sans motif
     * l'y laisserait aussi démuni qu'avec le 401 de Twilio.
     */
    envoiTwilio('SK-EXEMPLE-DE-TEST:secret-factice');

    $log = \App\Models\NotificationLog::latest('id')->first();

    expect($log)->not->toBeNull()
        ->and(json_encode($log->provider_response))->toContain('ACxxxx');
});
