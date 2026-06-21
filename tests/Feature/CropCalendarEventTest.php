<?php

use App\Models\CropCalendarEvent;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('un manager peut accéder à la liste des événements', function () {
    $this->actingAs($this->managerUser)
        ->get(route('crop-calendar-events.index'))
        ->assertStatus(200);
});

test('un lecteur seul peut aussi accéder à la liste des événements', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('crop-calendar-events.index'))
        ->assertStatus(200);
});

test('un manager peut accéder au formulaire de création', function () {
    $this->actingAs($this->managerUser)
        ->get(route('crop-calendar-events.create'))
        ->assertStatus(200)
        ->assertSee('Ajouter');
});

test('un lecteur seul ne peut pas accéder au formulaire de création', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('crop-calendar-events.create'))
        ->assertRedirect();
});

test('un manager peut créer un événement calendaire', function () {
    $this->actingAs($this->managerUser)
        ->post(route('crop-calendar-events.store'), [
            'title'      => 'Traitement herbicide parcelle A',
            'event_type' => 'traitement',
            'event_date' => '2026-07-10',
            'color'      => 'green',
        ])
        ->assertRedirect();

    expect(CropCalendarEvent::withoutFarm()->where('title', 'Traitement herbicide parcelle A')->exists())->toBeTrue();
});

test('un lecteur seul ne peut pas créer un événement', function () {
    $countBefore = CropCalendarEvent::withoutFarm()->count();

    $this->actingAs($this->readonlyUser)
        ->post(route('crop-calendar-events.store'), [
            'title'      => 'Événement non autorisé',
            'event_type' => 'observation',
            'event_date' => '2026-07-10',
        ])
        ->assertRedirect();

    expect(CropCalendarEvent::withoutFarm()->count())->toBe($countBefore);
});

test('un manager peut modifier un événement', function () {
    $event = CropCalendarEvent::create([
        'farm_id'    => $this->farm->id,
        'title'      => 'Observation initiale',
        'event_type' => 'observation',
        'event_date' => '2026-07-15',
        'color'      => 'green',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('crop-calendar-events.update', $event), [
            'title'      => 'Observation modifiée',
            'event_type' => 'observation',
            'event_date' => '2026-07-15',
            'color'      => 'blue',
        ])
        ->assertRedirect();

    $updated = CropCalendarEvent::withoutFarm()->find($event->id);
    expect($updated->title)->toBe('Observation modifiée')
        ->and($updated->color)->toBe('blue');
});

test('un admin peut supprimer un événement', function () {
    $event = CropCalendarEvent::create([
        'farm_id'    => $this->farm->id,
        'title'      => 'À supprimer',
        'event_type' => 'tache',
        'event_date' => '2026-07-20',
        'color'      => 'red',
    ]);

    $this->actingAs($this->adminUser)
        ->delete(route('crop-calendar-events.destroy', $event))
        ->assertRedirect(route('cultures.dashboard', ['tab' => 'calendar']));

    expect(CropCalendarEvent::withoutFarm()->find($event->id))->toBeNull();
});

test('un type d\'événement invalide est rejeté', function () {
    $this->actingAs($this->managerUser)
        ->post(route('crop-calendar-events.store'), [
            'title'      => 'Événement invalide',
            'event_type' => 'type_inexistant',
            'event_date' => '2026-07-10',
        ])
        ->assertSessionHasErrors('event_type');

    expect(CropCalendarEvent::withoutFarm()->where('title', 'Événement invalide')->exists())->toBeFalse();
});

test('le dashboard calendrier affiche les événements du manager', function () {
    CropCalendarEvent::create([
        'farm_id'    => $this->farm->id,
        'title'      => 'Rappel arrosage',
        'event_type' => 'rappel',
        'event_date' => now()->toDateString(),
        'color'      => 'blue',
    ]);

    $this->actingAs($this->managerUser)
        ->get(route('cultures.dashboard', ['tab' => 'calendar']))
        ->assertStatus(200)
        ->assertSee('Rappel arrosage');
});
