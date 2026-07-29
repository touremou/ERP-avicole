<?php

use App\Models\NotificationTemplate;
use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CE QUI SORT DU SYSTÈME — identité de l'expéditeur et modèles de message.
 *
 * Audit demandé par le promoteur : « on peut revoir la notification envoyée hors
 * système, template (documents, messages), paramétrages ».
 *
 * DÉFAUT PRINCIPAL — le nom de l'exploitation signait TROIS choses par trois
 * chemins différents :
 *
 *   • les documents (relevés client, tickets, bulletins) → general.company_name,
 *     saisi à l'installation et modifiable dans les Réglages ;
 *   • les rapports cultures → general.farm_name, une SECONDE clef, jamais
 *     alimentée : ces PDF sortaient signés « ERP Avicole » ;
 *   • les messages WhatsApp → config('whatsapp.farm_name'), c'est-à-dire une
 *     variable d'environnement INACCESSIBLE depuis l'application.
 *
 * Conséquence invisible depuis n'importe lequel des trois : le promoteur
 * saisissait le nom de son exploitation dans les Réglages, et ses alertes
 * continuaient de partir signées « AviSmart » sur les téléphones de ses
 * techniciens et de ses clients. Rien ne le lui disait.
 *
 * SECOND DÉFAUT — l'édition d'un modèle n'était pas validée. Une variable mal
 * orthographiée est remplacée par du VIDE à l'envoi : le message partait troué,
 * privé du chiffre qui le rendait utile.
 */

beforeEach(function () {
    $this->setUpRbac();
    Setting::clearCache();
});

// ── IDENTITÉ SORTANTE ────────────────────────────────────────────────────

test('ce qui est saisi dans les Réglages signe les messages sortants', function () {
    // Le cœur du défaut : le nom saisi n'atteignait pas WhatsApp.
    Setting::set('general.company_name', 'Biocrest SARL');
    Setting::clearCache();

    expect(Setting::companyName())->toBe('Biocrest SARL');

    // Et le hub ne lit plus le .env : ce que l'utilisateur saisit l'emporte.
    $hub = file_get_contents(app_path('Services/NotificationHub.php'));

    expect($hub)->not->toContain("config('whatsapp.farm_name'")
        ->and($hub)->toContain('Setting::companyName()');
});

test('« general.farm_name » n’a JAMAIS été un réglage déclaré', function () {
    // C'est la racine du défaut des rapports cultures : quatre PDF lisaient une
    // clef absente de la table des réglages, donc imprimaient toujours leur
    // texte de repli — « ERP Avicole » — quoi que le promoteur saisisse.
    $migration = file_get_contents(
        base_path('database/migrations/2026_06_05_000001_create_settings_table.php')
    );

    expect($migration)->toContain("'key' => 'company_name'")
        ->and($migration)->not->toContain("'key' => 'farm_name'");
});

test('le réglage saisi l’emporte sur la variable d’environnement', function () {
    // L'ordre va du plus intentionnel au plus lointain : ce que l'utilisateur a
    // saisi passe avant ce que le serveur suppose.
    config(['whatsapp.farm_name' => 'Nom du serveur']);
    Setting::set('general.company_name', 'Biocrest SARL');
    Setting::clearCache();

    expect(Setting::companyName())->toBe('Biocrest SARL');
});

test('sans aucun réglage, un nom reste servi — jamais du vide', function () {
    // Un document ou un message signé par une chaîne vide serait pire que par
    // un nom générique : il paraîtrait tronqué.
    expect(Setting::companyName())->not->toBe('')
        ->and(Setting::companyName())->not->toBeNull();
});

test('les rapports cultures ne signent plus « ERP Avicole »', function () {
    // Seconde clef jamais alimentée : quatre PDF partaient au nom du logiciel,
    // pas à celui de l'exploitation.
    foreach (['yield', 'campaigns', 'inputs', 'transformations'] as $report) {
        $view = file_get_contents(resource_path("views/cultures/reports/pdf/{$report}.blade.php"));

        expect($view)->not->toContain("setting('general.farm_name'")
            ->and($view)->toContain('Setting::companyName()');
    }
});

test('aucune vue ne lit encore la clef héritée en direct', function () {
    // Formulation générique : c'est la re-divergence qu'on interdit, pas les
    // quatre fichiers déjà corrigés.
    $found = [];

    foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
        if (str_contains(file_get_contents($file->getPathname()), "setting('general.farm_name'")) {
            $found[] = $file->getRelativePathname();
        }
    }

    expect($found)->toBe([]);
});

// ── MODÈLES DE MESSAGE ───────────────────────────────────────────────────

test('le catalogue est cohérent : aucun modèle ne réclame une variable absente', function () {
    // Un modèle livré qui attendrait une variable que le code ne fournit pas
    // partirait troué dès la première alerte, sans qu'on l'ait jamais édité.
    foreach (NotificationTemplate::catalog() as $key => $meta) {
        $unknown = NotificationTemplate::unknownVariables($key, $meta['default']);

        expect($unknown)->toBe([], "Le modèle livré « {$key} » emploie une variable non déclarée.");
    }
});

test('éditer un modèle avec une variable inventée est REFUSÉ', function () {
    // « quantite » au lieu de « quantity » : l'alerte de rupture de stock
    // serait partie sans le chiffre restant — donc sans son information.
    $template = NotificationTemplate::firstOrCreate(
        ['key' => 'alert_stock'],
        ['channel' => 'whatsapp', 'label' => 'Rupture de stock',
         'body' => NotificationTemplate::catalog()['alert_stock']['default'], 'is_active' => true]
    );

    $original = $template->body;

    $this->actingAs($this->adminUser)
        ->put(route('notifications.templates.update', $template), [
            'body'      => 'Restant : {{quantite}} {{unit}}',
            'is_active' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($template->fresh()->body)->toBe($original);
});

test('le refus NOMME la variable fautive et celles qui existent', function () {
    // Un refus qui ne dit pas quoi écrire fait recommencer à l'aveugle.
    $template = NotificationTemplate::firstOrCreate(
        ['key' => 'alert_stock'],
        ['channel' => 'whatsapp', 'label' => 'Rupture de stock',
         'body' => NotificationTemplate::catalog()['alert_stock']['default'], 'is_active' => true]
    );

    $this->actingAs($this->adminUser)
        ->put(route('notifications.templates.update', $template), [
            'body' => 'Restant : {{quantite}}',
        ]);

    expect(session('error'))->toContain('{{quantite}}')
        ->and(session('error'))->toContain('{{quantity}}');
});

test('un modèle correct s’enregistre normalement', function () {
    // Le garde-fou ne doit pas empêcher la personnalisation, qui est sa raison
    // d'être : on peut retirer des variables, en réordonner, changer le texte.
    $template = NotificationTemplate::firstOrCreate(
        ['key' => 'alert_stock'],
        ['channel' => 'whatsapp', 'label' => 'Rupture de stock',
         'body' => NotificationTemplate::catalog()['alert_stock']['default'], 'is_active' => true]
    );

    $this->actingAs($this->adminUser)
        ->put(route('notifications.templates.update', $template), [
            'body'      => 'Stock bas : {{item_name}} — {{quantity}} {{unit}}',
            'is_active' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($template->fresh()->body)->toContain('Stock bas');
});

test('un texte SANS aucune variable est accepté', function () {
    // Cas limite légitime : un message fixe (« Passez au bureau »).
    $template = NotificationTemplate::firstOrCreate(
        ['key' => 'alert_stock'],
        ['channel' => 'whatsapp', 'label' => 'Rupture de stock',
         'body' => NotificationTemplate::catalog()['alert_stock']['default'], 'is_active' => true]
    );

    $this->actingAs($this->adminUser)
        ->put(route('notifications.templates.update', $template), [
            'body' => 'Rupture de stock : voir le magasin.', 'is_active' => 1,
        ])
        ->assertSessionHas('success');
});

test('à l’ENVOI, une variable inconnue reste silencieuse', function () {
    // Le garde-fou est à la saisie. À l'envoi, mieux vaut une phrase incomplète
    // qu'un « {{montant}} » brut sur le téléphone d'un client : le blocage
    // arrive trop tard, il ne ferait qu'ajouter du bruit à un message déjà parti.
    $rendered = NotificationTemplate::interpolate('Reste : {{inconnue}} kg', ['autre' => 1]);

    expect($rendered)->toBe('Reste :  kg')
        ->and($rendered)->not->toContain('{{');
});
