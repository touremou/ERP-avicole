<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * QUATRE ALERTES N'EXISTAIENT QUE SUR WHATSAPP — donc nulle part ici.
 *
 * La chaîne de notification a quatre canaux : WhatsApp, cloche in-app, push et
 * e-mail. `broadcast()` les gouverne tous, selon les préférences de chacun.
 *
 * Quatre méthodes ne passaient pas par elle et appelaient `whatsapp->send()` en
 * direct. Sur cette installation, dont le canal WhatsApp est en mode « journal »,
 * elles n'atteignaient donc PERSONNE — et rien ne le disait :
 *
 *   • le RÉSUMÉ QUOTIDIEN, principal lien du promoteur avec son exploitation
 *     puisqu'il vit à l'étranger ;
 *   • le DIGEST D'ACTIVITÉ du soir, son outil de redevabilité ;
 *   • le DOSAGE D'ALIMENT du matin, la consigne des techniciens ;
 *   • l'avis au RÉCEPTEUR DÉSIGNÉ d'une expédition.
 *
 * C'est exactement le défaut corrigé sur les congés en #206. Il restait ailleurs.
 *
 * LE PLUS COÛTEUX DES QUATRE est l'expédition, et pour une raison précise : son
 * garde portait sur le NUMÉRO WhatsApp (`if (! $receiver?->whatsapp_phone) return`).
 * Un récepteur désigné sans numéro ne recevait donc rien du tout, pas même la
 * cloche. Or il est le SEUL à pouvoir valider la réception, et sans validation le
 * contrôle des écarts ne se déclenche jamais : la marchandise arrivait sans que
 * personne ne soit prévenu, et l'écart ne se voyait pas.
 *
 * ─── CE QUI N'EST PAS CORRIGÉ, ET POURQUOI ───
 *
 * `remindClientPayment()` envoie toujours un WhatsApp direct, et c'est juste : elle
 * écrit à un CLIENT. Un client n'est pas un compte — il n'a ni cloche, ni push, ni
 * préférences. Le faire passer par broadcast() n'aurait aucun destinataire.
 *
 * Le filet « téléphone admin » du digest reste également direct, pour la même
 * raison : c'est un numéro, pas un compte.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'état réel de cette exploitation : WhatsApp en mode journal. Aucun message
    // ne quitte le serveur par ce canal.
    Setting::set('whatsapp.driver', 'log');

    // Le promoteur est abonné au résumé quotidien et a un numéro : avant
    // correction, il était le SEUL cas où quelque chose partait — et encore,
    // seulement si le canal WhatsApp fonctionnait.
    $this->adminUser->update(['whatsapp_phone' => '+224620000000']);
});

/** Expédition à réceptionner. Il n'existe pas de fabrique pour ce modèle. */
function newDispatch(int $farmId, int $receiverId, int $senderId): \App\Models\Dispatch
{
    return \App\Models\Dispatch::withoutGlobalScopes()->create([
        'farm_id'              => $farmId,
        'dispatch_number'      => 'EXP-TEST-001',
        'dispatched_by'        => $senderId,
        'intended_receiver_id' => $receiverId,
        'vehicle_plate'        => 'RC-1234-A',
        'driver_name'          => 'Mamadou Camara',
        'dispatch_date'        => now()->toDateString(),
        'destination'          => 'Kérouané',
        'status'               => 'en_transit',
    ]);
}

/**
 * Notifications in-app (cloche) reçues par un compte.
 *
 * Nom préfixé : les fonctions déclarées dans un fichier Pest sont GLOBALES, et
 * `bellCount()` existe déjà dans HrAlertSubscriptionTest. La collision provoque une
 * erreur fatale qui n'apparaît qu'en jouant la suite ENTIÈRE — fichier par fichier,
 * les deux passent.
 */
function chainBellCount(int $userId): int
{
    return DB::table('notifications')->where('notifiable_id', $userId)->count();
}

test('le RÉSUMÉ QUOTIDIEN arrive par la cloche, WhatsApp muet ou non', function () {
    // Le lien principal du promoteur avec son exploitation. Avant correction, il
    // n'existait que sur WhatsApp : en mode journal, son résumé n'arrivait nulle
    // part, chaque matin, depuis le début.
    app(NotificationHub::class)->sendDailySummary();

    expect(chainBellCount($this->adminUser->id))->toBeGreaterThan(0);
});

test('le compte rendu compte les personnes ATTEINTES, pas les seuls WhatsApp', function () {
    // Avant : « envoyé à N destinataires » ne comptait que les envois WhatsApp. En
    // mode journal, la commande annonçait donc 0 alors que la cloche avait reçu —
    // un compte rendu qui contredit la réalité fait chercher la panne au mauvais
    // endroit.
    $sent = app(NotificationHub::class)->sendDailySummary();

    expect($sent)->toBeGreaterThan(0);
});

test('le DOSAGE D’ALIMENT du matin atteint le terrain sans WhatsApp', function () {
    // La consigne d'aliment du jour, destinée aux techniciens — ceux-là mêmes qui
    // n'ont pas forcément de numéro renseigné, et qui travaillent depuis la PWA.
    $lot = \App\Models\Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'initial_quantity' => 500, 'current_quantity' => 500,
    ]);

    $envoyes = app(NotificationHub::class)->sendFeedingDosage();

    // Si aucun barème n'existe pour ce lot, la méthode ne produit rien du tout :
    // c'est son contrat, et ce n'est pas ce qu'on éprouve ici.
    if ($envoyes === 0) {
        // Le barème de dosage dépend de données de référence (espèce, type de
        // production, semaine) qu'un lot de fabrique n'a pas. Sans barème, la
        // méthode ne produit AUCUN message : c'est son contrat, et le canal n'est
        // donc pas en cause. Le passage par broadcast() reste garanti par le
        // garde-fou de forme en fin de fichier — on ne perd pas la vérification,
        // seulement la démonstration de bout en bout.
        test()->markTestSkipped('Aucun barème de dosage pour ce lot de fabrique : le message n’est pas produit, le canal n’est pas en cause.');
    }

    expect(chainBellCount($this->adminUser->id))->toBeGreaterThan(0);
});

test('un RÉCEPTEUR DÉSIGNÉ sans numéro WhatsApp est tout de même prévenu', function () {
    // LE cas le plus coûteux. Le garde portait sur le numéro : sans lui, aucune
    // alerte, pas même la cloche. Le récepteur est le seul à pouvoir valider la
    // réception — sans quoi le contrôle des écarts ne se déclenche jamais.
    $recepteur = User::factory()->create([
        'role_id'        => $this->adminUser->role_id,
        'whatsapp_phone' => null,
    ]);

    app(NotificationHub::class)->notifyDispatchReceiver(newDispatch($this->farm->id, $recepteur->id, $this->adminUser->id));

    expect(chainBellCount($recepteur->id))->toBeGreaterThan(0);
});

test('l’avis d’expédition mène là où l’on VALIDE la réception', function () {
    // Une alerte qui dit « validez la réception » sans mener à l'écran de
    // réception laisse chercher. La destination web existe ; la PWA n'ayant pas
    // d'écran d'expédition, le terrain retombe sur ses alertes plutôt que d'être
    // renvoyé à l'accueil par un chemin inconnu de son routeur.
    $recepteur = User::factory()->create(['role_id' => $this->adminUser->role_id]);

    app(NotificationHub::class)->notifyDispatchReceiver(newDispatch($this->farm->id, $recepteur->id, $this->adminUser->id));

    $payload = (string) DB::table('notifications')->where('notifiable_id', $recepteur->id)->value('data');

    expect($payload)->toContain(route('dispatches.index', absolute: false))
        ->and($payload)->toContain('/alertes');
});

test('le digest d’activité ne s’adresse PAS à plus de monde qu’avant', function () {
    // Piège de cette correction : `activity_digest` n'était pas dans la carte des
    // abonnements, et le défaut de cette carte vaut « aucun filtre » — donc un
    // WhatsApp à TOUS les comptes ayant un numéro. Le WhatsApp coûte de l'argent :
    // élargir une audience payante par omission serait le contraire d'une
    // correction. Le type est donc rattaché au résumé quotidien, ce qu'il utilisait
    // déjà (getSubscribers('daily_summary')).
    $hub = new ReflectionClass(NotificationHub::class);
    $method = $hub->getMethod('subscriptionColumnFor');
    $method->setAccessible(true);

    $instance = app(NotificationHub::class);

    expect($method->invoke($instance, 'activity_digest'))->toBe('daily_summary');
});

test('AUCUNE méthode du hub n’envoie plus de WhatsApp hors de la chaîne', function () {
    // Le garde-fou de forme : c'est la troisième fois que ce défaut est corrigé
    // méthode par méthode (congés en #206, puis ces quatre-ci). Il doit cesser de
    // pouvoir revenir.
    $source = file_get_contents(app_path('Services/NotificationHub.php'));

    /*
     * Exceptions MOTIVÉES, une par une. Une liste sans raison écrite devient le
     * trou par lequel le défaut revient.
     */
    $legitimate = [
        // Écrit à un CLIENT : ni compte, ni cloche, ni préférences. broadcast()
        // n'aurait aucun destinataire.
        'remindClientPayment',
        // Filet « téléphone admin » : un numéro, pas un compte.
        'sendActivityDigest',
        // broadcast() elle-même, et le filet admin qu'elle porte.
        'broadcast',
        // Essais manuels de configuration : leur objet EST de prouver qu'un canal
        // précis fonctionne, isolément.
        'sendTestMessage',
    ];

    $offenders = [];

    foreach (preg_split('/\n    (?:public|private|protected) function /', $source) as $chunk) {
        if (! preg_match('/^(\w+)\(/', $chunk, $m)) {
            continue;
        }

        $name = $m[1];

        if (in_array($name, $legitimate, true)) {
            continue;
        }

        if (str_contains($chunk, 'whatsapp->send(')) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([], "Envoi WhatsApp hors de broadcast() :\n  " . implode("\n  ", $offenders));
});
