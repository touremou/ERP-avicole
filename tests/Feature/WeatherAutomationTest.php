<?php

use App\Models\Farm;
use App\Models\WeatherReading;
use App\Services\WeatherService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/**
 * Simule Open-Meteo (aucun appel réseau réel). Une seule closure répond aux
 * deux usages, discriminés par la requête : la PRÉVISION inclut le paramètre
 * `precipitation_probability_max`, le relevé du jour inclut l'humidité horaire.
 */
function fakeOpenMeteo(?array $dailyBlock = null, ?array $forecastDaily = null): void
{
    $dailyResponse = [
        'daily' => $dailyBlock ?? [
            'temperature_2m_max' => [31.2],
            'temperature_2m_min' => [24.8],
            'precipitation_sum'  => [12.5],
            'wind_speed_10m_max' => [18.0],
            'sunshine_duration'  => [25200], // 7 h
        ],
        'hourly' => [
            'relative_humidity_2m' => [80, 70, 90, 60], // moyenne = 75
        ],
    ];

    Http::fake([
        'geocoding-api.open-meteo.com/*' => Http::response([
            'results' => [['name' => 'Conakry', 'latitude' => 9.5379, 'longitude' => -13.6773]],
        ], 200),
        'api.open-meteo.com/*' => function ($request) use ($dailyResponse, $forecastDaily) {
            if ($forecastDaily !== null && str_contains($request->url(), 'precipitation_probability_max')) {
                return Http::response(['daily' => $forecastDaily], 200);
            }
            return Http::response($dailyResponse, 200);
        },
    ]);
}

beforeEach(function () {
    $this->setUpRbac();
});

test('dailyForFarm normalise la réponse Open-Meteo et met en cache les coordonnées', function () {
    fakeOpenMeteo();
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
    fakeOpenMeteo();
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
    fakeOpenMeteo();
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
    fakeOpenMeteo();
    Farm::where('code', 'FT-001')->update(['city' => 'Conakry']);

    $this->actingAs($this->operatorUser)
        ->post(route('weather.fetch'), ['reading_date' => '2026-06-23'])
        ->assertRedirect();

    expect(WeatherReading::whereDate('reading_date', '2026-06-23')->where('farm_id', $this->farm->id)->exists())->toBeTrue();
});

test('forecastAlerts détecte fortes pluies, canicule et vent fort annoncés', function () {
    fakeOpenMeteo(forecastDaily: [
        'time'                          => ['2026-06-24', '2026-06-25', '2026-06-26'],
        'temperature_2m_max'            => [30.0, 39.5, 31.0],   // J+2 canicule
        'temperature_2m_min'            => [23.0, 24.0, 23.5],
        'precipitation_sum'             => [60.0, 5.0, 8.0],     // J+1 fortes pluies
        'precipitation_probability_max' => [95, 30, 40],
        'wind_speed_10m_max'            => [12.0, 10.0, 50.0],   // J+3 vent fort
    ]);

    $farm = Farm::create(['name' => 'F', 'code' => 'F-FC', 'city' => 'Conakry', 'is_active' => true]);

    $alerts = app(WeatherService::class)->forecastAlerts($farm, 3);
    $titles = collect($alerts)->pluck('title');

    expect($titles)->toContain('Fortes pluies annoncées'); // 60 mm ≥ 50 → critique
    expect($titles)->toContain('Forte chaleur annoncée');  // 39.5°C ≥ 38
    expect($titles)->toContain('Vent fort annoncé');        // 50 km/h ≥ 45

    $rain = collect($alerts)->firstWhere('title', 'Fortes pluies annoncées');
    expect($rain['severity'])->toBe('critique');
    expect($rain['horizon'])->toBe(1);
});

test('forecastAlerts ignore une pluie faiblement probable', function () {
    fakeOpenMeteo(forecastDaily: [
        'time'                          => ['2026-06-24'],
        'temperature_2m_max'            => [30.0],
        'temperature_2m_min'            => [23.0],
        'precipitation_sum'             => [25.0], // ≥ 20 mm…
        'precipitation_probability_max' => [20],   // …mais proba 20 % < 50 → pas d'alerte
        'wind_speed_10m_max'            => [10.0],
    ]);

    $farm = Farm::create(['name' => 'F', 'code' => 'F-LP', 'city' => 'Conakry', 'is_active' => true]);

    expect(app(WeatherService::class)->forecastAlerts($farm, 1))->toBe([]);
});

test('weather:fetch ignore proprement une ferme sans localisation', function () {
    // FT-001 n'a pas de ville → géocodage impossible, aucun relevé, pas d'erreur.
    $this->artisan('weather:fetch', ['--date' => '2026-06-23'])->assertSuccessful();

    expect(WeatherReading::count())->toBe(0);
});
