<?php

use App\Models\Batch;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\Stock;
use App\Services\NotificationHub;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE ALERTE MÈNE QUELQUE PART.
 *
 * Demandé par le promoteur : « certaines notifications indiquent des actions à
 * réaliser, il serait intéressant d'être redirigé au clic ».
 *
 * CE QUE L'AUDIT A TROUVÉ — le mécanisme existait DE BOUT EN BOUT : la cloche
 * redirige vers data['url'], le service worker l'ouvre au clic sur la bannière,
 * l'e-mail en fait un bouton. Mais AUCUNE alerte ne renseignait cette adresse :
 * `broadcast()` construisait sa charge utile avec type/titre/message/gravité, et
 * rien d'autre. Des lecteurs, pas de rédacteur — la troisième occurrence de ce
 * motif aujourd'hui.
 *
 * Une alerte dit qu'il y a QUELQUE CHOSE À FAIRE. Sans destination, elle laisse
 * chercher où — et sur un téléphone, chercher signifie renoncer.
 *
 * DÉFAUT TROUVÉ AU PASSAGE : la destination de repli du push était « /alertes »,
 * une adresse qui n'a jamais existé. Le clic sur une bannière ouvrait donc une
 * page introuvable, ce qui se lit comme une application cassée.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

test('chaque type d’alerte a une destination, et elle EXISTE', function () {
    // Une adresse inventée renverrait une page introuvable au clic : pire que
    // pas de lien, car elle se lit comme une panne.
    $routes = collect(app('router')->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->map(fn ($r) => '/' . ltrim($r->uri(), '/'))
        ->all();

    foreach ([
        'alert_mortality', 'alert_stock', 'alert_energy', 'alert_sales',
        'alert_fraud', 'alert_budget', 'alert_haccp', 'alert_hr_contract',
        'alert_hr_attendance', 'alert_leave', 'daily_summary',
        'type_inconnu',
    ] as $type) {
        $url = NotificationHub::destinationFor($type);

        expect($url)->toStartWith('/');

        // NB : `toContain($valeur, $message)` traite le second argument comme une
        // AUTRE valeur attendue — le message ne s'affiche pas, il se cherche.
        // On assemble donc l'échec nous-mêmes.
        expect(in_array($url, $routes, true))->toBeTrue(
            "La destination « {$url} » du type « {$type} » ne correspond à aucune route."
        );
    }
});

test('un type inconnu mène au centre d’alertes, pas nulle part', function () {
    // La notification y est relisible en entier : mieux qu'un clic sans effet.
    expect(NotificationHub::destinationFor('quelque_chose_de_neuf'))->toBe('/notifications');
});

test('« /alertes » est l’écran du TERRAIN, pas une adresse morte', function () {
    // CORRECTION D'UNE ERREUR QUE J'AI FAITE : j'avais pris « /alertes » pour une
    // adresse inexistante et l'avais remplacée par « /notifications ». C'est en
    // réalité le centre d'alertes de la PWA — une route mobile, pas web.
    //
    // Le remplacer envoyait le push vers un chemin que le routeur du téléphone
    // ignore : le clic retombait sur l'accueil. Deux applications, deux jeux de
    // routes ; confondre les deux revient à ne mener nulle part.
    $mobileRoutes = file_get_contents(base_path('mobile/src/offline/access.ts'));

    expect($mobileRoutes)->toContain("'/alertes'")
        ->and(NotificationHub::mobileDestinationFor('type_inconnu'))->toBe('/alertes');
});

test('une alerte de mortalité ouvre LE LOT concerné', function () {
    // La liste obligerait à retrouver la ligne : c'est précisément le geste
    // qu'on voulait épargner.
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    app(NotificationHub::class)->alertMortality($batch, 12, 6.2);

    $notification = $this->adminUser->fresh()->notifications()->latest()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['url'])->toBe(route('batches.show', $batch->id, absolute: false));
});

test('une alerte de stock ouvre L’ARTICLE concerné', function () {
    $stock = Stock::factory()->create([
        'farm_id' => $this->farm->id, 'current_quantity' => 2, 'alert_threshold' => 10,
    ]);

    app(NotificationHub::class)->alertStockCritical($stock);

    $notification = $this->adminUser->fresh()->notifications()->latest()->first();

    expect($notification->data['url'])->toBe(route('stocks.show', $stock->id, absolute: false));
});

test('une alerte SANS objet précis mène à l’écran du sujet', function () {
    // Un contrôle planifié porte sur plusieurs enregistrements : la liste est
    // alors la bonne destination, pas un choix arbitraire parmi eux.
    app(NotificationHub::class)->alertHaccp('Registre incomplet', 'Températures');

    $notification = $this->adminUser->fresh()->notifications()->latest()->first();

    expect($notification->data['url'])->toBe(NotificationHub::destinationFor('alert_haccp'));
});

test('la cloche REDIRIGE vers la destination au clic', function () {
    // Le mécanisme existait déjà : ce test le gèle, puisqu'il n'était jamais
    // exercé faute d'adresse à suivre.
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    app(NotificationHub::class)->alertMortality($batch, 12, 6.2);

    $notification = $this->adminUser->fresh()->notifications()->latest()->first();

    $this->actingAs($this->adminUser)
        ->get(route('notifications.read', $notification->id))
        ->assertRedirect(route('batches.show', $batch->id, absolute: false));

    // …et l'alerte est bien marquée lue au passage.
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('la cloche reçoit l’adresse WEB, le push celle du TERRAIN', function () {
    // Elles ne peuvent PAS être identiques : le bureau connaît /batches/12, le
    // téléphone ne connaît que ses onze écrans. Une seule adresse pour les deux
    // en casse forcément une.
    //
    // Ce qui reste unique, c'est la DÉCLARATION : les deux sortent de la même
    // table, pas de deux cartes qui divergeraient.
    $payload = [
        'type' => 'alert_sales', 'title' => 'Vente', 'message' => 'V-001',
        'url' => '/sales/7', 'mobile_url' => '/commerce/journal',
    ];

    $notification = new \App\Notifications\AlertNotification($payload, ['database', 'webpush']);

    expect($notification->toDatabase($this->adminUser)['url'])->toBe('/sales/7')
        ->and($notification->toWebPush($this->adminUser)['url'])->toBe('/commerce/journal');
});

test('chaque destination du TERRAIN est une route que la PWA connaît', function () {
    // Une adresse inconnue de son routeur renvoie à l'accueil — un clic sans
    // effet, indiscernable d'une panne.
    $mobileRoutes = file_get_contents(base_path('mobile/src/offline/access.ts'));

    foreach ([
        'alert_mortality', 'alert_stock', 'alert_energy', 'alert_sales',
        'alert_fraud', 'alert_budget', 'alert_haccp', 'alert_hr_contract',
        'alert_hr_attendance', 'alert_leave', 'daily_summary', 'type_inconnu',
    ] as $type) {
        $path = NotificationHub::mobileDestinationFor($type);

        expect(str_contains($mobileRoutes, "'{$path}'"))->toBeTrue(
            "L'écran « {$path} » du type « {$type} » n'existe pas dans la PWA."
        );
    }
});

test('une alerte ANTÉRIEURE, sans adresse, mène quand même quelque part', function () {
    // Celles qui remplissent la cloche aujourd'hui ont été créées avant ce
    // correctif : sans repli, elles resteraient mortes au clic — et ce sont
    // précisément celles que le promoteur essaie d'ouvrir.
    $this->adminUser->notify(new \App\Notifications\AlertNotification(
        ['type' => 'alert_stock', 'title' => 'Ancienne', 'message' => 'Sans adresse'],
        ['database']
    ));

    $notification = $this->adminUser->fresh()->notifications()->latest()->first();

    // Une alerte sans adresse la porte à null (et les lignes créées AVANT ce
    // champ n'ont pas la clef du tout) : « ?? » couvre les deux cas.
    expect($notification->data['url'] ?? null)->toBeNull();

    $this->actingAs($this->adminUser)
        ->get(route('notifications.read', $notification->id))
        ->assertRedirect(NotificationHub::destinationFor('alert_stock'));
});

/*
 * DÉFAUT DE PRODUCTION TROUVÉ EN COURS DE ROUTE, sans rapport avec les
 * notifications — révélé parce que la date de l'environnement a basculé au 31.
 *
 * `Expense::scopeBetweenDates` comparait la colonne — un DATETIME, donc
 * « 2026-07-31 00:00:00 » — à une borne écrite en date seule, « 2026-07-31 ».
 * La chaîne la plus longue étant la plus grande, TOUTE dépense du DERNIER JOUR
 * du mois était exclue.
 *
 * Le défaut ne se voyait qu'au dernier jour, et frappait précisément ce qui s'y
 * calcule : le cumul mensuel. Un dépassement de budget franchi le 31 n'était
 * jamais alerté, et les états du mois sous-estimaient les charges du dernier
 * jour — silencieusement.
 */

test('une dépense du DERNIER JOUR du mois compte dans le cumul', function () {
    $lastDay = now()->endOfMonth()->toDateString();

    Expense::create([
        'reference' => 'DEP-FIN', 'category' => 'divers', 'label' => 'Dernier jour',
        'amount' => 5000, 'expense_date' => $lastDay, 'status' => 'valide',
        'user_id' => $this->adminUser->id,
    ]);

    $total = (float) Expense::validated()
        ->where('category', 'divers')
        ->betweenDates(now()->startOfMonth()->toDateString(), $lastDay)
        ->sum('amount');

    expect($total)->toBe(5000.0);
});

test('une dépense du PREMIER jour compte aussi — les deux bornes sont incluses', function () {
    $firstDay = now()->startOfMonth()->toDateString();

    Expense::create([
        'reference' => 'DEP-DEB', 'category' => 'divers', 'label' => 'Premier jour',
        'amount' => 3000, 'expense_date' => $firstDay, 'status' => 'valide',
        'user_id' => $this->adminUser->id,
    ]);

    $total = (float) Expense::validated()
        ->where('category', 'divers')
        ->betweenDates($firstDay, now()->endOfMonth()->toDateString())
        ->sum('amount');

    expect($total)->toBe(3000.0);
});

test('une dépense HORS période reste exclue', function () {
    // Élargir les bornes ne doit pas tout ramasser : le scope garde son sens.
    Expense::create([
        'reference' => 'DEP-HORS', 'category' => 'divers', 'label' => 'Mois suivant',
        'amount' => 9000, 'expense_date' => now()->addMonth()->startOfMonth()->toDateString(),
        'status' => 'valide', 'user_id' => $this->adminUser->id,
    ]);

    $total = (float) Expense::validated()
        ->where('category', 'divers')
        ->betweenDates(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString())
        ->sum('amount');

    expect($total)->toBe(0.0);
});

test('un budget franchi le DERNIER jour du mois alerte bien', function () {
    // La conséquence concrète : sans ce correctif, un dépassement survenu le 31
    // ne déclenchait rien du tout.
    Budget::create([
        'category' => 'divers', 'year' => now()->year, 'month' => now()->month, 'amount' => 10000,
    ]);

    $hub = Mockery::mock(NotificationHub::class);
    $hub->shouldReceive('alertBudgetOverrun')->once();
    $this->app->instance(NotificationHub::class, $hub);

    $lastDay = now()->endOfMonth()->toDateString();

    foreach ([['DEP-A', 8000], ['DEP-B', 5000]] as [$ref, $amount]) {
        Expense::create([
            'reference' => $ref, 'category' => 'divers', 'label' => 'Fin de mois',
            'amount' => $amount, 'expense_date' => $lastDay, 'status' => 'valide',
            'user_id' => $this->adminUser->id,
        ]);
    }
});

/*
 * SUITE — signalé après le lot précédent : « la redirection fonctionne sur le
 * web, pas sur mobile. Ou peut-être que je suis notifié d'une action non
 * autorisée par mon profil et rejeté silencieusement. »
 *
 * L'HYPOTHÈSE ÉTAIT JUSTE EN PARTIE, et deux causes se cumulaient.
 *
 * 1. MA CARTE MOBILE ÉTAIT TROP PAUVRE. J'avais conclu « la PWA n'a pas d'écran
 *    de détail » d'un inventaire de routes TRONQUÉ — je n'en avais lu que les
 *    onze premières. Elle en compte une cinquantaine : fiche de lot, feuille de
 *    présence, stocks, tournée de températures. Huit types d'alerte sur onze
 *    pointaient donc vers /alertes, c'est-à-dire l'écran où l'on se trouvait
 *    déjà : le clic ne montrait rien, ce qui se lit comme « ça ne marche pas ».
 *
 * 2. LE DROIT N'ÉTAIT PAS VÉRIFIÉ AVANT DE NAVIGUER. Une alerte part à tous les
 *    abonnés d'un type ; l'écran qui la traite est réservé. Le clic menait donc
 *    à « Accès refusé » — pas un rejet silencieux, mais un mur qui se lit comme
 *    une panne alors que c'est une règle.
 */

test('le terrain reçoit l’écran où l’on AGIT, pas le centre d’alertes', function () {
    // C'est la correction du défaut signalé : huit types sur onze menaient à
    // l'écran où l'on se trouvait déjà.
    expect(NotificationHub::mobileDestinationFor('alert_hr_attendance'))->toBe('/rh/presence')
        ->and(NotificationHub::mobileDestinationFor('alert_stock'))->toBe('/logistique/stocks')
        ->and(NotificationHub::mobileDestinationFor('alert_haccp'))->toBe('/abattoir/temperature/tournee')
        ->and(NotificationHub::mobileDestinationFor('alert_energy'))->toBe('/ressources/ravitaillement');
});

test('une alerte de mortalité ouvre LE LOT sur le terrain aussi', function () {
    // Le mobile a bien une fiche de lot : /lot/:batchId. Je l'avais manquée.
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    app(NotificationHub::class)->alertMortality($batch, 12, 6.2);

    $notification = $this->adminUser->fresh()->notifications()->latest()->first();

    expect($notification->data['mobile_url'])->toBe("/lot/{$batch->id}");
});

test('les destinations ADMINISTRATIVES restent au centre d’alertes', function () {
    // Le terrain n'a pas d'écran de contrats ni de congés : l'y envoyer
    // produirait un « Accès refusé » ou un écran vide. Y renoncer est un choix,
    // pas un oubli.
    expect(NotificationHub::mobileDestinationFor('alert_hr_contract'))->toBe('/alertes')
        ->and(NotificationHub::mobileDestinationFor('alert_leave'))->toBe('/alertes');
});

test('le mobile vérifie le DROIT avant de naviguer', function () {
    // L'hypothèse du promoteur : notifié d'une action que son profil n'autorise
    // pas. Le contrôle existe désormais côté écran, et il le DIT.
    $screen = file_get_contents(base_path('mobile/src/features/notifications/NotificationsScreen.tsx'));

    expect($screen)->toContain('accessForPath(n.url)')
        ->and($screen)->toContain('allows(spec,')
        // …et le refus est affiché, pas avalé.
        ->and($screen)->toContain('setRefused');
});

test('le résolveur de droit rapproche un chemin CONCRET de son gabarit', function () {
    // « /lot/12 » doit retrouver « /lot/:batchId » : sinon toute destination
    // portant un identifiant serait jugée inconnue et refusée.
    $access = file_get_contents(base_path('mobile/src/offline/access.ts'));

    expect($access)->toContain('export function accessForPath')
        ->and($access)->toContain("segment.startsWith(':')");
});
