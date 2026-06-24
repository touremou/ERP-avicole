<?php

use App\Models\Farm;
use App\Models\NotificationPreference;
use App\Models\Stock;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\NotificationHub;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
});

/** Préférence de notif abonnée aux alertes stock (heures calmes neutralisées). */
function stockPref(User $user, array $attrs = []): NotificationPreference
{
    return NotificationPreference::create(array_merge([
        'user_id'          => $user->id,
        'is_active'        => true,
        'channel_whatsapp' => false,
        'channel_database' => true,
        'channel_email'    => false,
        'alert_stock'      => true,
        'quiet_start'      => '00:00', // start == end → isQuietHour() toujours false
        'quiet_end'        => '00:00',
    ], $attrs));
}

function lowStock(): Stock
{
    return Stock::factory()->create([
        'category'         => 'conso',
        'current_quantity' => 1,
        'alert_threshold'  => 10,
    ]);
}

test('AlertNotification est mise en file d\'attente (ShouldQueue)', function () {
    expect(is_subclass_of(AlertNotification::class, ShouldQueue::class))->toBeTrue();
});

test('une alerte stock allume la cloche in-app de l\'abonné', function () {
    Notification::fake();
    $user = User::factory()->create();
    stockPref($user); // database ON, email OFF

    app(NotificationHub::class)->alertStockCritical(lowStock());

    Notification::assertSentTo($user, AlertNotification::class, function ($notif, $channels) {
        return in_array('database', $channels, true) && ! in_array('mail', $channels, true);
    });
});

test('avec le canal e-mail activé, l\'alerte part aussi par e-mail', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'eleveur@ferme.gn']);
    stockPref($user, ['channel_email' => true]);

    app(NotificationHub::class)->alertStockCritical(lowStock());

    Notification::assertSentTo($user, AlertNotification::class, function ($notif, $channels) {
        return in_array('database', $channels, true) && in_array('mail', $channels, true);
    });
});

test('un utilisateur non abonné à ce type n\'est pas notifié', function () {
    Notification::fake();
    $user = User::factory()->create();
    stockPref($user, ['alert_stock' => false]);

    app(NotificationHub::class)->alertStockCritical(lowStock());

    Notification::assertNothingSentTo($user);
});

test('préférences inactives : aucune notification', function () {
    Notification::fake();
    $user = User::factory()->create();
    stockPref($user, ['is_active' => false]);

    app(NotificationHub::class)->alertStockCritical(lowStock());

    Notification::assertNothingSentTo($user);
});
