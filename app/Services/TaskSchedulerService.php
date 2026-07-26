<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Building;
use App\Models\CropCycle;
use App\Models\CropSpecies;
use App\Models\Employee;
use App\Models\Plot;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TaskSchedulerService
{
    /**
     * Génère les tâches pour une date et une ferme donnée.
     *
     * @param Carbon   $date
     * @param int|null $farmId  Si null, génère pour TOUTES les fermes (cron)
     */
    public function generateForDate(Carbon $date, ?int $farmId = null): array
    {
        // Templates = globaux (pas de farm_id)
        $templates = TaskTemplate::withoutGlobalScopes()->where('is_active', true)->get();

        // Bâtiments et employés = filtrés par ferme.
        // On exclut les bâtiments virtuels (cf. Building::physical) et on
        // n'exige que des lots RÉELS actifs (->live), pour qu'aucun bâtiment
        // ni lot virtuel de traçabilité ne génère de tâches.
        $buildingQuery = Building::physical()
            ->whereHas('batches', fn($q) => $q->active()->live());
        $employeeQuery = Employee::where('status', 'Actif');

        if ($farmId && Schema::hasColumn('buildings', 'farm_id')) {
            $buildingQuery->where('farm_id', $farmId);
        }
        if ($farmId && Schema::hasColumn('employees', 'farm_id')) {
            $employeeQuery->where('farm_id', $farmId);
        }

        $activeBuildings = $buildingQuery->get();
        $employees = $employeeQuery->get();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($date, $farmId, $templates, $activeBuildings, $employees, &$created, &$skipped) {
            foreach ($templates as $tpl) {
                if (! $tpl->shouldRunOnDay($date)) continue;

                if ($tpl->per_building) {
                    foreach ($activeBuildings as $building) {
                        if ($tpl->batch_types) {
                            $hasBatchType = Batch::where('building_id', $building->id)
                                ->active()
                                ->live()
                                ->whereHas('productionType', fn ($q) => $q->whereIn('slug', $tpl->batch_types))
                                ->exists();
                            if (! $hasBatchType) continue;
                        }

                        if ($this->alreadyExists($tpl, $date, $building->id, $farmId)) { $skipped++; continue; }

                        $employee = $this->findBestEmployee($building, $employees, $date);

                        TaskAssignment::create([
                            'farm_id'          => $farmId ?? $building->farm_id ?? null,
                            'task_template_id' => $tpl->id,
                            // Pool : pas de titulaire — le premier qui la prend se l'attribue.
                            'employee_id'      => $tpl->is_pool ? null : $employee?->id,
                            'is_pool'          => $tpl->is_pool,
                            'title'            => $tpl->name . ' — ' . $building->name,
                            'description'      => $tpl->description,
                            'category'         => $tpl->category,
                            'proof_type'       => $tpl->proof_type ?? 'aucune',
                            'proof_label'      => $tpl->proof_label,
                            'proof_unit'       => $tpl->proof_unit,
                            'building_id'      => $building->id,
                            'scheduled_date'   => $date,
                            'scheduled_time'   => $tpl->scheduled_time,
                            'duration_minutes' => $tpl->duration_minutes,
                            'priority'         => $tpl->priority,
                            'status'           => 'a_faire',
                            'is_auto_generated' => true,
                        ]);
                        $created++;
                    }
                } elseif ($tpl->target_type === 'plot') {
                    // Generate one task per active plot (plots with in-progress crop cycles).
                    $plotQuery = Plot::where('status', Plot::STATUS_EN_CULTURE);
                    if ($farmId && Schema::hasColumn('plots', 'farm_id')) {
                        $plotQuery->where('farm_id', $farmId);
                    }
                    $activePlots = $plotQuery->with(['cropCycles' => fn($q) => $q->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)])->get();

                    foreach ($activePlots as $plot) {
                        // Filter by plot_types if set (match against CropSpecies type via crop_name).
                        if ($tpl->plot_types) {
                            $hasMatchingCrop = $plot->cropCycles->contains(function ($cycle) use ($tpl) {
                                // We match on the CropSpecies type if species exists, else pass.
                                $species = CropSpecies::where('name', $cycle->crop_name)->first();
                                return $species && in_array($species->type, $tpl->plot_types);
                            });
                            if (!$hasMatchingCrop) continue;
                        }

                        if ($this->alreadyExistsForPlot($tpl, $date, $plot->id, $farmId)) { $skipped++; continue; }

                        $employee = $this->findBestEmployeeForPlot($plot, $employees, $date);

                        TaskAssignment::create([
                            'farm_id'           => $farmId ?? $plot->farm_id ?? null,
                            'task_template_id'  => $tpl->id,
                            'employee_id'       => $tpl->is_pool ? null : $employee?->id,
                            'is_pool'           => $tpl->is_pool,
                            'title'             => $tpl->name . ' — ' . $plot->name,
                            'description'       => $tpl->description,
                            'category'          => $tpl->category,
                            'proof_type'        => $tpl->proof_type ?? 'aucune',
                            'proof_label'       => $tpl->proof_label,
                            'proof_unit'        => $tpl->proof_unit,
                            'plot_id'           => $plot->id,
                            'scheduled_date'    => $date,
                            'scheduled_time'    => $tpl->scheduled_time,
                            'duration_minutes'  => $tpl->duration_minutes,
                            'priority'          => $tpl->priority,
                            'status'            => 'a_faire',
                            'is_auto_generated' => true,
                        ]);
                        $created++;
                    }
                } else {
                    if ($this->alreadyExists($tpl, $date, null, $farmId)) { $skipped++; continue; }

                    TaskAssignment::create([
                        'farm_id'          => $farmId,
                        'task_template_id' => $tpl->id,
                        'is_pool'          => $tpl->is_pool,
                        'title'            => $tpl->name,
                        'description'      => $tpl->description,
                        'category'         => $tpl->category,
                        'proof_type'       => $tpl->proof_type ?? 'aucune',
                        'proof_label'      => $tpl->proof_label,
                        'proof_unit'       => $tpl->proof_unit,
                        'scheduled_date'   => $date,
                        'scheduled_time'   => $tpl->scheduled_time,
                        'duration_minutes' => $tpl->duration_minutes,
                        'priority'         => $tpl->priority,
                        'status'           => 'a_faire',
                        'is_auto_generated' => true,
                    ]);
                    $created++;
                }
            }
        });

        // ── ITINÉRAIRE TECHNIQUE (S1) ──
        // Les étapes en jours après semis (« traitement phyto J+30 ») ne sont pas
        // calendaires : elles dépendent de la date de semis de CHAQUE cycle. Elles
        // deviennent ici de vraies tâches, donc visibles au calendrier et comptées
        // dans le taux de complétion — jusqu'à présent elles n'existaient qu'en
        // alerte, et un technicien pouvait afficher 100 % en les ayant toutes
        // manquées.
        $created += $this->generateProtocolTasks($date, $farmId, $employees);

        // ── CONTRÔLES DE CONSERVATION (T2) ──
        // Un lot gardé pour être vendu plus cher se dégrade sans surveillance.
        // La consigne de contrôle périodique n'existerait nulle part si elle ne
        // devenait pas une tâche : ni au calendrier, ni dans la complétion.
        $created += $this->generateStoredLotChecks($date, $farmId, $employees);

        // Marquer en retard (jours précédents, même ferme)
        $overdueQuery = TaskAssignment::where('status', 'a_faire')
            ->where('scheduled_date', '<', $date->toDateString());
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $overdueQuery->where('farm_id', $farmId);
        }
        $overdue = $overdueQuery->update(['status' => 'en_retard']);

        Log::info("Tasks [{$farmId}] {$date->format('d/m')}: {$created} created, {$skipped} skipped, {$overdue} overdue");

        return ['created' => $created, 'skipped' => $skipped, 'overdue' => $overdue];
    }

    /**
     * Matérialise en TÂCHES les étapes d'itinéraire technique arrivées à échéance.
     *
     * Source de vérité : CropProtocolAlertService::getCycleSchedule(), qui projette
     * chaque étape (jours après semis → date cible) et calcule son statut. On ne
     * duplique donc AUCUNE règle de phénologie ici — on ne fait que transformer un
     * « due / overdue » en tâche assignée.
     *
     * Trois choix qui comptent :
     *
     *  - scheduled_date = la DATE CIBLE de l'étape, pas aujourd'hui. Une étape
     *    prévue J+30 et découverte avec trois jours de retard doit apparaître au
     *    30, pas au 33 : sinon le retard disparaît du calendrier et le taux de
     *    ponctualité devient faux ;
     *  - idempotence par (cycle, étape) — index UNIQUE en base. Le générateur
     *    tourne chaque jour, sur des étapes qui restent « overdue » plusieurs
     *    jours : sans cette clé il en créerait une par jour de retard ;
     *  - une étape DÉJÀ FAITE ne génère rien. Comme la complétion d'une tâche
     *    écrit en retour une CropProtocolCompletion (TaskAssignment::
     *    recordProtocolCompletion), la boucle se referme et le calendrier
     *    s'accorde avec l'itinéraire au lieu de le contredire.
     */
    private function generateProtocolTasks(Carbon $date, ?int $farmId, $employees): int
    {
        $alerts = app(\App\Services\CropProtocolAlertService::class);

        $cycleQuery = CropCycle::query()
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->whereNotNull('crop_protocol_id')
            ->whereNotNull('planting_date')
            ->with(['protocol.items', 'inputs', 'harvests', 'plot']);

        if ($farmId && Schema::hasColumn('crop_cycles', 'farm_id')) {
            $cycleQuery->where('farm_id', $farmId);
        }

        $created = 0;

        foreach ($cycleQuery->get() as $cycle) {
            foreach ($alerts->getCycleSchedule($cycle) as $entry) {
                // Seules les étapes échues comptent : on ne remplit pas le
                // calendrier du technicien avec l'itinéraire des trois mois à
                // venir, il n'y verrait plus ce qui est à faire aujourd'hui.
                if (! in_array($entry['status'], ['due', 'overdue'], true)) {
                    continue;
                }

                /** @var \App\Models\CropProtocolItem $item */
                $item = $entry['item'];

                // La date cible ne doit pas être dans le futur du jour généré :
                // une génération rétroactive ne crée pas de tâches à l'avance.
                if ($entry['target_date']->gt($date)) {
                    continue;
                }

                if (TaskAssignment::where('crop_cycle_id', $cycle->id)
                    ->where('crop_protocol_item_id', $item->id)
                    ->exists()) {
                    continue;
                }

                // Responsable du cycle en priorité (continuité du suivi), sinon
                // répartition de charge sur la parcelle.
                $employeeId = $cycle->employee_id
                    ?? ($cycle->plot ? $this->findBestEmployeeForPlot($cycle->plot, $employees, $date)?->id : null);

                TaskAssignment::create([
                    'farm_id'               => $farmId ?? $cycle->farm_id ?? null,
                    'task_template_id'      => null,
                    'employee_id'           => $employeeId,
                    'is_pool'               => false,
                    'title'                 => $item->action_name . ' — ' . $cycle->crop_name
                                               . ($cycle->code ? " ({$cycle->code})" : ''),
                    'description'           => $this->protocolStepDescription($item, (int) $item->day_number),
                    'category'              => $item->type,
                    'plot_id'               => $cycle->plot_id,
                    'crop_cycle_id'         => $cycle->id,
                    'crop_protocol_item_id' => $item->id,
                    'scheduled_date'        => $entry['target_date']->toDateString(),
                    'priority'              => $entry['status'] === 'overdue' ? 'critique' : 'haute',
                    'status'                => 'a_faire',
                    'is_auto_generated'     => true,
                ] + $this->protocolStepProof($item));

                $created++;
            }
        }

        return $created;
    }

    /** Consigne de l'étape : ce que le technicien doit lire sur son téléphone. */
    private function protocolStepDescription(\App\Models\CropProtocolItem $item, int $day): string
    {
        $parts = array_filter([
            $item->stage ? "Stade : {$item->stage}" : null,
            "Prévu J+{$day} après semis",
            $item->product_suggested ? "Produit : {$item->product_suggested}" : null,
            $item->dose ? "Dose : {$item->dose}" : null,
            $item->method ? "Méthode : {$item->method}" : null,
            $item->notes,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * Preuve d'exécution exigée selon le type d'étape.
     *
     * Un TRAITEMENT PHYTOSANITAIRE exige une photo : c'est l'acte le moins
     * vérifiable à distance, celui qui engage un délai avant récolte (DAR) et
     * une responsabilité sanitaire. Sur un site sans binôme pour le contrôle
     * croisé, la photo horodatée est le seul élément objectif disponible.
     * Une OBSERVATION exige une valeur chiffrée : « j'ai regardé » ne se
     * vérifie pas, « 12 pieds atteints sur 100 » se compare d'une semaine
     * à l'autre.
     *
     * @return array<string, string|null>
     */
    private function protocolStepProof(\App\Models\CropProtocolItem $item): array
    {
        return match ($item->type) {
            'traitement'  => ['proof_type' => 'photo', 'proof_label' => 'Photo de la parcelle traitée', 'proof_unit' => null],
            'observation' => ['proof_type' => 'valeur', 'proof_label' => 'Pieds atteints observés', 'proof_unit' => 'pieds'],
            default       => ['proof_type' => 'aucune', 'proof_label' => null, 'proof_unit' => null],
        };
    }

    /**
     * Matérialise en tâches les CONTRÔLES DE CONSERVATION échus.
     *
     * Une tâche par lot à contrôler, datée à son échéance (et non au jour de
     * génération) : comme pour les étapes d'itinéraire, dater à aujourd'hui
     * effacerait le retard et fausserait la ponctualité.
     *
     * Preuve exigée : une VALEUR — la pesée. C'est tout l'objet du contrôle ;
     * « je suis passé voir » ne se recoupe avec rien, « 86,5 kg » se compare au
     * relevé précédent et donne la freinte.
     *
     * Idempotence : une seule tâche ouverte par lot à la fois. Tant que le
     * contrôle n'est pas fait, on ne réempile pas une tâche par jour de retard ;
     * dès qu'il est fait, l'échéance suivante en produira une nouvelle.
     */
    private function generateStoredLotChecks(Carbon $date, ?int $farmId, $employees): int
    {
        $lotQuery = \App\Models\StoredLot::query()->open()->with('stock');

        if ($farmId && Schema::hasColumn('stored_lots', 'farm_id')) {
            $lotQuery->where('farm_id', $farmId);
        }

        $created = 0;

        foreach ($lotQuery->get() as $lot) {
            $due = $lot->next_check_due_at;

            if ($due->startOfDay()->gt($date->copy()->startOfDay())) {
                continue;
            }

            // Une tâche de contrôle DÉJÀ OUVERTE sur ce lot suffit.
            $hasOpen = TaskAssignment::where('stored_lot_id', $lot->id)
                ->whereIn('status', ['a_faire', 'en_cours', 'en_retard'])
                ->exists();

            if ($hasOpen) {
                continue;
            }

            // Le contrôle déjà fait à cette échéance ne se redemande pas.
            $alreadyDone = TaskAssignment::where('stored_lot_id', $lot->id)
                ->whereDate('scheduled_date', $due->toDateString())
                ->exists();

            if ($alreadyDone) {
                continue;
            }

            $employee = $employees->reject(fn ($emp) => $emp->isOnLeaveOn($date))->first();

            TaskAssignment::create([
                'farm_id'           => $farmId ?? $lot->farm_id ?? null,
                'task_template_id'  => null,
                'employee_id'       => $employee?->id,
                'is_pool'           => $employee === null,
                'title'             => 'Contrôle de conservation — ' . $lot->label,
                'description'       => $this->storedLotCheckDescription($lot),
                'category'          => 'controle',
                'stored_lot_id'     => $lot->id,
                'scheduled_date'    => $due->toDateString(),
                'priority'          => $lot->is_past_deadline ? 'critique' : 'haute',
                'status'            => 'a_faire',
                'is_auto_generated' => true,
                'proof_type'        => 'valeur',
                'proof_label'       => 'Pesée du lot',
                'proof_unit'        => $lot->unit,
            ]);

            $created++;
        }

        return $created;
    }

    /** Consigne du contrôle : ce qu'il faut mesurer et regarder, sur place. */
    private function storedLotCheckDescription(\App\Models\StoredLot $lot): string
    {
        $parts = [
            sprintf('Peser le lot (dernier relevé : %s %s)', number_format((float) $lot->quantity_current, 1, ',', ' '), $lot->unit),
            'Vérifier humidité, insectes, moisissure',
            'Relever le cours du marché du jour',
        ];

        if ($lot->target_unit_price !== null) {
            $parts[] = sprintf('Objectif de vente : %s / %s', number_format((float) $lot->target_unit_price, 0, ',', ' '), $lot->unit);
        }

        if ($lot->hold_until) {
            $parts[] = 'Échéance de détention : ' . $lot->hold_until->format('d/m/Y');
        }

        return implode(' · ', $parts);
    }

    private function alreadyExists(TaskTemplate $tpl, Carbon $date, ?int $buildingId, ?int $farmId): bool
    {
        $q = TaskAssignment::where('task_template_id', $tpl->id)
            ->where('scheduled_date', $date->toDateString());

        if ($buildingId) $q->where('building_id', $buildingId);
        else $q->whereNull('building_id');

        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $q->where('farm_id', $farmId);
        }

        return $q->exists();
    }

    private function findBestEmployee(Building $building, $employees, Carbon $date): ?Employee
    {
        // Garde-fou disponibilité : on écarte d'emblée les employés en congé
        // approuvé à cette date — on n'auto-assigne jamais une tâche à un absent.
        $available = $employees->reject(fn ($emp) => $emp->isOnLeaveOn($date))->values();
        if ($available->isEmpty()) return null;

        // 1. GARDIEN DÉDIÉ DU BÂTIMENT (configuration opérationnelle explicite) :
        //    un bâtiment confié à un agent (assigned_building_id) reste sous sa
        //    responsabilité, quelle que soit sa charge — choix d'organisation
        //    assumé, distinct de la répartition automatique ci-dessous.
        if (Schema::hasColumn('employees', 'assigned_building_id')) {
            $keeper = $available->firstWhere('assigned_building_id', $building->id);
            if ($keeper) return $keeper;
        }

        // 2. RÉPARTITION DE CHARGE (équité) : à défaut de gardien dédié, on
        //    retient l'employé le MOINS chargé ce jour-là. À charge égale, on
        //    préfère le responsable du lot présent dans le bâtiment (continuité
        //    du suivi), puis l'ordre stable.
        //
        //    Le tri par charge est PRIORITAIRE sur la responsabilité du lot :
        //    sans cela, toutes les tâches retombaient sur le responsable des
        //    lots (souvent l'agent qui a créé les bandes), en ignorant la
        //    disponibilité des autres employés — exactement le défaut signalé.
        $batch = Batch::where('building_id', $building->id)->active()->live()->first();
        $batchEmployeeId = $batch?->employee_id;

        return $available
            ->sortBy(function ($emp) use ($date, $batchEmployeeId) {
                // whereDate (et non une égalité de chaîne) : la colonne est
                // castée en datetime (« …00:00:00 »), une comparaison brute à
                // « Y-m-d » ne matchait jamais → la charge ressortait toujours à
                // 0 et la répartition était inopérante.
                $load = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
                    ->where('employee_id', $emp->id)
                    ->count();

                // Clé composite : charge du jour ×10 (critère principal) + bonus
                // de continuité (0 pour le responsable du lot, 1 sinon).
                return $load * 10 + ($emp->id === $batchEmployeeId ? 0 : 1);
            })
            ->first();
    }

    private function alreadyExistsForPlot(TaskTemplate $tpl, Carbon $date, int $plotId, ?int $farmId): bool
    {
        $q = TaskAssignment::where('task_template_id', $tpl->id)
            ->where('scheduled_date', $date->toDateString())
            ->where('plot_id', $plotId);
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $q->where('farm_id', $farmId);
        }
        return $q->exists();
    }

    private function findBestEmployeeForPlot(Plot $plot, $employees, Carbon $date): ?Employee
    {
        $available = $employees->reject(fn ($emp) => $emp->isOnLeaveOn($date))->values();
        if ($available->isEmpty()) return null;

        // Prefer the employee assigned to the most recent active crop cycle on this plot.
        $cycleEmployeeId = $plot->cropCycles
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->sortByDesc('planting_date')
            ->first()?->employee_id;

        return $available
            ->sortBy(function ($emp) use ($date, $cycleEmployeeId) {
                $load = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
                    ->where('employee_id', $emp->id)
                    ->count();
                return $load * 10 + ($emp->id === $cycleEmployeeId ? 0 : 1);
            })
            ->first();
    }

    /**
     * Stats dashboard — filtrées par ferme si applicable.
     */
    public function getDashboardStats(Carbon $date, ?int $farmId = null): array
    {
        $query = TaskAssignment::forDate($date);
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $query->where('farm_id', $farmId);
        }
        $tasks = $query->get();

        $overdueQuery = TaskAssignment::overdue();
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $overdueQuery->where('farm_id', $farmId);
        }

        return [
            'total'       => $tasks->count(),
            'done'        => $tasks->where('status', 'fait')->count(),
            'pending'     => $tasks->whereIn('status', ['a_faire'])->count(),
            'overdue'     => $overdueQuery->count(),
            'rate'        => $tasks->count() > 0 ? round($tasks->where('status', 'fait')->count() / $tasks->count() * 100) : 0,
            'by_category' => $tasks->groupBy('category')->map->count(),
            'by_employee' => $tasks->groupBy('employee_id')->map(fn($t) => [
                'total' => $t->count(), 'done' => $t->where('status', 'fait')->count(),
            ]),
        ];
    }
}
