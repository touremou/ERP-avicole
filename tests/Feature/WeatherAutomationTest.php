<?php

use App\Models\Farm;
use App\Models\WeatherReading;
use App\Services\WeatherService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    // Réponses Open-Meteo simulées (aucun appel réseau réel en test).
    Http::fake([
        'geocoding-api.open-meteo.com/*' => Http::response([
            'results' => [[
                'name' => 'Conakry', 'latitude' => 9.5379, 'longitude' => -13.6773,
            ]],
        ], 200),
        'api.open-meteo.com/*' => Http::response([
            'daily' => [
                'temperature_2m_max'  => [31.2],
                'temperature_2m_min'  => [24.8],
                'precipitation_sum'   => [12.5],
                'wind_speed_10m_max'  => [18.0],
                'sunshine_duration'   => [25200], // 7 h en secondes
            ],
            'hourly' => [
                'relative_humidity_2m' => [80, 70, 90, 60], // moyenne = 75
            ],
        ], 200),
    ]);
});

test('dailyForFarm normalise la réponse Open-Meteo et met en cache les coordonnées', function () {
    $farm = Farm::create(['name' => 'Ferme Conakry', 'code' => 'F-CKY', 'city' => 'Conakry', 'is_active' => true]);

    $data = app(WeatherService::class)->dailyForFarm($farm, '2026-06-23');

    expect($data)->not->toBeNull();
    expect($data['temperature_max'])->toBe(31.2);
    expect($data['temperature_min'])->toBe(24.8);
    expect($data['humidity_pct'])->toBe(75.0);   // moyenne des humidités horaires
    expect($data['rainfall_mm'])->toBe(12.5);
    expect($data['wind_kmh'])->toBe(18.0);
    expect($data['sunshine_h'])->toBe(7.0);       // 25200 s → 7 h

    // Les coordonnées sont mémorisées dans les settings de la ferme.
    $geo = $farm->fresh()->getSetting('geo');
    expect($geo['lat'])->toBe(9.5379);
    expect($geo['query'])->toBe('Conakry');
});

test('coordinates ne re-géocode pas quand la ville est inchangée', function () {
    $farm = Farm::create(['name' => 'F', 'code' => 'F-1', 'city' => 'Conakry', 'is_active' => true]);
    $svc  = app(WeatherService::class);

    $svc->coordinates($farm);            // 1er appel → géocode
    $svc->coordinates($farm->fresh());   // 2e appel → cache settings

    // Un seul appel au service de géocodage malgré deux résolutions.
    Http::assertSentCount(1);
});

test('coordinates retourne null si la ferme n\'a ni ville ni région', function () {
    $farm = Farm::create(['name' => 'Sans lieu', 'code' => 'F-0', 'is_active' => true]);

    expect(app(WeatherService::class)->coordinates($farm))->toBeNull();
});

test('la commande weather:fetch crée puis actualise un relevé (idempotent)', function () {
    Farm::where('code', 'FT-001')->update(['city' => 'Conakry']);

    $this->artisan('weather:fetch', ['--date' => '2026-06-23'])->assertSuccessful();

    expect(WeatherReading::whereDate('reading_date', '2026-06-23')->count())->toBe(1);
    $reading = WeatherReading::whereDate('reading_date', '2026-06-23')->first();
    expect((float) $reading->temperature_max)->toBe(31.2);
    expect($reading->plot_id)->toBeNull();

    // Re-run : pas de doublon, le même relevé est mis à jour.
    $this->artisan('weather:fetch', ['--date' => '2026-06-23'])->assertSuccessful();
    expect(WeatherReading::whereDate('reading_date', '2026-06-23')->count())->toBe(1);
});

test('le bouton manuel (fetchNow) enregistre le relevé du jour', function () {
    Farm::where('code', 'FT-001')->update(['city' => 'Conakry']);

    $this->actingAs($this->operatorUser)
        ->post(route('weather.fetch'), ['reading_date' => '2026-06-23'])
        ->assertRedirect();

    expect(WeatherReading::whereDate('reading_date', '2026-06-23')->where('farm_id', $this->farm->id)->exists())->toBeTrue();
});

test('weather:fetch ignore proprement une ferme sans localisation', function () {
    // FT-001 n'a pas de ville → géocodage impossible, aucun relevé, pas d'erreur.
    $this->artisan('weather:fetch', ['--date' => '2026-06-23'])->assertSuccessful();

    expect(WeatherReading::count())->toBe(0);
});
