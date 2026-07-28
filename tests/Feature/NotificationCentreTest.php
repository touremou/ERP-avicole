<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE ALERTE LUE NE DOIT PAS DISPARAÎTRE.
 *
 * Signalé depuis le terrain : « lorsqu'on clique, cela passe en lu et disparaît
 * sans même le temps de bien lire ».
 *
 * La cloche n'affichait que les notifications NON LUES. Cliquer sur l'une d'elles
 * la marquait lue — elle quittait donc la liste immédiatement — et AUCUN écran ne
 * permettait de la retrouver : l'entrée « Historique » du menu Notifications
 * désigne le journal des messages SORTANTS (WhatsApp/SMS), réservé aux
 * administrateurs, pas ses propres alertes.
 *
 * Le mobile faisait déjà la bonne chose : son centre d'alertes liste tout, les
 * lues estompées. Le WEB était l'exception — encore deux comportements pour la
 * même donnée.
 *
 * Autre gêne signalée par la capture : deux alertes « Relevés de température
 * manquants » identiques, qui se lisaient comme un doublon. Ce n'en était pas un
 * — le contrôle HACCP tourne une fois par jour, c'étaient deux jours différents.
 * La cloche n'affichait simplement AUCUNE date.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Crée une notification in-app pour un utilisateur, lue ou non. */
function inAppNotification(User $user, array $data = [], ?string $readAt = null): string
{
    $id = (string) \Illuminate\Support\Str::uuid();

    DB::table('notifications')->insert([
        'id'              => $id,
        'type'            => 'App\\Notifications\\FarmAlert',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id'   => $user->id,
        'data'            => json_encode(array_merge([
            'title'    => 'Relevés de température manquants',
            'message'  => 'Registre incomplet : 0/2 relevés effectués aujourd’hui.',
            'severity' => 'normal',
            'type'     => 'alert_haccp',
        ], $data)),
        'read_at'    => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('le centre d’alertes liste les alertes LUES comme non lues', function () {
    inAppNotification($this->adminUser, ['title' => 'Alerte fraîche']);
    inAppNotification($this->adminUser, ['title' => 'Alerte déjà lue'], readAt: now()->subDay()->toDateTimeString());

    $this->actingAs($this->adminUser)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Alerte fraîche')
        // C'est tout l'objet du lot : elle ne disparaît plus.
        ->assertSee('Alerte déjà lue');
});

test('cliquer une alerte ne la fait plus disparaître de l’historique', function () {
    $id = inAppNotification($this->adminUser, ['title' => 'Alerte à lire']);

    $this->actingAs($this->adminUser)->get(route('notifications.read', $id))->assertRedirect();

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();

    $this->actingAs($this->adminUser)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Alerte à lire');
});

test('la cloche affiche aussi les alertes lues, estompées', function () {
    inAppNotification($this->adminUser, ['title' => 'Alerte ancienne'], readAt: now()->subHour()->toDateTimeString());

    $this->actingAs($this->adminUser)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Alerte ancienne')
        // Et le chemin vers l'historique, qui n'existait pas.
        ->assertSee('Voir toutes les alertes');
});

test('la cloche DATE chaque alerte', function () {
    // Sans date, deux alertes du même contrôle à deux jours d'intervalle se
    // lisaient comme un doublon.
    inAppNotification($this->adminUser, ['title' => 'Contrôle du jour']);

    $this->actingAs($this->adminUser)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('il y a', false);
});

test('le filtre « non lues » ne montre que celles-là', function () {
    inAppNotification($this->adminUser, ['title' => 'Toujours en attente']);
    inAppNotification($this->adminUser, ['title' => 'Traitée hier'], readAt: now()->subDay()->toDateTimeString());

    // On lit la LISTE, pas la page : la cloche du gabarit affiche de son côté les
    // dernières alertes, lues comprises — c'est justement ce que corrige ce lot.
    $response = $this->actingAs($this->adminUser)
        ->get(route('notifications.index', ['vue' => 'non_lues']))->assertOk();

    $titles = collect($response->viewData('notifications')->items())
        ->map(fn ($n) => $n->data['title']);

    expect($titles)->toContain('Toujours en attente')
        ->and($titles)->not->toContain('Traitée hier');
});

test('chacun ne voit QUE ses propres alertes', function () {
    inAppNotification($this->adminUser, ['title' => 'Pour le promoteur']);
    inAppNotification($this->readonlyUser, ['title' => 'Pour le technicien']);

    $this->actingAs($this->readonlyUser)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Pour le technicien')
        ->assertDontSee('Pour le promoteur');
});

test('le centre d’alertes n’exige AUCUN droit de module', function () {
    // Ce sont ses alertes : un technicien sans droit RH doit y accéder.
    inAppNotification($this->readonlyUser, ['title' => 'Ma tâche du jour']);

    $this->actingAs($this->readonlyUser)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Ma tâche du jour');
});

test('« tout marquer comme lu » ne supprime rien', function () {
    inAppNotification($this->adminUser, ['title' => 'Alerte une']);
    inAppNotification($this->adminUser, ['title' => 'Alerte deux']);

    $this->actingAs($this->adminUser)->post(route('notifications.read-all'))->assertRedirect();

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(2);

    $this->actingAs($this->adminUser)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Alerte une')
        ->assertSee('Alerte deux');
});

test('le compteur de la cloche ne compte que les NON lues', function () {
    inAppNotification($this->adminUser, ['title' => 'Non lue']);
    inAppNotification($this->adminUser, ['title' => 'Lue'], readAt: now()->toDateTimeString());

    // La cloche liste 2 alertes mais n'en signale qu'une comme nouvelle.
    expect($this->adminUser->unreadNotifications()->count())->toBe(1)
        ->and($this->adminUser->notifications()->count())->toBe(2);
});

test('le centre d’alertes n’est pas le journal des messages sortants', function () {
    // La confusion d'origine : « Historique » du menu Notifications désigne les
    // envois WhatsApp/SMS, réservés aux administrateurs.
    expect(route('notifications.index'))->not->toBe(route('notifications.logs'));

    $this->actingAs($this->readonlyUser)->get(route('notifications.logs'))
        ->assertRedirect();   // réservé, et c'est très bien
});
