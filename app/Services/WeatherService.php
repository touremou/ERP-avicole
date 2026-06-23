<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\WeatherReading;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Récupération automatique de la météo via Open-Meteo (gratuit, sans clé).
 *
 * Deux usages :
 *  - relevés agronomiques quotidiens (weather_readings) → commande weather:fetch
 *    + bouton manuel dans le module Cultures ;
 *  - pré-remplissage de la météo du pointage volaille (temp/humidité → THI).
 *
 * Les coordonnées de la ferme sont déduites de sa ville/région par géocodage,
 * puis mémorisées dans farm.settings['geo'] pour éviter de re-géocoder.
 */
class WeatherService
{
    /** Le service est-il activé en configuration ? */
    public function enabled(): bool
    {
        return (bool) config('services.weather.enabled', true);
    }

    /**
     * Coordonnées GPS de la ferme. Lues depuis le cache (settings['geo']) si
     * présentes et cohérentes avec la ville actuelle ; sinon géocodées et
     * mémorisées. Retourne null si la ville/région est inexploitable.
     *
     * @return array{lat: float, lon: float, label: string}|null
     */
    public function coordinates(Farm $farm): ?array
    {
        $place = trim((string) ($farm->city ?: $farm->region ?: ''));
        if ($place === '') {
            return null;
        }

        // Cache : on revérifie que la ville n'a pas changé depuis le géocodage.
        $geo = $farm->getSetting('geo');
        if (is_array($geo)
            && isset($geo['lat'], $geo['lon'])
            && ($geo['query'] ?? null) === $place) {
            return [
                'lat'   => (float) $geo['lat'],
                'lon'   => (float) $geo['lon'],
                'label' => (string) ($geo['label'] ?? $place),
            ];
        }

        $coords = $this->geocode($place);
        if ($coords === null) {
            return null;
        }

        // Mémorisation dans les settings de la ferme (persistant, 1 appel/ville).
        $settings = $farm->settings ?? [];
        $settings['geo'] = [
            'query' => $place,
            'lat'   => $coords['lat'],
            'lon'   => $coords['lon'],
            'label' => $coords['label'],
            'at'    => now()->toIso8601String(),
        ];
        $farm->forceFill(['settings' => $settings])->save();

        return $coords;
    }

    /**
     * Géocodage d'un nom de lieu via l'API Open-Meteo (sans clé).
     *
     * @return array{lat: float, lon: float, label: string}|null
     */
    public function geocode(string $place): ?array
    {
        try {
            $resp = Http::timeout($this->timeout())
                ->get(config('services.weather.geocode_url'), [
                    'name'     => $place,
                    'count'    => 1,
                    'language' => 'fr',
                    'country'  => config('services.weather.country'),
                ]);

            $hit = $resp->ok() ? ($resp->json('results.0') ?? null) : null;
            if (! is_array($hit) || ! isset($hit['latitude'], $hit['longitude'])) {
                return null;
            }

            return [
                'lat'   => (float) $hit['latitude'],
                'lon'   => (float) $hit['longitude'],
                'label' => (string) ($hit['name'] ?? $place),
            ];
        } catch (\Throwable $e) {
            Log::warning('WeatherService: échec géocodage', ['place' => $place, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Relevé météo d'une ferme pour une date donnée (par défaut aujourd'hui),
     * normalisé aux colonnes de weather_readings. Retourne null si indisponible.
     *
     * @return array{temperature_min: ?float, temperature_max: ?float, humidity_pct: ?float, rainfall_mm: float, wind_kmh: ?float, sunshine_h: ?float}|null
     */
    public function dailyForFarm(Farm $farm, ?string $date = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $coords = $this->coordinates($farm);
        if ($coords === null) {
            return null;
        }

        $date = $date ?: now()->toDateString();

        return $this->fetchDaily($coords['lat'], $coords['lon'], $date);
    }

    /**
     * Appel Open-Meteo pour une journée : agrégats quotidiens + humidité
     * moyenne dérivée des valeurs horaires.
     *
     * @return array{temperature_min: ?float, temperature_max: ?float, humidity_pct: ?float, rainfall_mm: float, wind_kmh: ?float, sunshine_h: ?float}|null
     */
    public function fetchDaily(float $lat, float $lon, string $date): ?array
    {
        try {
            $resp = Http::timeout($this->timeout())
                ->get(config('services.weather.forecast_url'), [
                    'latitude'   => $lat,
                    'longitude'  => $lon,
                    'daily'      => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,sunshine_duration',
                    'hourly'     => 'relative_humidity_2m',
                    'start_date' => $date,
                    'end_date'   => $date,
                    'timezone'   => 'auto',
                ]);

            if (! $resp->ok()) {
                return null;
            }

            $tMax     = $resp->json('daily.temperature_2m_max.0');
            $tMin     = $resp->json('daily.temperature_2m_min.0');
            $rain     = $resp->json('daily.precipitation_sum.0');
            $wind     = $resp->json('daily.wind_speed_10m_max.0');
            $sunSecs  = $resp->json('daily.sunshine_duration.0');
            $humidity = $this->meanOf($resp->json('hourly.relative_humidity_2m'));

            // Aucune donnée exploitable → on ne crée pas de relevé vide.
            if ($tMax === null && $tMin === null && $rain === null && $humidity === null) {
                return null;
            }

            return [
                'temperature_min' => $tMin !== null ? round((float) $tMin, 1) : null,
                'temperature_max' => $tMax !== null ? round((float) $tMax, 1) : null,
                'humidity_pct'    => $humidity !== null ? round($humidity, 1) : null,
                'rainfall_mm'     => $rain !== null ? round((float) $rain, 1) : 0.0,
                'wind_kmh'        => $wind !== null ? round((float) $wind, 1) : null,
                'sunshine_h'      => $sunSecs !== null ? round(((float) $sunSecs) / 3600, 1) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('WeatherService: échec récupération météo', [
                'lat' => $lat, 'lon' => $lon, 'date' => $date, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Météo du jour d'une ferme adaptée au pré-remplissage du pointage volaille
     * (temp min/max + humidité). Mise en cache courte pour ne pas appeler l'API
     * à chaque ouverture du formulaire. Retourne null si indisponible.
     *
     * @return array{temp_min: ?float, temp_max: ?float, humidity: ?float, label: string}|null
     */
    public function currentForFarm(Farm $farm): ?array
    {
        if (! $this->enabled() || ! $farm->id) {
            return null;
        }

        return cache()->remember("weather.current.farm.{$farm->id}", now()->addHour(), function () use ($farm) {
            $coords = $this->coordinates($farm);
            if ($coords === null) {
                return null;
            }

            $daily = $this->fetchDaily($coords['lat'], $coords['lon'], now()->toDateString());
            if ($daily === null) {
                return null;
            }

            return [
                'temp_min' => $daily['temperature_min'],
                'temp_max' => $daily['temperature_max'],
                'humidity' => $daily['humidity_pct'],
                'label'    => $coords['label'],
            ];
        });
    }

    /**
     * Prévisions journalières d'une ferme à partir de J+1 (mis en cache 3 h).
     *
     * @return array<int, array{date: string, horizon: int, t_min: ?float, t_max: ?float, rain_mm: ?float, rain_prob: ?int, wind_kmh: ?float}>
     */
    public function forecast(Farm $farm, int $days = 3): array
    {
        if (! $this->enabled() || ! $farm->id) {
            return [];
        }

        $days = max(1, min(7, $days));

        return cache()->remember("weather.forecast.farm.{$farm->id}.{$days}", now()->addHours(3), function () use ($farm, $days) {
            $coords = $this->coordinates($farm);
            if ($coords === null) {
                return [];
            }

            return $this->fetchForecast($coords['lat'], $coords['lon'], $days);
        });
    }

    /**
     * Appel Open-Meteo pour les prévisions J+1 à J+N.
     *
     * @return array<int, array{date: string, horizon: int, t_min: ?float, t_max: ?float, rain_mm: ?float, rain_prob: ?int, wind_kmh: ?float}>
     */
    public function fetchForecast(float $lat, float $lon, int $days): array
    {
        $start = now()->addDay()->toDateString();
        $end   = now()->addDays($days)->toDateString();

        try {
            $resp = Http::timeout($this->timeout())
                ->get(config('services.weather.forecast_url'), [
                    'latitude'   => $lat,
                    'longitude'  => $lon,
                    'daily'      => 'temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max',
                    'start_date' => $start,
                    'end_date'   => $end,
                    'timezone'   => 'auto',
                ]);

            if (! $resp->ok()) {
                return [];
            }

            $dates = $resp->json('daily.time') ?? [];
            $out   = [];
            foreach ($dates as $i => $date) {
                $out[] = [
                    'date'      => $date,
                    'horizon'   => $i + 1, // J+1, J+2, …
                    't_min'     => $this->numAt($resp->json('daily.temperature_2m_min'), $i),
                    't_max'     => $this->numAt($resp->json('daily.temperature_2m_max'), $i),
                    'rain_mm'   => $this->numAt($resp->json('daily.precipitation_sum'), $i),
                    'rain_prob' => ($p = $this->numAt($resp->json('daily.precipitation_probability_max'), $i)) !== null ? (int) $p : null,
                    'wind_kmh'  => $this->numAt($resp->json('daily.wind_speed_10m_max'), $i),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('WeatherService: échec prévisions', [
                'lat' => $lat, 'lon' => $lon, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Alertes prédictives dérivées des prévisions (fortes pluies, canicule,
     * vent fort) sur les `days` prochains jours. Même structure que les alertes
     * CropAdvisorService (type/severity/icon/title/message) pour réutiliser
     * l'affichage et la diffusion existants.
     *
     * @return array<int, array{type: string, severity: string, icon: string, title: string, message: string, date: string, horizon: int}>
     */
    public function forecastAlerts(Farm $farm, int $days = 2): array
    {
        $alerts = [];

        foreach ($this->forecast($farm, $days) as $day) {
            $when = $this->horizonLabel($day['horizon'], $day['date']);

            // Fortes pluies annoncées : ≥ 50 mm = critique, ≥ 20 mm = attention.
            $rain = $day['rain_mm'];
            $prob = $day['rain_prob'];
            if ($rain !== null && $rain >= 20 && ($prob === null || $prob >= 50)) {
                $critique = $rain >= 50;
                $probTxt  = $prob !== null ? " (proba {$prob}%)" : '';
                $alerts[] = [
                    'type'     => 'weather_forecast',
                    'severity' => $critique ? 'critique' : 'attention',
                    'icon'     => 'fa-cloud-showers-heavy',
                    'title'    => $critique ? 'Fortes pluies annoncées' : 'Pluies annoncées',
                    'message'  => "{$when} : {$rain} mm prévus{$probTxt}. "
                        . ($critique
                            ? 'Risque de lessivage et d’inondation : différer fertilisation/traitement, sécuriser les intrants et le drainage.'
                            : 'Différer la fertilisation et les traitements foliaires ; vérifier le drainage.'),
                    'date'     => $day['date'],
                    'horizon'  => $day['horizon'],
                ];
            }

            // Canicule annoncée (T° max ≥ 38 °C).
            if ($day['t_max'] !== null && $day['t_max'] >= 38) {
                $alerts[] = [
                    'type'     => 'weather_forecast',
                    'severity' => 'attention',
                    'icon'     => 'fa-temperature-high',
                    'title'    => 'Forte chaleur annoncée',
                    'message'  => "{$when} : {$day['t_max']}°C prévus. Renforcer abreuvement/ventilation des animaux et l’irrigation des cultures sensibles.",
                    'date'     => $day['date'],
                    'horizon'  => $day['horizon'],
                ];
            }

            // Vent fort (rafales/vent max ≥ 45 km/h).
            if ($day['wind_kmh'] !== null && $day['wind_kmh'] >= 45) {
                $alerts[] = [
                    'type'     => 'weather_forecast',
                    'severity' => 'attention',
                    'icon'     => 'fa-wind',
                    'title'    => 'Vent fort annoncé',
                    'message'  => "{$when} : vent jusqu’à {$day['wind_kmh']} km/h. Sécuriser bâches, filets et jeunes plants ; reporter les pulvérisations.",
                    'date'     => $day['date'],
                    'horizon'  => $day['horizon'],
                ];
            }
        }

        return $alerts;
    }

    /** Libellé d'horizon lisible : « demain », « après-demain », « dans N j ». */
    private function horizonLabel(int $horizon, string $date): string
    {
        return match ($horizon) {
            1       => 'Demain',
            2       => 'Après-demain',
            default => "Dans {$horizon} j",
        };
    }

    /** Valeur numérique d'un tableau indexé, ou null. */
    private function numAt($array, int $i): ?float
    {
        if (! is_array($array) || ! array_key_exists($i, $array) || ! is_numeric($array[$i])) {
            return null;
        }

        return (float) $array[$i];
    }

    /**
     * Insère ou met à jour LE relevé du jour d'une ferme (plot null), de façon
     * idempotente. On recherche via whereDate (et non une égalité littérale) :
     * la colonne date stocke un datetime sous SQLite, ce qui ferait échouer
     * updateOrCreate et créerait des doublons. withoutFarm() neutralise le
     * scope ferme pour cibler explicitement la ferme passée.
     */
    public function storeReading(Farm $farm, string $date, array $data): WeatherReading
    {
        $payload = array_merge($data, ['notes' => 'Relevé automatique (Open-Meteo).']);

        $reading = WeatherReading::withoutFarm()
            ->where('farm_id', $farm->id)
            ->whereNull('plot_id')
            ->whereDate('reading_date', $date)
            ->first();

        if ($reading) {
            $reading->update($payload);
            return $reading;
        }

        return WeatherReading::create(array_merge($payload, [
            'farm_id'      => $farm->id,
            'plot_id'      => null,
            'reading_date' => $date,
        ]));
    }

    /** Moyenne d'un tableau de valeurs numériques (ignore null/non numériques). */
    private function meanOf($values): ?float
    {
        if (! is_array($values)) {
            return null;
        }

        $nums = array_values(array_filter($values, fn ($v) => is_numeric($v)));
        if ($nums === []) {
            return null;
        }

        return array_sum(array_map('floatval', $nums)) / count($nums);
    }

    private function timeout(): int
    {
        return (int) config('services.weather.timeout', 12);
    }
}
