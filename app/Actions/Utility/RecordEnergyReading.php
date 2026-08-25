<?php

namespace App\Actions\Utility;

use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\FuelPurchase;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;

/**
 * Relevé énergie (groupe électrogène / réseau) — SOURCE UNIQUE web + sync
 * mobile. Extrait du contrôleur pour que le relevé saisi au terrain applique
 * exactement les mêmes règles :
 *   - anti-corvée : carburant estimé (heures × conso horaire) et coût estimé
 *     (carburant × dernier prix au litre) quand ils ne sont pas saisis ;
 *   - un relevé par (source, jour) — updateOrCreate, donc rejouable ;
 *   - compteur d'heures incrémenté, niveau de carburant décrémenté ;
 *   - alerte gasoil au franchissement du seuil, bascule en maintenance.
 *
 * @return array{reading: EnergyReading, notes: array<int,string>}
 */
class RecordEnergyReading
{
    public function execute(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['user_id'] = $userId;
            $data['outage_hours'] = $data['outage_hours'] ?? 0;

            /** @var EnergySource $source */
            $source = EnergySource::findOrFail($data['energy_source_id']);
            $autoNotes = [];

            /*
             * CE QUE LE RELEVÉ DU JOUR COMPTAIT DÉJÀ.
             *
             * `updateOrCreate` réécrit la ligne du jour — mais les compteurs, eux,
             * étaient AJOUTÉS à chaque passage. Corriger un relevé (« 6 h, non,
             * 8 h ») incrémentait le compteur d'heures de 8 de plus, sur un
             * compteur qui portait déjà les 6 premières : 14 h pour une journée
             * qui en comptait 8. Idem pour le gasoil, retiré deux fois de la cuve.
             *
             * L'en-tête de cette action annonce pourtant « un relevé par (source,
             * jour) — updateOrCreate, donc rejouable ». La ligne l'était ; les
             * compteurs ne l'étaient pas.
             *
             * Ce que ça fausse n'est pas décoratif : `total_hours_run` commande
             * l'échéance de vidange (`hours_before_maintenance`), donc le passage
             * automatique du groupe en statut « maintenance » ; le niveau de cuve
             * commande l'alerte gasoil et l'autonomie. Deux corrections de saisie
             * suffisaient à déclencher une vidange qui n'était pas due et une
             * alerte carburant sur une cuve pleine.
             *
             * On applique donc le DELTA — exactement ce que fait déjà
             * `SyncManureCollection`, et ce que la synchro mobile suppose en
             * rejouant ses relevés hors-ligne.
             */
            $precedent = EnergyReading::where('energy_source_id', $data['energy_source_id'])
                ->whereDate('reading_date', $data['reading_date'])
                ->first();

            $heuresDejaComptees = (float) ($precedent->hours_run ?? 0);
            $gasoilDejaCompte   = (float) ($precedent->fuel_consumed_liters ?? 0);

            // Carburant estimé : l'opérateur ne renseigne idéalement que les
            // heures. Toute valeur saisie manuellement est respectée.
            if (empty($data['fuel_consumed_liters'])
                && $source->type === 'groupe'
                && (float) ($data['hours_run'] ?? 0) > 0) {
                $litersPerHour = $source->averageLitersPerHour();
                if ($litersPerHour) {
                    $data['fuel_consumed_liters'] = round((float) $data['hours_run'] * $litersPerHour, 1);
                    $autoNotes[] = 'gasoil estimé ' . number_format($data['fuel_consumed_liters'], 1, ',', ' ') . ' L';
                }
            }

            if (empty($data['cost']) && ! empty($data['fuel_consumed_liters'])) {
                // Prix réel le plus récent (dernier achat), repli sur le paramètre.
                $unitPrice = FuelPurchase::where('energy_source_id', $source->id)
                    ->latest('purchase_date')->value('unit_price')
                    ?? (float) setting('energie.fuel_price_liter', 12000);
                $data['cost'] = round((float) $data['fuel_consumed_liters'] * (float) $unitPrice);
                $autoNotes[] = 'coût estimé ' . number_format($data['cost'], 0, ',', ' ') . ' GNF';
            }

            /*
             * ON RÉÉCRIT LA LIGNE DÉJÀ TROUVÉE, plutôt que de la rechercher une
             * seconde fois par `updateOrCreate`.
             *
             * `reading_date` est une colonne DATE, mais le cast Eloquent l'écrit
             * au format datetime : la clause d'égalité d'`updateOrCreate`
             * comparait « 2026-08-25 » à « 2026-08-25 00:00:00 ». MySQL tranche
             * en faveur de la date et retrouve la ligne ; SQLite, qui n'a pas de
             * type date, compare deux chaînes différentes, ne trouve rien, et
             * tente un INSERT que la contrainte unique refuse.
             *
             * Le même geste échouait donc selon le moteur. On s'appuie sur la
             * recherche par `whereDate` faite plus haut, qui est vraie des deux
             * côtés — et qui nous sert déjà à mesurer le delta.
             */
            if ($precedent) {
                $precedent->update($data);
                $reading = $precedent->refresh();
            } else {
                $reading = EnergyReading::create($data);
            }

            $deltaHeures = (float) ($data['hours_run'] ?? 0) - $heuresDejaComptees;

            if (abs($deltaHeures) > 0.0001) {
                // Un compteur d'heures ne recule pas sous zéro, même si une
                // correction retire plus que ce que la journée avait ajouté.
                $source->update([
                    'total_hours_run' => max(0, (float) $source->total_hours_run + $deltaHeures),
                ]);
            }

            $wasFuelLow = $source->is_fuel_low;

            $deltaGasoil = (float) ($data['fuel_consumed_liters'] ?? 0) - $gasoilDejaCompte;

            if (abs($deltaGasoil) > 0.0001 && $source->current_fuel_level !== null) {
                // Un delta négatif REND le carburant : la correction à la baisse
                // doit remonter la cuve, sinon elle resterait basse à tort.
                $source->update([
                    'current_fuel_level' => max(0, (float) $source->current_fuel_level - $deltaGasoil),
                ]);
            }

            // Alerte gasoil : uniquement au franchissement du seuil.
            if (! $wasFuelLow && $source->refresh()->is_fuel_low) {
                app(NotificationHub::class)->alertFuelLow($source);
            }

            if ($source->needs_maintenance && $source->status === 'operationnel') {
                $source->update(['status' => 'maintenance']);
            }

            return ['reading' => $reading, 'notes' => $autoNotes];
        });
    }
}
