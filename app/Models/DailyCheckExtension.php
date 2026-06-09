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
            $phMin = (float) setting('pisciculture.ph_min', 6.5);
            $phMax = (float) setting('pisciculture.ph_max', 8.5);
            if ($this->water_ph < $phMin || $this->water_ph > $phMax) {
                $alerts[] = "pH hors norme ({$this->water_ph})";
            }
        }
        if ($this->water_o2_ppm !== null && $this->water_o2_ppm < (float) setting('pisciculture.o2_alert', 4)) {
            $alerts[] = "O₂ critique ({$this->water_o2_ppm} mg/L)";
        }
        if ($this->water_ammonia_ppm !== null && $this->water_ammonia_ppm > (float) setting('pisciculture.ammonia_alert', 0.02)) {
            $alerts[] = "NH₃ élevé ({$this->water_ammonia_ppm} mg/L)";
        }
        return $alerts;
    }
}
