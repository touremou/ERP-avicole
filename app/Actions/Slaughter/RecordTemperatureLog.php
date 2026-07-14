<?php

namespace App\Actions\Slaughter;

use App\Models\TemperatureLog;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Relevé manuel de température (E4) — conformité calculée serveur selon
 * les seuils paramétrables, alerte IMMÉDIATE si hors seuil (une chambre
 * froide qui dérive = le stock d'une semaine en jeu).
 */
class RecordTemperatureLog
{
    public function execute(array $data): TemperatureLog
    {
        return DB::transaction(function () use ($data) {
            $conforme = TemperatureLog::isCompliant($data['point'], (float) $data['temperature']);

            $log = TemperatureLog::create(array_merge($data, [
                'conforme'  => $conforme,
                'synced_at' => now(),
            ]));

            if (! $conforme) {
                $this->alert($log);
            }

            return $log;
        });
    }

    private function alert(TemperatureLog $log): void
    {
        try {
            $bounds = TemperatureLog::boundsFor($log->point);
            $range = ($bounds['min'] !== null ? "min {$bounds['min']}°C " : '')
                   . ($bounds['max'] !== null ? "max {$bounds['max']}°C" : '');
            $label = TemperatureLog::POINT_LABELS[$log->point] ?? $log->point;

            app(NotificationHub::class)->alertHaccp(
                "🌡️ TEMPÉRATURE HORS SEUIL — {$label}"
                . ($log->equipment_ref ? " ({$log->equipment_ref})" : '')
                . " : {$log->temperature}°C (seuil {$range}). "
                . 'Action : ' . ($log->corrective_action ?: 'à définir'),
                'Température hors seuil',
                'critique',
            );
        } catch (\Throwable $e) {
            Log::warning("Température {$log->id}: alerte non envoyée : {$e->getMessage()}");
        }
    }
}
