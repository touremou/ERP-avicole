<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\TelemetryLog;
use App\Models\TelemetrySensor;
use Illuminate\Console\Command;

/**
 * Worker d'association télémétrie → lot (exigence 3 pré-MEP : zone tampon).
 *
 * Les relevés arrivent dans telemetry_logs SANS toucher aux tables métier ;
 * ce worker (planifié toutes les 5 minutes) associe chaque relevé « pending »
 * au LOT ACTIF du bâtiment du capteur au moment du relevé (lieu + heure).
 * Capteur inconnu du registre → « orphan » (visible, jamais perdu).
 */
class ProcessTelemetry extends Command
{
    protected $signature = 'telemetry:process {--chunk=500 : Relevés traités par passe}';

    protected $description = 'Associe les relevés IoT en tampon au lot actif du bâtiment (lieu + heure)';

    public function handle(): int
    {
        $sensors = TelemetrySensor::pluck('building_id', 'sensor_id');

        $linked = 0;
        $orphan = 0;

        TelemetryLog::pending()
            ->orderBy('id')
            ->limit((int) $this->option('chunk'))
            ->get()
            ->each(function (TelemetryLog $log) use ($sensors, &$linked, &$orphan) {
                $buildingId = $log->building_id ?? $sensors[$log->sensor_id] ?? null;

                if (! $buildingId) {
                    $log->update(['status' => TelemetryLog::STATUS_ORPHAN]);
                    $orphan++;

                    return;
                }

                // Lot actif du bâtiment à l'heure du relevé (arrivé avant, non clôturé avant).
                $batch = Batch::withoutGlobalScopes()
                    ->where('building_id', $buildingId)
                    ->where('status', 'Actif')
                    ->whereDate('arrival_date', '<=', $log->recorded_at->toDateString())
                    ->orderByDesc('arrival_date')
                    ->first();

                $log->update([
                    'building_id' => $buildingId,
                    'batch_id'    => $batch?->id,
                    'status'      => TelemetryLog::STATUS_LINKED,
                ]);
                $linked++;
            });

        $this->info("Télémétrie : {$linked} relevé(s) associé(s), {$orphan} orphelin(s).");

        return self::SUCCESS;
    }
}
