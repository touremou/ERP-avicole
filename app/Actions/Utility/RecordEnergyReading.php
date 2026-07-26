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

            $reading = EnergyReading::updateOrCreate(
                ['energy_source_id' => $data['energy_source_id'], 'reading_date' => $data['reading_date']],
                $data,
            );

            $source->increment('total_hours_run', (float) ($data['hours_run'] ?? 0));

            $wasFuelLow = $source->is_fuel_low;

            if (! empty($data['fuel_consumed_liters']) && $source->current_fuel_level !== null) {
                $source->decrement('current_fuel_level', (float) $data['fuel_consumed_liters']);
                if ($source->current_fuel_level < 0) {
                    $source->update(['current_fuel_level' => 0]);
                }
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
