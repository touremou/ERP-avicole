<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Building;
use App\Models\PlannedBatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PlanningService
{
    /**
     * Calendrier des bandes planifiées (période donnée).
     * FarmScope actif → filtre automatiquement par ferme.
     */
    public function getCalendar(Carbon $from, Carbon $to): Collection
    {
        return PlannedBatch::with(['building', 'provider'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('planned_arrival_date', [$from, $to])
                  ->orWhereBetween('planned_end_date', [$from, $to])
                  ->orWhereBetween('sanitary_void_end', [$from, $to]);
            })
            ->orderBy('planned_arrival_date')
            ->get();
    }

    /**
     * Vérifie la disponibilité d'un bâtiment pour une période.
     */
    public function checkBuildingAvailability(int $buildingId, Carbon $from, Carbon $to, ?int $excludePlanId = null): array
    {
        $conflicts = [];

        // Lots réels actifs
        $activeBatches = Batch::where('building_id', $buildingId)
            ->active()
            ->get();

        foreach ($activeBatches as $batch) {
            $conflicts[] = [
                'type'    => 'lot_actif',
                'message' => "Lot {$batch->code} actif ({$batch->current_quantity} sujets)",
                'from'    => $batch->arrival_date,
                'to'      => null,
            ];
        }

        // Bandes planifiées
        $query = PlannedBatch::where('building_id', $buildingId)
            ->whereNotIn('status', ['annule', 'termine'])
            ->where(function ($q) use ($from, $to) {
                $q->where('planned_arrival_date', '<=', $to)
                  ->where(function ($q2) use ($from) {
                      $q2->where('sanitary_void_end', '>=', $from)
                         ->orWhere('planned_end_date', '>=', $from);
                  });
            });

        if ($excludePlanId) $query->where('id', '!=', $excludePlanId);

        foreach ($query->get() as $plan) {
            $df = setting('general.date_format', 'd/m/Y');
            $endDate = $plan->sanitary_void_end ?? $plan->planned_end_date;
            $conflicts[] = [
                'type'    => 'bande_planifiee',
                'message' => "Bande planifiée du {$plan->planned_arrival_date->format($df)} au {$endDate->format($df)} ({$plan->batch_type})",
                'from'    => $plan->planned_arrival_date,
                'to'      => $endDate,
            ];
        }

        // Vide sanitaire en cours
        $building = Building::find($buildingId);
        if ($building && $building->disinfection_started_at) {
            $voidDays = (int) setting('planning.void_sanitaire_days', $building->min_sanitary_days ?? 21);
            $voidEnd = Carbon::parse($building->disinfection_started_at)->addDays($voidDays);
            if ($voidEnd->isAfter($from)) {
                $conflicts[] = [
                    'type'    => 'vide_sanitaire',
                    'message' => "Vide sanitaire en cours jusqu'au {$voidEnd->format(setting('general.date_format', 'd/m/Y'))}",
                    'from'    => $building->disinfection_started_at,
                    'to'      => $voidEnd,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Taux d'occupation des bâtiments — basé sur EFFECTIF RÉEL / CAPACITÉ.
     *
     * FarmScope actif → seuls les bâtiments de la ferme courante.
     *
     * Retourne pour chaque bâtiment :
     *   - current_birds  : effectif vivant actuel
     *   - capacity       : capacité du bâtiment
     *   - occupancy_rate : (current_birds / capacity) × 100
     *   - idle_days      : jours depuis la fin du dernier lot (si vide)
     *   - planned_days   : jours de planification future
     *   - active_batches : lots actifs dans ce bâtiment
     */
    public function getBuildingOccupancy(Carbon $from, Carbon $to): Collection
    {
        // FarmScope actif → filtre automatiquement par ferme
        /*
        buildings = Building::where('type', '!=', 'stockage')
            ->orderBy('name')
            ->get();
        */
        $buildings = Building::physical()->with(['batches' => function($q) {
            $q->active();
            }])->get();

        return $buildings->map(function ($building) {

            // ═══ 1. EFFECTIF RÉEL dans ce bâtiment ═══
            $activeBatches = Batch::where('building_id', $building->id)
                ->active()
                ->with('productionType:id,slug')
                ->get(['id', 'code', 'production_type_id', 'current_quantity', 'arrival_date']);

            $currentBirds = (int) $activeBatches->sum('current_quantity');
            $capacity = (int) ($building->capacity ?? 0);

            // ═══ 2. TAUX D'OCCUPATION RÉEL ═══
            $occupancyRate = $capacity > 0
                ? min(100, round(($currentBirds / $capacity) * 100, 1))
                : 0;

            // ═══ 3. JOURS VIDES (si bâtiment inoccupé) ═══
            $idleDays = 0;
            if ($currentBirds === 0) {
                $lastClosed = Batch::where('building_id', $building->id)
                    ->whereIn('status', [Batch::STATUS_TERMINE, Batch::STATUS_CLOTURE])
                    ->whereNotNull('closing_date')
                    ->latest('closing_date')
                    ->value('closing_date');

                $idleDays = $lastClosed
                    ? (int) Carbon::parse($lastClosed)->diffInDays(now())
                    : (int) ($building->created_at?->diffInDays(now()) ?? 0);
            }

            // ═══ 4. JOURS PLANIFIÉS (futures planifications) ═══
            $plannedDays = 0;
            $futurePlans = PlannedBatch::where('building_id', $building->id)
                ->whereNotIn('status', ['termine', 'annule'])
                ->where('planned_arrival_date', '>=', now())
                ->get();

            foreach ($futurePlans as $plan) {
                $start = Carbon::parse($plan->planned_arrival_date);
                $end = $plan->planned_end_date
                    ? Carbon::parse($plan->planned_end_date)
                    : $start->copy()->addDays((int) setting("elevage.cycle_{$plan->batch_type}", 42));
                $plannedDays += max(0, $start->diffInDays($end));
            }

            return [
                'building'       => $building,
                'current_birds'  => $currentBirds,
                'capacity'       => $capacity,
                'occupancy_rate' => $occupancyRate,
                'idle_days'      => $idleDays,
                'planned_days'   => $plannedDays,
                'active_batches' => $activeBatches,
                'is_empty'       => $currentBirds === 0,
                'is_full'        => $occupancyRate >= 90,
            ];
        });
    }

    /**
     * Alertes de planification.
     */
    public function getAlerts(): array
    {
        $alerts = [];
        $df = setting('general.date_format', 'd/m/Y');

        // Commandes en retard
        $overdue = PlannedBatch::overdue()->with('building')->get();
        foreach ($overdue as $plan) {
            $daysLate = (int) $plan->chick_order_deadline->diffInDays(now());
            $alerts[] = [
                'type'     => 'order_overdue',
                'severity' => $daysLate > 14 ? 'critique' : 'attention',
                'message'  => "Commande en retard de {$daysLate}j — {$plan->building->name} ({$plan->batch_type}, arrivée {$plan->planned_arrival_date->format($df)})",
                'icon'     => 'fa-egg',
            ];
        }

        // Arrivées dans 7 jours
        $arriving = PlannedBatch::where('status', 'commande')
            ->whereBetween('planned_arrival_date', [now(), now()->addDays(7)])
            ->with('building')
            ->get();

        foreach ($arriving as $plan) {
            $alerts[] = [
                'type'     => 'arrival_soon',
                'severity' => 'info',
                'message'  => "Arrivée dans {$plan->days_until_arrival}j — {$plan->building->name} ({$plan->planned_quantity} {$plan->batch_type})",
                'icon'     => 'fa-truck',
            ];
        }

        return $alerts;
    }
}
