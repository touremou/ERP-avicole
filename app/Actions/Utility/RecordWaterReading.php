<?php

namespace App\Actions\Utility;

use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;

/**
 * Relevé de consommation d'eau — SOURCE UNIQUE web + sync mobile.
 * Coût estimé depuis le prix du m³ quand il n'est pas saisi ; un relevé par
 * (citerne, jour) avec is_refill=false, pour ne jamais heurter les lignes de
 * ravitaillement ; le niveau de la citerne est recalculé après écriture.
 */
class RecordWaterReading
{
    public function execute(array $data, ?int $userId = null): WaterReading
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['user_id'] = $userId;
            $data['volume_added_liters'] = $data['volume_added_liters'] ?? 0;

            if (empty($data['cost'])) {
                $pricePerM3 = (float) setting('energie.water_price_m3', 0);
                $data['cost'] = round(((float) $data['volume_consumed_liters'] / 1000) * $pricePerM3, 2);
            }

            $reading = WaterReading::updateOrCreate(
                [
                    'water_source_id' => $data['water_source_id'],
                    'reading_date'    => $data['reading_date'],
                    'is_refill'       => false,
                ],
                array_merge($data, ['is_refill' => false]),
            );

            WaterSource::find($data['water_source_id'])?->refreshLevel();

            return $reading;
        });
    }
}
