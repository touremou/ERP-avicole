<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\EmployeeContractEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les deux seules décisions possibles à l'échéance d'un contrat à terme :
 * PROLONGER, ou NOTIFIER LA FIN. Ne rien faire n'est pas une option neutre —
 * un CDD qui court au-delà de son terme sans acte se requalifie.
 *
 * Source unique, partagée par le web et (le jour où le besoin viendra) la
 * synchro : chaque décision repousse ou fige le terme ET laisse une trace
 * datée, car `contract_end_date` seul écrase l'historique des prolongations.
 */
class DecideContract
{
    /**
     * Repousse le terme. Le nouveau terme doit être POSTÉRIEUR à l'actuel :
     * « prolonger » vers une date antérieure serait une rupture anticipée, qui
     * relève du préavis et pas d'ici.
     */
    public function prolong(Employee $employee, string $newEndDate, ?string $reason = null, ?int $userId = null): Employee
    {
        $this->assertFixedTerm($employee);

        $new = Carbon::parse($newEndDate)->startOfDay();
        $current = $employee->contract_end_date?->copy()->startOfDay();

        if ($current && $new->lte($current)) {
            throw ValidationException::withMessages([
                'new_end_date' => __('Le nouveau terme (:new) doit être postérieur au terme actuel (:current).', [
                    'new'     => $new->format('d/m/Y'),
                    'current' => $current->format('d/m/Y'),
                ]),
            ]);
        }

        return DB::transaction(function () use ($employee, $new, $current, $reason, $userId) {
            EmployeeContractEvent::create([
                'farm_id'           => $employee->farm_id,
                'employee_id'       => $employee->id,
                'type'              => 'prolongation',
                'decided_on'        => now()->toDateString(),
                'previous_end_date' => $current?->toDateString(),
                'new_end_date'      => $new->toDateString(),
                'reason'            => $reason,
                'user_id'           => $userId,
            ]);

            // Un préavis émis puis annulé par une prolongation : on le lève,
            // sinon l'employé resterait « préavis émis » alors qu'il continue.
            $employee->update([
                'contract_end_date' => $new->toDateString(),
                'notice_given_at'   => null,
            ]);

            return $employee->refresh();
        });
    }

    /**
     * Émet le préavis : la fin est notifiée. `notice_given_at` prouve QUE le
     * préavis a été donné et QUAND — c'est la pièce qui manque le plus souvent
     * dans un dossier, et elle ne se reconstitue pas après coup.
     *
     * Le dernier jour travaillé peut être avancé (rupture au terme du préavis)
     * mais pas repoussé : repousser, c'est prolonger.
     */
    public function issueNotice(Employee $employee, ?string $lastDay = null, ?string $reason = null, ?int $userId = null): Employee
    {
        $this->assertFixedTerm($employee);

        if ($employee->notice_given_at) {
            throw ValidationException::withMessages([
                'notice' => __('Un préavis a déjà été émis le :date.', [
                    'date' => $employee->notice_given_at->format('d/m/Y'),
                ]),
            ]);
        }

        $term = $employee->contract_end_date?->copy()->startOfDay();
        $end = $lastDay ? Carbon::parse($lastDay)->startOfDay() : $term;

        if ($term && $end && $end->gt($term)) {
            throw ValidationException::withMessages([
                'last_day' => __('Le dernier jour travaillé ne peut pas dépasser le terme du contrat (:term). Utilisez une prolongation.', [
                    'term' => $term->format('d/m/Y'),
                ]),
            ]);
        }

        return DB::transaction(function () use ($employee, $term, $end, $reason, $userId) {
            EmployeeContractEvent::create([
                'farm_id'           => $employee->farm_id,
                'employee_id'       => $employee->id,
                'type'              => 'preavis',
                'decided_on'        => now()->toDateString(),
                'previous_end_date' => $term?->toDateString(),
                'new_end_date'      => $end?->toDateString(),
                'reason'            => $reason,
                'user_id'           => $userId,
            ]);

            // Le contrat n'est pas clos aujourd'hui : il l'est au dernier jour.
            // On ne touche donc PAS au statut RH ici — l'employé reste actif et
            // continue d'être pointé et payé jusqu'au terme. L'archivage relève
            // de la sortie (ArchiveEmployee), pas de la notification.
            $employee->update([
                'contract_end_date' => $end?->toDateString(),
                'notice_given_at'   => now()->toDateString(),
            ]);

            return $employee->refresh();
        });
    }

    private function assertFixedTerm(Employee $employee): void
    {
        if (! $employee->hasFixedTerm()) {
            throw ValidationException::withMessages([
                'contract_type' => __("Ce contrat est un :type : il n'a pas de terme à prolonger ni à notifier.", [
                    'type' => $employee->contract_type,
                ]),
            ]);
        }
    }
}
