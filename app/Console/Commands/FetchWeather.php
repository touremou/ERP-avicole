<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Récupère automatiquement la météo du jour pour chaque ferme et insère/actualise
 * le relevé (weather_readings). Idempotent : un seul relevé par ferme et par jour.
 *
 * Usage :
 *   php artisan weather:fetch                  → toutes les fermes actives, aujourd'hui
 *   php artisan weather:fetch --date=2026-06-22
 *   php artisan weather:fetch --farm=1
 *
 * Cron (routes/console.php) : Schedule::command('weather:fetch')->dailyAt('05:15');
 */
class FetchWeather extends Command
{
    protected $signature = 'weather:fetch
        {--date= : Date du relevé (Y-m-d), défaut aujourd\'hui}
        {--farm= : ID ferme spécifique (sinon toutes les fermes actives)}';

    protected $description = 'Récupère la météo (Open-Meteo) et alimente les relevés agronomiques';

    public function handle(WeatherService $weather): int
    {
        if (! $weather->enabled()) {
            $this->warn('Service météo désactivé (services.weather.enabled = false).');
            return self::SUCCESS;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date'))->toDateString() : now()->toDateString();

        $farms = Farm::query()
            ->when($this->option('farm'), fn ($q) => $q->whereKey((int) $this->option('farm')), fn ($q) => $q->where('is_active', true))
            ->get();

        if ($farms->isEmpty()) {
            $this->warn('Aucune ferme à traiter.');
            return self::SUCCESS;
        }

        $ok = $skipped = 0;

        foreach ($farms as $farm) {
            $data = $weather->dailyForFarm($farm, $date);

            if ($data === null) {
                $skipped++;
                $this->line("• {$farm->name} : météo indisponible (localisation absente ou API muette).");
                continue;
            }

            $weather->storeReading($farm, $date, $data);

            $ok++;
            $this->info("• {$farm->name} : {$data['temperature_max']}°C / {$data['humidity_pct']}% / {$data['rainfall_mm']} mm");
        }

        $this->info("Météo {$date} : {$ok} relevé(s) à jour, {$skipped} ignoré(s).");

        return self::SUCCESS;
    }
}
