<?php

use App\Models\Employee;
use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\NotificationPreference;
use App\Models\Provider;
use App\Models\Stock;
use App\Notifications\Channels\SmsGuineeChannel;
use App\Notifications\IndustrialAlert;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX INTERRUPTEURS QUI NE COMMANDAIENT RIEN.
 *
 * 1. LE CANAL SMS. La case « SMS » de Notifications › Préférences était
 *    enregistrée, validée, persistée — et lue par personne. Le SMS partait sur la
 *    seule condition d'une alerte de priorité haute. La décocher n'arrêtait rien.
 *
 *    Le piège : faire lire cette case suffisait à corriger le défaut, mais aurait
 *    COUPÉ le SMS pour tous, la colonne valant `false` depuis sa création. On
 *    aurait réduit la portée des alertes en croyant réparer un réglage — l'inverse
 *    du besoin d'une exploitation dont le WhatsApp ne part pas. La migration
 *    aligne donc l'état enregistré sur le comportement réel d'avant.
 *
 * 2. LE MIROIR HORS-LIGNE. Cinq référentiels descendaient sans aucun contrôle de
 *    module — annuaire nominatif du personnel, téléphones des fournisseurs,
 *    inventaire complet — à tout compte authentifié, quand leurs trois voisins
 *    vérifiaient bien un module.
 *
 *    Le garde retenu est `elevage.L`, et NON le module d'origine (rh, annuaire,
 *    logistique) : ces listes sont les sélecteurs des formulaires du mode terrain.
 *    Les verrouiller sur leur module d'origine viderait le sélecteur d'un
 *    technicien qui peut créer une bande sans avoir accès aux fiches du
 *    personnel — le défaut « liste d'employés vide » déjà vécu.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Ne laisse au rôle QUE les droits énumérés (slug => niveaux). */
function limitAbilities(int $roleId, array $abilities): void
{
    ModulePermission::where('role_id', $roleId)->update([
        'can_read' => false, 'can_create' => false, 'can_modify' => false, 'can_delete' => false,
    ]);

    foreach ($abilities as $slug => $levels) {
        if (! $module = Module::where('slug', $slug)->first()) {
            continue;
        }

        ModulePermission::updateOrCreate(
            ['role_id' => $roleId, 'module_id' => $module->id],
            [
                'can_read'   => str_contains($levels, 'L'),
                'can_create' => str_contains($levels, 'C'),
                'can_modify' => str_contains($levels, 'M'),
                'can_delete' => str_contains($levels, 'S'),
            ]
        );
    }
}

// ─────────────────────────────────────────────────────────────
// 1. LE CANAL SMS
// ─────────────────────────────────────────────────────────────

test('la portée du SMS n’est PAS réduite : la case vaut « activé » par défaut', function () {
    // Le point entier du choix retenu. Si ce test tombe, une exploitation perd
    // son canal de repli au déploiement sans avoir rien demandé.
    expect(NotificationPreference::DEFAULTS['channel_sms'])->toBeTrue();

    $prefs = NotificationPreference::resolveFor($this->adminUser);

    expect($prefs->channel_sms)->toBeTrue();
});

test('la migration a rétabli le SMS des comptes existants', function () {
    // Les lignes déjà en base valaient `false` — mais personne n'avait jamais pu
    // « choisir » cela, la case ne gouvernant rien. Il n'y a donc aucun choix à
    // préserver, et le laisser à false couperait le canal.
    $row = NotificationPreference::forUser($this->managerUser->id);
    expect($row->channel_sms)->toBeTrue();

    // Et le DÉFAUT DE COLONNE suit, pour tout compte créé plus tard : on lit
    // directement le schéma, une insertion nue étant impossible (la ligne de
    // préférences naît avec le compte).
    $columnDefault = collect(\Illuminate\Support\Facades\Schema::getColumns('notification_preferences'))
        ->firstWhere('name', 'channel_sms');

    expect($columnDefault)->not->toBeNull()
        ->and((string) ($columnDefault['default'] ?? ''))->toMatch('/1|true/i',
            'Le défaut de colonne doit valoir « activé », sinon un compte futur naît sans canal de repli.');
});

test('une alerte de priorité haute emprunte le canal SMS', function () {
    $notification = new IndustrialAlert(['priority' => 'high', 'title' => 'T', 'message' => 'M']);

    expect($notification->via($this->adminUser))->toContain(SmsGuineeChannel::class);
});

test('décocher la case ARRÊTE réellement le SMS — sans toucher à la cloche', function () {
    // C'est le défaut corrigé : avant, ce geste n'avait aucun effet.
    NotificationPreference::forUser($this->adminUser->id)->update(['channel_sms' => false]);

    $channels = (new IndustrialAlert(['priority' => 'high', 'title' => 'T', 'message' => 'M']))
        ->via($this->adminUser->fresh());

    expect($channels)->not->toContain(SmsGuineeChannel::class)
        // La cloche reste : couper le SMS ne doit pas rendre l'alerte invisible.
        ->and($channels)->toContain('database');
});

test('une alerte ordinaire n’envoie pas de SMS, même case cochée', function () {
    // Le SMS se paie au message : il reste borné aux priorités hautes. L'étendre
    // serait une décision de dépense, pas une correction de défaut.
    $channels = (new IndustrialAlert(['priority' => 'medium', 'title' => 'T', 'message' => 'M']))
        ->via($this->adminUser);

    expect($channels)->toBe(['database']);
});

// ─────────────────────────────────────────────────────────────
// 2. LE MIROIR HORS-LIGNE
// ─────────────────────────────────────────────────────────────

test('un compte SANS élevage ne récupère plus l’annuaire du personnel', function () {
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    // Un caissier : il encaisse, il n'a rien à faire dans les fiches du personnel.
    limitAbilities($this->managerUser->role_id, ['caisse' => 'LC']);

    $this->actingAs($this->managerUser->fresh())
        ->getJson('/api/offline/employees')->assertForbidden();

    $this->actingAs($this->managerUser->fresh())
        ->getJson('/api/offline/providers')->assertForbidden();

    $this->actingAs($this->managerUser->fresh())
        ->getJson('/api/offline/stocks')->assertForbidden();
});

test('le technicien qui saisit au terrain reçoit TOUJOURS ses sélecteurs', function () {
    // La non-régression qui compte. `elevage.L` seul — pas rh, pas annuaire, pas
    // logistique — doit suffire : c'est ce que possède l'écran « Nouvelle bande »
    // et le pointage quotidien.
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    Provider::factory()->create(['name' => 'Provenderie Kindia', 'status' => 'Actif']);
    Stock::factory()->create([
        'farm_id' => $this->farm->id, 'category' => Stock::CAT_CONSO,
        'item_name' => 'Aliment démarrage', 'current_quantity' => 50, 'unit_price' => 100,
    ]);

    limitAbilities($this->managerUser->role_id, ['elevage' => 'LC']);

    $user = $this->managerUser->fresh();

    $this->actingAs($user)->getJson('/api/offline/employees')->assertOk()
        ->assertJsonCount(1);
    $this->actingAs($user)->getJson('/api/offline/providers')->assertOk()
        ->assertJsonFragment(['name' => 'Provenderie Kindia']);
    $this->actingAs($user)->getJson('/api/offline/stocks')->assertOk()
        ->assertJsonFragment(['item_name' => 'Aliment démarrage']);
    $this->actingAs($user)->getJson('/api/offline/protocols')->assertOk();
    $this->actingAs($user)->getJson('/api/offline/norms')->assertOk();
});

test('AUCUN endpoint du miroir ne descend de données sans contrôle de module', function () {
    // Le garde-fou : c'est l'absence de garde sur cinq endroits sur huit qui
    // constituait le défaut. Qu'un endpoint futur s'ajoute sans garde, et ce test
    // le dira — l'oubli ne se voit pas à la lecture d'un groupe de routes.
    $ungated = [];

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'offline.')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $hasGate = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));

        // Les endpoints portés par un contrôleur vérifient leur module dans le
        // corps de la méthode (Gate::denies) : on les accepte s'ils le font.
        if (! $hasGate) {
            $action = $route->getActionName();

            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action, 2);
                $file = (new ReflectionMethod($class, $method))->getFileName();
                $start = (new ReflectionMethod($class, $method))->getStartLine();
                $end = (new ReflectionMethod($class, $method))->getEndLine();
                $body = implode('', array_slice(file($file), $start - 1, $end - $start + 1));

                if (str_contains($body, 'Gate::denies') || str_contains($body, 'Gate::allows')) {
                    continue;
                }
            }

            $ungated[] = $route->getName() . '  (' . $route->uri() . ')';
        }
    }

    expect($ungated)->toBe([], "Endpoints du miroir hors-ligne sans contrôle de module :\n  " . implode("\n  ", $ungated));
});
