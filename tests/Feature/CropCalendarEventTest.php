<?php

use App\Models\CropCalendarEvent;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('un opérateur peut créer un événement calendaire', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('crop-calendar-events.store'), [
            'title'      => 'Traitement herbicide parcelle Nord',
            'event_type' => 'traitement',
            'event_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('cultures.dashboard', ['tab' => 'calendar']));

    $event = CropCalendarEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->title)->toBe('Traitement herbicide parcelle Nord')
        ->and($event->event_type)->toBe('traitement');
});

test("l'événement apparaît dans le dashboard calendrier", function () {
    CropCalendarEvent::create([
        'title'      => 'Observation maïs',
        'event_type' => 'observation',
        'event_date' => now()->toDateString(),
        'color'      => 'green',
    ]);

    $this->actingAs($this->operatorUser)
        ->get(route('cultures.dashboard', ['tab' => 'calendar']))
        ->assertOk()
        ->assertSee('Observation maïs');
});

test('un manager peut modifier un événement calendaire', function () {
    $event = CropCalendarEvent::create([
        'title'      => 'Irrigation initiale',
        'event_type' => 'tache',
        'event_date' => now()->toDateString(),
        'color'      => 'blue',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('crop-calendar-events.update', $event), [
            'title'      => 'Irrigation corrigée',
            'event_type' => 'tache',
            'event_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('cultures.dashboard', ['tab' => 'calendar']));

    expect($event->fresh()->title)->toBe('Irrigation corrigée');
});

test('un lecteur seul ne peut pas créer un événement calendaire', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('crop-calendar-events.store'), [
            'title'      => 'Tentative interdite',
            'event_type' => 'rappel',
            'event_date' => now()->toDateString(),
        ])
        ->assertSessionHas('error');

    expect(CropCalendarEvent::count())->toBe(0);
});
