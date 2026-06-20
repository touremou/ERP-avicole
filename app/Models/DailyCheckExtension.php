<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyCheckExtension extends Model
{
    protected $fillable = [
        'daily_check_id',
        // Ruminants
        'qty_born','qty_weaned','qty_sold_live','milk_liters','milk_fat_pct',
        // Aquaculture
        'water_temp','water_ph','water_o2_ppm','water_ammonia_ppm','biomass_kg','survival_rate',
        // Libre
        'extra_data',
    ];

    protected $casts = [
        'milk_liters'       => 'decimal:2',
        'milk_fat_pct'      => 'decimal:2',
        'water_temp'        => 'decimal:1',
        'water_ph'          => 'decimal:1',
        'water_o2_ppm'      => 'decimal:2',
        'water_ammonia_ppm' => 'decimal:3',
        'biomass_kg'        => 'decimal:2',
        'survival_rate'     => 'decimal:2',
        'extra_data'        => 'array',
    ];

    public function dailyCheck(): BelongsTo
    {
        return $this->belongsTo(DailyCheck::class);
    }

    /**
     * Vérifie les alertes qualité eau (pisciculture).
     *
     * Les seuils sont pilotés par Paramètres > Pisciculture, ce qui permet
     * d'adapter les niveaux d'alerte aux espèces et conditions locales.
     * Les seuils "critique" sont dérivés des seuils configurés (qui
     * représentent le niveau "vigilance"), avec une marge de sécurité fixe.
     */
    public function getWaterAlerts(): array
    {
        $alerts = [];

        $phMin = (float) Setting::get('pisciculture.ph_min', 6.5);
        $phMax = (float) Setting::get('pisciculture.ph_max', 8.5);
        $o2Alert = (float) Setting::get('pisciculture.o2_alert', 4);
        $nh3Alert = (float) Setting::get('pisciculture.ammonia_alert', 1);
        $tempMin = (float) Setting::get('pisciculture.temp_min', 25);
        $tempMax = (float) Setting::get('pisciculture.temp_max', 32);

        if ($this->water_ph !== null) {
            $ph = (float) $this->water_ph;
            if ($ph < $phMin - 0.5 || $ph > $phMax + 0.5) {
                $alerts[] = ['level' => 'critical', 'metric' => 'pH', 'value' => $ph, 'message' => "pH {$ph} critique (hors {$phMin}–{$phMax})"];
            } elseif ($ph < $phMin || $ph > $phMax) {
                $alerts[] = ['level' => 'warning', 'metric' => 'pH', 'value' => $ph, 'message' => "pH {$ph} hors plage optimale ({$phMin}–{$phMax})"];
            }
        }

        if ($this->water_o2_ppm !== null) {
            $o2 = (float) $this->water_o2_ppm;
            if ($o2 < $o2Alert) {
                $alerts[] = ['level' => 'critical', 'metric' => 'O₂', 'value' => $o2, 'message' => "O₂ {$o2} mg/L — risque d'asphyxie (< {$o2Alert} mg/L)"];
            } elseif ($o2 < $o2Alert + 1) {
                $alerts[] = ['level' => 'warning', 'metric' => 'O₂', 'value' => $o2, 'message' => "O₂ {$o2} mg/L — zone de vigilance (< " . ($o2Alert + 1) . " mg/L)"];
            }
        }

        if ($this->water_ammonia_ppm !== null) {
            $nh3 = (float) $this->water_ammonia_ppm;
            if ($nh3 > $nh3Alert) {
                $alerts[] = ['level' => 'critical', 'metric' => 'NH₃', 'value' => $nh3, 'message' => "Ammoniaque {$nh3} mg/L — risque d'intoxication (> {$nh3Alert} mg/L)"];
            } elseif ($nh3 > $nh3Alert / 2) {
                $alerts[] = ['level' => 'warning', 'metric' => 'NH₃', 'value' => $nh3, 'message' => "Ammoniaque {$nh3} mg/L — vigilance (> " . ($nh3Alert / 2) . " mg/L)"];
            }
        }

        if ($this->water_temp !== null) {
            $temp = (float) $this->water_temp;
            if ($temp < $tempMin || $temp > $tempMax) {
                $alerts[] = ['level' => 'warning', 'metric' => 'Température', 'value' => $temp, 'message' => "Température {$temp}°C hors plage optimale ({$tempMin}–{$tempMax}°C)"];
            }
        }

        return $alerts;
    }
}
