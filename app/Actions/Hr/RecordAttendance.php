<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Illuminate\Support\Facades\DB;

/**
 * RecordAttendance — enregistre la grille de pointage d'une JOURNÉE.
 *
 * Source unique partagée par la grille web (AttendanceController::store) et le
 * terrain hors-ligne (SyncService::attendanceCreate) : la règle de présence ne
 * doit pas diverger entre les deux portes d'entrée.
 *
 * Idempotent PAR NATURE : la clé métier est (employé, jour) — contrainte UNIQUE
 * en base. Rejouer la même grille réécrit les mêmes lignes, ne les duplique
 * pas. C'est ce qui permet au terrain de pousser sans uuid de déduplication :
 * corriger un statut le soir (« finalement absent ») est un rejeu légitime.
 */
class RecordAttendance
{
    /**
     * @param  string  $date  jour pointé (Y-m-d)
     * @param  array<int, array{employee_id: int|string, status: string, check_in_time?: string|null}>  $rows
     * @return array{saved: int, skipped: int}  skipped = employés hors ferme (anti-injection)
     */
    public function execute(string $date, array $rows, ?int $userId = null): array
    {
        return DB::transaction(function () use ($date, $rows, $userId) {
            $saved = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $employeeId = (int) ($row['employee_id'] ?? 0);

                // Anti-injection : le pointage ne sort jamais du périmètre de la
                // ferme courante (Employee est borné par BelongsToFarm).
                if ($employeeId <= 0 || ! Employee::whereKey($employeeId)->exists()) {
                    $skipped++;
                    continue;
                }

                $attributes = [
                    'status'      => $row['status'],
                    'recorded_by' => $userId,
                ];

                if (array_key_exists('check_in_time', $row)) {
                    $attributes['check_in_time'] = $row['check_in_time'] ?: null;
                }

                // whereDate() compare la DATE seule : robuste que la colonne
                // stocke « Y-m-d » (MySQL) ou « Y-m-d 00:00:00 » (sqlite via le
                // cast date), sinon le rejeu créerait un doublon.
                $existing = EmployeeAttendance::where('employee_id', $employeeId)
                    ->whereDate('attendance_date', $date)
                    ->first();

                if ($existing) {
                    $existing->update($attributes);
                } else {
                    EmployeeAttendance::create($attributes + [
                        'employee_id'     => $employeeId,
                        'attendance_date' => $date,
                    ]);
                }

                $saved++;
            }

            return ['saved' => $saved, 'skipped' => $skipped];
        });
    }
}
