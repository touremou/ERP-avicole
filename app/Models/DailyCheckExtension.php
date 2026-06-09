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

    /** Vérifie les alertes qualité eau (pisciculture) */
    public function getWaterAlerts(): array
    {
        $alerts = [];

        if ($this->water_ph !== null) {
            $ph = (float) $this->water_ph;
            if ($ph < 6.0 || $ph > 9.0) {
                $alerts[] = ['level' => 'critical', 'metric' => 'pH', 'value' => $ph, 'message' => "pH {$ph} critique (hors 6.0–9.0)"];
            } elseif ($ph < 6.5 || $ph > 8.5) {
                $alerts[] = ['level' => 'warning', 'metric' => 'pH', 'value' => $ph, 'message' => "pH {$ph} hors plage optimale (6.5–8.5)"];
            }
        }

        if ($this->water_o2_ppm !== null) {
            $o2 = (float) $this->water_o2_ppm;
            if ($o2 < 3.0) {
                $alerts[] = ['level' => 'critical', 'metric' => 'O₂', 'value' => $o2, 'message' => "O₂ {$o2} ppm — risque d'asphyxie (< 3 ppm)"];
            } elseif ($o2 < 5.0) {
                $alerts[] = ['level' => 'warning', 'metric' => 'O₂', 'value' => $o2, 'message' => "O₂ {$o2} ppm — zone de vigilance (< 5 ppm)"];
            }
        }

        if ($this->water_ammonia_ppm !== null) {
            $nh3 = (float) $this->water_ammonia_ppm;
            if ($nh3 > 1.0) {
                $alerts[] = ['level' => 'critical', 'metric' => 'NH₃', 'value' => $nh3, 'message' => "Ammoniaque {$nh3} ppm — risque d'intoxication (> 1 ppm)"];
            } elseif ($nh3 > 0.5) {
                $alerts[] = ['level' => 'warning', 'metric' => 'NH₃', 'value' => $nh3, 'message' => "Ammoniaque {$nh3} ppm — vigilance (> 0.5 ppm)"];
            }
        }

        return $alerts;
    }
}
