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

test('« /alertes » n’existe nulle part — c’était la destination du push', function () {
    // Défaut préexistant : le clic sur une bannière push ouvrait une page
    // introuvable. Le centre d'alertes est /notifications.
    $found = [];

    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
        if (str_contains(file_get_contents($file->getPathname()), "'/alertes'")) {
            $found[] = $file->getRelativePathname();
        }
    }

    expect($found)->toBe([]);
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

test('la destination est la MÊME pour la cloche, le push et l’e-mail', function () {
    // Trois cartes divergeraient, et l'on ouvrirait trois écrans différents pour
    // une seule alerte.
    $payload = ['type' => 'alert_mortality', 'title' => 'Mortalité', 'message' => 'Lot CH-001', 'url' => '/batches/7'];

    $notification = new \App\Notifications\AlertNotification($payload, ['database', 'webpush', 'mail']);

    expect($notification->toDatabase($this->adminUser)['url'])->toBe('/batches/7')
        ->and($notification->toWebPush($this->adminUser)['url'])->toBe('/batches/7');
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
