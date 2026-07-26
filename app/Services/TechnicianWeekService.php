<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\Employee;
use App\Models\HealthIncident;
use App\Models\TaskAssignment;
use Carbon\Carbon;

/**
 * TechnicianWeekService — la semaine d'un technicien, en six indicateurs.
 *
 * Raison d'être (management par exception, sur données objectives) : le suivi à
 * distance ne repose pas sur « a-t-il bien travaillé ? » — invérifiable — mais
 * sur des mesures que le système produit seul. Le technicien s'auto-suit le
 * lundi, le promoteur ne regarde que les écarts.
 *
 * Source UNIQUE des six indicateurs, partagée par la page web, l'export et
 * l'écran mobile : trois consommateurs qui doivent afficher le MÊME chiffre.
 * Un tableau de bord qui contredit un rapport n'est plus consulté.
 *
 * Deux partis pris qui décident de la crédibilité de la mesure :
 *
 *  1. LA PONCTUALITÉ SE MESURE SUR LA DATE DÉCLARÉE DE L'ACTE (completed_at,
 *     désormais alimenté par l'horodatage du terrain), jamais sur l'arrivée au
 *     serveur. Sur un site sans couverture réseau, mesurer l'arrivée
 *     sanctionnerait le réseau et non un manquement.
 *  2. AUCUN INDICATEUR N'EST INVENTÉ QUAND LA DONNÉE MANQUE. Sans norme de
 *     souche, l'écart aliment vaut null et s'affiche « non mesurable » — pas 0,
 *     qui se lirait comme « conforme ».
 */
class TechnicianWeekService
{
    /** Catégories d'intervention issues d'un itinéraire de culture. */
    private const CROP_CATEGORIES = [
        'semis', 'fertilisation', 'sarclage', 'traitement', 'irrigation', 'observation', 'recolte',
    ];

    /** Statuts de tâche qui restent à faire (dénominateur de la complétion). */
    private const OPEN_STATUSES = ['a_faire', 'en_cours', 'en_retard'];

    /**
     * Fiche hebdomadaire complète.
     *
     * @return array{
     *   employee: Employee, from: Carbon, to: Carbon,
     *   indicators: array<int, array<string, mixed>>,
     *   tasks: array<string, int>,
     *   batches: array<int, array<string, mixed>>,
     *   cycles: array<int, array<string, mixed>>,
     *   incidents: int,
     * }
     */
    public function forEmployee(Employee $employee, Carbon $weekStart): array
    {
        $from = $weekStart->copy()->startOfWeek();
        $to   = $from->copy()->endOfWeek();

        $tasks = $this->taskStats($employee, $from, $to);
        $batches = $this->batchRows($employee);
        $cycles = $this->cycleRows($employee);
        $incidents = $this->incidentCount($employee, $from, $to);

        return [
            'employee'   => $employee,
            'from'       => $from,
            'to'         => $to,
            'indicators' => $this->indicators($tasks, $batches),
            'tasks'      => $tasks,
            'batches'    => $batches,
            'cycles'     => $cycles,
            'incidents'  => $incidents,
        ];
    }

    /**
     * Les SIX indicateurs standardisés, dans l'ordre du rituel hebdomadaire :
     * d'abord ce qui dépend du technicien (a-t-il fait, et à temps), ensuite ce
     * que la conduite du troupeau révèle (mortalité, conversion, aliment),
     * enfin la partie cultures.
     *
     * `value` à null = NON MESURABLE (donnée absente), volontairement distinct
     * d'une valeur nulle : un « 0 » se lirait comme un résultat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function indicators(array $tasks, array $batches): array
    {
        $worstMortality = $this->worst($batches, 'mortality_rate');
        $worstFeedGap   = $this->worst($batches, 'feed_gap_percent', abs: true);
        $fcrAvg         = $this->average($batches, 'fcr');

        return [
            [
                'key'    => 'completion',
                'label'  => 'Taux de complétion des tâches',
                'value'  => $tasks['total'] > 0 ? round($tasks['done'] / $tasks['total'] * 100, 1) : null,
                'unit'   => '%',
                'target' => '≥ 90 %',
                'tone'   => $this->tone($tasks['total'] > 0 ? $tasks['done'] / $tasks['total'] * 100 : null, 90, 75),
                'detail' => "{$tasks['done']} faites sur {$tasks['total']} planifiées"
                            . ($tasks['late'] > 0 ? " · {$tasks['late']} en retard" : ''),
            ],
            [
                'key'    => 'punctuality',
                'label'  => 'Ponctualité de saisie (le jour même)',
                'value'  => $tasks['done'] > 0 ? round($tasks['on_time'] / $tasks['done'] * 100, 1) : null,
                'unit'   => '%',
                'target' => '100 %',
                'tone'   => $this->tone($tasks['done'] > 0 ? $tasks['on_time'] / $tasks['done'] * 100 : null, 100, 85),
                // Mesurée sur la date DÉCLARÉE de l'acte : le décalage de
                // synchronisation d'un site sans réseau ne compte pas comme un retard.
                'detail' => "{$tasks['on_time']} saisies le jour prévu sur {$tasks['done']} faites",
            ],
            [
                'key'    => 'mortality',
                'label'  => 'Mortalité du lot le plus atteint',
                'value'  => $worstMortality['value'],
                'unit'   => '%',
                'target' => '< ' . $this->mortalityThreshold() . ' %',
                'tone'   => $this->toneInverse($worstMortality['value'], $this->mortalityThreshold(), $this->mortalityThreshold() * 0.6),
                'detail' => $worstMortality['label'] ?? 'Aucun lot sous responsabilité',
            ],
            [
                'key'    => 'fcr',
                'label'  => 'Indice de consommation (FCR) moyen',
                'value'  => $fcrAvg,
                'unit'   => '',
                'target' => 'tendance stable',
                // Pas de seuil universel : le FCR cible dépend de l'espèce et de
                // l'âge. On l'affiche pour la TENDANCE, sans le colorer — un
                // faux seuil serait plus nuisible qu'aucun seuil.
                'tone'   => 'neutral',
                'detail' => $fcrAvg !== null
                    ? 'Sur ' . count(array_filter($batches, fn ($b) => $b['fcr'] !== null)) . ' lot(s) pesé(s)'
                    : 'Aucune pesée moyenne enregistrée',
            ],
            [
                'key'    => 'feed_gap',
                'label'  => 'Écart aliment réel / norme de souche',
                'value'  => $worstFeedGap['value'],
                'unit'   => '%',
                'target' => 'proche de 0',
                'tone'   => $this->toneInverse(
                    $worstFeedGap['value'] !== null ? abs($worstFeedGap['value']) : null, 10, 5,
                ),
                'detail' => $worstFeedGap['label'] ?? 'Aucune norme de souche renseignée',
            ],
            [
                'key'    => 'crop_interventions',
                'label'  => 'Interventions cultures réalisées / planifiées',
                'value'  => $tasks['crop_total'] > 0
                    ? round($tasks['crop_done'] / $tasks['crop_total'] * 100, 1)
                    : null,
                'unit'   => '%',
                'target' => '100 %',
                'tone'   => $this->tone(
                    $tasks['crop_total'] > 0 ? $tasks['crop_done'] / $tasks['crop_total'] * 100 : null, 100, 80,
                ),
                'detail' => "{$tasks['crop_done']} sur {$tasks['crop_total']} interventions d'itinéraire",
            ],
        ];
    }

    /**
     * Compte des tâches de la semaine.
     *
     * Le dénominateur est la tâche PLANIFIÉE dans la semaine (scheduled_date),
     * pas la tâche close dans la semaine : sinon ne rien faire donnerait 100 %
     * de complétion, l'indicateur s'auto-annulerait.
     *
     * @return array<string, int>
     */
    private function taskStats(Employee $employee, Carbon $from, Carbon $to): array
    {
        // whereDate() sur CHAQUE borne, et non whereBetween : la colonne est
        // castée en date et sqlite la stocke « Y-m-d 00:00:00 ». Un
        // whereBetween sur des chaînes « Y-m-d » exclut alors le DERNIER jour de
        // la semaine (« 2026-07-26 00:00:00 » > « 2026-07-26 ») — le dimanche
        // disparaissait silencieusement du calcul.
        $rows = TaskAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('scheduled_date', '>=', $from->toDateString())
            ->whereDate('scheduled_date', '<=', $to->toDateString())
            ->get(['status', 'category', 'scheduled_date', 'completed_at', 'crop_protocol_item_id']);

        $done = $rows->where('status', 'fait');

        // Ponctualité : la tâche a-t-elle été faite LE JOUR où elle était prévue ?
        // completed_at porte l'horodatage déclaré au terrain (cf. done_at).
        $onTime = $done->filter(function (TaskAssignment $task) {
            return $task->completed_at
                && $task->completed_at->toDateString() === $task->scheduled_date->toDateString();
        });

        $crop = $rows->filter(fn (TaskAssignment $t) => $t->crop_protocol_item_id !== null
            || in_array($t->category, self::CROP_CATEGORIES, true));

        return [
            'total'      => $rows->count(),
            'done'       => $done->count(),
            'on_time'    => $onTime->count(),
            'late'       => $rows->where('status', 'en_retard')->count(),
            'open'       => $rows->whereIn('status', self::OPEN_STATUSES)->count(),
            'crop_total' => $crop->count(),
            'crop_done'  => $crop->where('status', 'fait')->count(),
        ];
    }

    /**
     * Lots sous la responsabilité du technicien, avec mortalité, FCR et écart
     * aliment. Le FCR vient de Batch::fcr_corrected (même formule que le rapport
     * de performance technique) et l'écart aliment de BatchAdvisorService, pour
     * qu'aucun chiffre ne diverge d'un écran à l'autre.
     *
     * @return array<int, array<string, mixed>>
     */
    private function batchRows(Employee $employee): array
    {
        $advisor = app(BatchAdvisorService::class);

        return Batch::query()
            ->with('building:id,name')
            ->where('employee_id', $employee->id)
            ->active()
            ->live()
            ->get()
            ->map(function (Batch $batch) use ($advisor) {
                $reco = $advisor->recommendation($batch);

                // Écart aliment : réel de la dernière distribution contre la
                // norme de souche ajustée à l'environnement. null si la souche
                // n'est pas renseignée — on ne fabrique pas de référence.
                $gap = null;
                $expected = $reco['total']['feed_kg'] ?? 0;
                $actual = $reco['actual']['feed_kg'] ?? null;
                if ($expected > 0 && $actual !== null) {
                    $gap = round(($actual - $expected) / $expected * 100, 1);
                }

                return [
                    'id'             => $batch->id,
                    'code'           => $batch->code,
                    'building'       => $batch->building?->name,
                    'age_days'       => $batch->age,
                    'current'        => (int) $batch->current_quantity,
                    'mortality_rate' => $batch->mortality_rate,
                    'fcr'            => $batch->fcr_corrected,
                    'feed_gap_percent' => $gap,
                    'feed_expected_kg' => $expected > 0 ? round($expected, 1) : null,
                    'feed_actual_kg'   => $actual,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Cultures sous sa responsabilité, avec l'avancement de leur itinéraire —
     * le pendant végétal des lots.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cycleRows(Employee $employee): array
    {
        return CropCycle::query()
            ->with('plot:id,name')
            ->where('employee_id', $employee->id)
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->get()
            ->map(function (CropCycle $cycle) {
                $steps = TaskAssignment::where('crop_cycle_id', $cycle->id)
                    ->whereNotNull('crop_protocol_item_id')
                    ->get(['status']);

                return [
                    'id'         => $cycle->id,
                    'code'       => $cycle->code,
                    'crop_name'  => $cycle->crop_name,
                    'plot'       => $cycle->plot?->name,
                    'days_after_planting' => $cycle->planting_date
                        ? (int) Carbon::parse($cycle->planting_date)->diffInDays(now())
                        : null,
                    'steps_total' => $steps->count(),
                    'steps_done'  => $steps->where('status', 'fait')->count(),
                    'steps_late'  => $steps->where('status', 'en_retard')->count(),
                ];
            })
            ->values()
            ->all();
    }

    /** Incidents sanitaires déclarés dans la semaine (contexte, pas une cible). */
    private function incidentCount(Employee $employee, Carbon $from, Carbon $to): int
    {
        $batchIds = Batch::where('employee_id', $employee->id)->pluck('id');
        if ($batchIds->isEmpty()) {
            return 0;
        }

        return HealthIncident::whereIn('batch_id', $batchIds)
            ->whereDate('incident_date', '>=', $from->toDateString())
            ->whereDate('incident_date', '<=', $to->toDateString())
            ->count();
    }

    /** Seuil de mortalité paramétré à la ferme (même source que le rapport technique). */
    private function mortalityThreshold(): float
    {
        return (float) setting('elevage.mortality_alert', 5);
    }

    /**
     * Pire valeur d'une colonne parmi les lots (la seule qui appelle une action)
     * avec le libellé du lot concerné.
     *
     * @return array{value: float|null, label: string|null}
     */
    private function worst(array $batches, string $key, bool $abs = false): array
    {
        $candidates = array_filter($batches, fn ($b) => $b[$key] !== null);
        if ($candidates === []) {
            return ['value' => null, 'label' => null];
        }

        usort($candidates, fn ($a, $b) => ($abs ? abs($b[$key]) : $b[$key]) <=> ($abs ? abs($a[$key]) : $a[$key]));
        $worst = $candidates[0];

        $label = $worst['code'];
        if ($key === 'feed_gap_percent' && $worst['feed_expected_kg']) {
            $label .= sprintf(
                ' — %s kg distribués contre %s kg attendus',
                number_format((float) $worst['feed_actual_kg'], 1, ',', ' '),
                number_format((float) $worst['feed_expected_kg'], 1, ',', ' '),
            );
        } elseif ($key === 'mortality_rate') {
            $label .= sprintf(' — %s sujets vivants', number_format($worst['current'], 0, ',', ' '));
        }

        return ['value' => $worst[$key], 'label' => $label];
    }

    /** Moyenne d'une colonne sur les lots qui la renseignent (null si aucune). */
    private function average(array $batches, string $key): ?float
    {
        $values = array_values(array_filter(
            array_map(fn ($b) => $b[$key], $batches),
            fn ($v) => $v !== null && $v > 0,
        ));

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    /** Couleur d'un indicateur « plus haut = mieux ». */
    private function tone(?float $value, float $good, float $warn): string
    {
        if ($value === null) return 'neutral';

        return $value >= $good ? 'ok' : ($value >= $warn ? 'warn' : 'bad');
    }

    /** Couleur d'un indicateur « plus bas = mieux » (mortalité, écart). */
    private function toneInverse(?float $value, float $bad, float $warn): string
    {
        if ($value === null) return 'neutral';

        return $value >= $bad ? 'bad' : ($value >= $warn ? 'warn' : 'ok');
    }

    /**
     * Comparatif de tous les techniciens sur la semaine — la vue du promoteur.
     * Ne renvoie que les indicateurs, pour comparer trois personnes d'un coup
     * d'œil sans charger trois fiches.
     *
     * @return array<int, array{employee: Employee, indicators: array<int, array<string, mixed>>, tasks: array<string, int>}>
     */
    public function comparison(Carbon $weekStart): array
    {
        return Employee::active()
            ->orderBy('first_name')
            ->get()
            ->map(function (Employee $employee) use ($weekStart) {
                $sheet = $this->forEmployee($employee, $weekStart);

                return [
                    'employee'   => $employee,
                    'indicators' => $sheet['indicators'],
                    'tasks'      => $sheet['tasks'],
                ];
            })
            // Un employé sans aucune tâche ni lot n'a pas de semaine à comparer :
            // le laisser afficherait trois colonnes vides et diluerait la lecture.
            ->filter(fn ($row) => $row['tasks']['total'] > 0)
            ->values()
            ->all();
    }
}
