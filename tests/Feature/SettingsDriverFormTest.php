<?php

use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('l\'écran Réglages › WhatsApp se rend avec la liaison de driver dynamique', function () {
    Setting::set('whatsapp.driver', 'ultramsg');

    $this->actingAs($this->adminUser)
        ->get(route('settings.index', ['group' => 'whatsapp']))
        ->assertOk()
        ->assertSee('x-data="{ driver:', false)        // conteneur Alpine piloté par le driver
        ->assertSee('x-model="driver"', false);         // le select de driver est lié
});

test('l\'écran Réglages › SMS se rend (champs passerelle conditionnés au driver)', function () {
    $this->actingAs($this->adminUser)
        ->get(route('settings.index', ['group' => 'sms']))
        ->assertOk()
        ->assertSee('x-show=', false);                  // les champs http sont conditionnels
});

test('un groupe sans driver (ex. général) se rend sans liaison dynamique', function () {
    $this->actingAs($this->adminUser)
        ->get(route('settings.index', ['group' => 'general']))
        ->assertOk();
});
