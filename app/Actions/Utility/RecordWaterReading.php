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
            /*
             * SÉRIALISATION SUR LA CITERNE.
             *
             * L'unicité « un relevé par (citerne, jour) » était tenue par une
             * CONTRAINTE DE BASE — `water_reading_unique_per_day`. La migration
             * du 18/07/2026 l'a levée, à raison : les ravitaillements sont des
             * événements et plusieurs par jour doivent coexister.
             *
             * Mais son en-tête annonce que « le relevé garde son unicité via
             * updateOrCreate sur (source, date, is_refill=false) » — or un
             * `updateOrCreate` est un lire-puis-écrire, et il ne vaut pas une
             * contrainte : deux relevés simultanés n'en trouvent aucun et en
             * créent DEUX, là où la base en aurait refusé un. La garantie a été
             * déplacée du schéma vers l'application, et présentée comme
             * équivalente alors qu'elle est plus faible.
             *
             * Le verrou pris ici sur la citerne rétablit ce que la contrainte
             * donnait. C'est déjà ce que fait le ravitaillement des deux côtés
             * (`waterRefillCreate` côté synchro, et le web depuis peu) : le
             * relevé de consommation était le seul geste sur cette table à ne
             * rien sérialiser.
             *
             * Le `refreshLevel()` en fin de méthode le réclamait de toute façon :
             * il recalcule le niveau depuis TOUTES les lignes du jour.
             */
            WaterSource::lockForUpdate()->find($data['water_source_id']);

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
