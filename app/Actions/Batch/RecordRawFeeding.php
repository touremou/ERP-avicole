<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\BatchCostAllocation;
use App\Models\Employee;
use App\Models\RawFeeding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ENREGISTRER UNE ALIMENTATION BRUTE — et imputer son temps au lot.
 *
 * Seule porte d'écriture (terrain : SyncService `raw_feeding.create`).
 *
 *   coût de l'action = (minutes ÷ 60) × coût horaire de l'employé
 *
 * Le coût horaire est FIGÉ sur l'enregistrement : une augmentation de salaire
 * ne réécrit pas le coût des semaines passées. L'imputation analytique
 * (BatchCostAllocation) porte un libellé automatique et entre dans le coût
 * d'exploitation du lot (Batch::operating_cost), distincte de l'aliment acheté.
 *
 * Idempotent par uuid : un rejeu de la synchro ne double ni l'action ni le coût.
 */
final class RecordRawFeeding
{
    /**
     * @param  array{uuid?: string, batch_id: int, fed_at: string, resource: string, quantity?: ?float,
     *               quantity_unit?: ?string, minutes_spent: int, plot_id?: ?int, notes?: ?string}  $data
     */
    public function execute(array $data, User $auteur): RawFeeding
    {
        if (! empty($data['uuid']) && $deja = RawFeeding::where('uuid', $data['uuid'])->first()) {
            return $deja;
        }

        $lot = Batch::findOrFail($data['batch_id']);
        if (! $lot->isActive() && ! $lot->isSolde()) {
            throw ValidationException::withMessages([
                'batch_id' => __('Le lot :code est clôturé : on ne le nourrit plus.', ['code' => $lot->code]),
            ]);
        }

        /** @var Employee|null $employe */
        $employe = $auteur->employee;
        $taux = $employe?->coutHoraire() ?? 0.0;
        $minutes = (int) $data['minutes_spent'];
        $cout = round($minutes / 60 * $taux, 2);

        return DB::transaction(function () use ($data, $auteur, $employe, $lot, $taux, $minutes, $cout) {
            $distribution = RawFeeding::create([
                'uuid'            => $data['uuid'] ?? null,
                'farm_id'         => $lot->farm_id,
                'batch_id'        => $lot->id,
                'user_id'         => $auteur->id,
                'employee_id'     => $employe?->id,
                'plot_id'         => $data['plot_id'] ?? null,
                'fed_at'          => $data['fed_at'],
                'resource'        => trim($data['resource']),
                'quantity'        => $data['quantity'] ?? null,
                'quantity_unit'   => $data['quantity_unit'] ?? null,
                'minutes_spent'   => $minutes,
                'hourly_cost_gnf' => $taux,
                'labor_cost_gnf'  => $cout,
                'notes'           => $data['notes'] ?? null,
            ]);

            if ($cout > 0) {
                $nom = $employe ? trim($employe->first_name . ' ' . $employe->last_name) : $auteur->name;
                BatchCostAllocation::create([
                    'farm_id'      => $lot->farm_id,
                    'batch_id'     => $lot->id,
                    'category'     => BatchCostAllocation::MAIN_OEUVRE_ALIMENTATION,
                    'label'        => "Main-d'œuvre : Coupe/Distribution {$distribution->resource} - {$nom}",
                    'amount'       => $cout,
                    'allocated_on' => $distribution->fed_at->toDateString(),
                    'source_type'  => $distribution->getMorphClass(),
                    'source_id'    => $distribution->id,
                ]);
            }

            return $distribution;
        });
    }
}
