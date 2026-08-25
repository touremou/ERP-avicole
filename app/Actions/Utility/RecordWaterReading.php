<?php

namespace App\Actions\Utility;

use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;

/**
 * Relevé de consommation d'eau — SOURCE UNIQUE web + sync mobile.
 * Coût estimé depuis le prix du m³ quand il n'est pas saisi ; un relevé par
 * (citerne, jour) avec is_refill=false, pour ne jamais heurter les lignes de
 * ravitaillement ; le niveau de la citerne suit la VARIATION du relevé, si bien
 * qu'une correction de saisie ne retire pas la consommation une seconde fois.
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

            /*
             * CE QUE LE RELEVÉ DU JOUR AVAIT DÉJÀ RETIRÉ DE LA CITERNE.
             *
             * `updateOrCreate` réécrit la ligne ; le niveau, lui, était
             * décrémenté À NEUF à chaque passage (cf. WaterSource). On lit donc
             * la ligne AVANT de l'écrire, pour n'appliquer que la variation.
             */
            $precedent = WaterReading::where('water_source_id', $data['water_source_id'])
                ->whereDate('reading_date', $data['reading_date'])
                ->where('is_refill', false)
                ->first();

            $consommeAvant = (float) ($precedent->volume_consumed_liters ?? 0);
            $ajouteAvant   = (float) ($precedent->volume_added_liters ?? 0);

            if (empty($data['cost'])) {
                $pricePerM3 = (float) setting('energie.water_price_m3', 0);
                $data['cost'] = round(((float) $data['volume_consumed_liters'] / 1000) * $pricePerM3, 2);
            }

            /*
             * ON RÉÉCRIT LA LIGNE DÉJÀ TROUVÉE (cf. RecordEnergyReading pour le
             * détail) : `reading_date` est une colonne DATE que le cast écrit au
             * format datetime, si bien que l'égalité d'`updateOrCreate` retrouve
             * la ligne sous MySQL et pas sous SQLite. La recherche par
             * `whereDate` faite plus haut est vraie des deux côtés.
             */
            $data['is_refill'] = false;

            if ($precedent) {
                $precedent->update($data);
                $reading = $precedent->refresh();
            } else {
                $reading = WaterReading::create($data);
            }

            WaterSource::find($data['water_source_id'])?->applyReadingDelta(
                (float) $data['volume_consumed_liters'] - $consommeAvant,
                (float) $data['volume_added_liters'] - $ajouteAvant,
            );

            return $reading;
        });
    }
}
