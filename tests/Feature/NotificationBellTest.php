<?php

use App\Models\Farm;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\Notification;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);
});

test('« tout marquer comme lu » vide les notifications non lues', function () {
    $user = User::factory()->create();
    $user->notify(new AlertNotification(['type' => 'alert_stock', 'title' => 'T', 'message' => 'M', 'severity' => 'critique'], ['database']));

    expect($user->unreadNotifications()->count())->toBe(1);

    $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('cliquer une notification la marque lue et redirige vers sa cible', function () {
    $user = User::factory()->create();
    $user->notify(new AlertNotification(['type' => 'x', 'title' => 'T', 'message' => 'M', 'severity' => 'normal', 'url' => '/dashboard'], ['database']));

    $notif = $user->notifications()->first();

    $this->actingAs($user)->get(route('notifications.read', $notif->id))->assertRedirect('/dashboard');

    expect($notif->fresh()->read_at)->not->toBeNull();
});

test('un utilisateur ne peut pas marquer lue la notification d\'un autre', function () {
    $owner   = User::factory()->create();
    $other   = User::factory()->create();
    $owner->notify(new AlertNotification(['type' => 'x', 'title' => 'T', 'message' => 'M'], ['database']));
    $notif = $owner->notifications()->first();

    $this->actingAs($other)->get(route('notifications.read', $notif->id)); // ne doit rien marquer

    expect($notif->fresh()->read_at)->toBeNull();
});

test('une alerte CRITIQUE envoie un e-mail au filet admin (whatsapp.admin_email)', function () {
    Notification::fake();
    Setting::set('whatsapp.admin_email', 'admin@ferme.gn');

    $stock = Stock::factory()->create(['category' => 'conso', 'current_quantity' => 1, 'alert_threshold' => 10]);
    app(NotificationHub::class)->alertStockCritical($stock); // sévérité « critique »

    Notification::assertSentOnDemand(AlertNotification::class, function ($notif, $channels, $notifiable) {
        return in_array('mail', $channels, true)
            && ($notifiable->routes['mail'] ?? null) === 'admin@ferme.gn';
    });
});

test('sans adresse admin configurée, aucun e-mail de secours n\'est envoyé', function () {
    Notification::fake();
    Setting::set('whatsapp.admin_email', ''); // filet désactivé

    $stock = Stock::factory()->create(['category' => 'conso', 'current_quantity' => 1, 'alert_threshold' => 10]);
    app(NotificationHub::class)->alertStockCritical($stock);

    Notification::assertNothingSent();
});
