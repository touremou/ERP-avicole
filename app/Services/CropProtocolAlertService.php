<?php

namespace App\Services;

use App\Models\CropCalendarEvent;
use App\Models\CropCycle;
use App\Models\CropProtocolItem;
use Carbon\Carbon;

/**
 * CropProtocolAlertService — moteur de rappels d'itinéraire technique.
 *
 * Pendant végétal de SanitaryAlertService : pour un cycle doté d'un protocole et
 * d'une date de semis, projette chaque étape (DAP → date cible) et en calcule le
 * statut, en croisant avec les intrants (crop_inputs), récoltes et événements
 * calendaires déjà saisis sur le cycle. Service en lecture seule, sans effet de
 * bord : il ne produit que des tableaux consommés par les vues et notifications.
 */
class CropProtocolAlertService
{
    /** Normalise une chaîne pour comparaison « contient » insensible à la casse/espaces. */
    private function sanitize(?string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/', '', $text ?? '')));
    }

    /**
     * Calendrier projeté du cycle : une entrée par étape du protocole avec
     * date cible, statut (done|overdue|due|upcoming) et retard en jours.
     *
     * @return array<int, array{item: CropProtocolItem, target_date: Carbon, status: string, delay_days: int}>
     */
    public function getCycleSchedule(CropCycle $cycle): array
    {
        if (! $cycle->relationLoaded('protocol')) {
            $cycle->loadMissing('protocol.items');
        }

        $protocol = $cycle->protocol;
        if (! $protocol || ! $cycle->planting_date) {
            return [];
        }

        $planting = Carbon::parse($cycle->planting_date)->startOfDay();
        $today = now()->startOfDay();

        // Pré-chargement des intrants & événements pour la détection « done ».
        $inputs = $cycle->relationLoaded('inputs')
            ? $cycle->inputs
            : $cycle->inputs()->get();
        $events = CropCalendarEvent::where('crop_cycle_id', $cycle->id)->get();
        $hasHarvest = $cycle->relationLoaded('harvests')
            ? $cycle->harvests->isNotEmpty()
            : $cycle->harvests()->exists();

        // Index normalisé des intrants (nom + type) saisis à/après le semis.
        $doneInputs = $inputs->filter(function ($input) use ($planting) {
            return ! $input->input_date
                || Carbon::parse($input->input_date)->startOfDay()->gte($planting);
        });
        $doneInputNames = $doneInputs->map(fn ($i) => $this->sanitize($i->name))->filter()->all();
        $doneEventTitles = $events->map(fn ($e) => $this->sanitize($e->title))->filter()->all();

        $schedule = [];

        foreach ($protocol->items as $item) {
            $target = $planting->copy()->addDays((int) $item->day_number);
            $done = $this->isItemDone($item, $doneInputNames, $doneEventTitles, $hasHarvest);

            if ($done) {
                $status = 'done';
            } elseif ($target->lt($today)) {
                $status = 'overdue';
            } elseif ($target->lte($today)) {
                $status = 'due';
            } else {
                $status = 'upcoming';
            }

            $schedule[] = [
                'item'        => $item,
                'target_date' => $target,
                'status'      => $status,
                'delay_days'  => $status === 'overdue' ? (int) $target->diffInDays($today) : 0,
            ];
        }

        return $schedule;
    }

    /**
     * Détecte si une étape a été réalisée d'après les saisies du cycle.
     *
     * - recolte : faite si le cycle a au moins une récolte ;
     * - étapes avec produit (fertilisation/traitement/semis…) : faites si un
     *   intrant correspond par nom (action_name OU product_suggested) ;
     * - observation/sarclage/irrigation (sans produit) : ce sont des rappels —
     *   considérées faites seulement si un intrant OU un événement calendaire les
     *   référence explicitement, sinon elles restent due/upcoming.
     */
    private function isItemDone(CropProtocolItem $item, array $doneInputNames, array $doneEventTitles, bool $hasHarvest): bool
    {
        if ($item->type === 'recolte') {
            return $hasHarvest;
        }

        $needles = array_filter([
            $this->sanitize($item->action_name),
            $this->sanitize($item->product_suggested),
        ]);

        $haystack = array_merge($doneInputNames, $doneEventTitles);

        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            foreach ($haystack as $recorded) {
                if ($recorded !== '' && (str_contains($recorded, $needle) || str_contains($needle, $recorded))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Alertes du cycle (étapes en retard / dues du jour), au format « advisory »
     * de CropAdvisorService pour fusion dans la même section de conseils.
     *
     * @return array<int, array{type: string, severity: string, icon: string, title: string, message: string}>
     */
    public function getCycleAlerts(CropCycle $cycle): array
    {
        $alerts = [];

        foreach ($this->getCycleSchedule($cycle) as $entry) {
            if (! in_array($entry['status'], ['overdue', 'due'], true)) {
                continue;
            }

            $item = $entry['item'];
            $isOverdue = $entry['status'] === 'overdue';

            $detail = trim(implode(' ', array_filter([
                $item->product_suggested,
                $item->dose ? "({$item->dose})" : null,
            ])));

            $stage = $item->stage ? "{$item->stage} — " : '';
            $message = "{$stage}prévu J+{$item->day_number} ({$entry['target_date']->format('d/m/Y')}).";
            if ($detail !== '') {
                $message .= " {$detail}";
            }

            $alerts[] = [
                'type'     => 'protocol',
                'severity' => $isOverdue ? 'critique' : 'attention',
                'icon'     => $item->type_icon,
                'title'    => "À faire : {$item->action_name}",
                'message'  => $message,
            ];
        }

        return $alerts;
    }

    /**
     * Alertes à l'échelle de la ferme : itère les cycles en cours dotés d'un
     * protocole et collecte leurs étapes en retard / dues, chacune taguée du
     * cycle concerné (consommé par les notifications quotidiennes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveAlerts(int $cap = 100): array
    {
        $alerts = [];

        $cycles = CropCycle::query()
            ->inProgress()
            ->whereNotNull('crop_protocol_id')
            ->with(['protocol.items', 'inputs', 'harvests'])
            ->orderBy('planting_date')
            ->get();

        foreach ($cycles as $cycle) {
            foreach ($this->getCycleAlerts($cycle) as $alert) {
                $alert['cycle_id']   = $cycle->id;
                $alert['crop_name']  = $cycle->crop_name;
                $alerts[] = $alert;

                if (count($alerts) >= $cap) {
                    return $alerts;
                }
            }
        }

        return $alerts;
    }
}
