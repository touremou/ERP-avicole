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

                /*
                 * ─── LE PÉRIMÈTRE EST CELUI QUE L'ÉCRAN PROPOSE ───
                 *
                 * Ce garde interrogeait `Employee::whereKey(...)` tel quel, donc
                 * sous le scope de ferme : « rattaché à ce site ». Les deux
                 * écrans qui remplissent la grille — la grille web et le miroir
                 * mobile — listent, eux, `assignableInCurrentFarm()`, qui repose
                 * sur l'AFFECTATION datée et inclut donc les agents PRÊTÉS.
                 *
                 * L'agent prêté était donc proposé à la saisie, coché présent, et
                 * sa ligne écartée en silence à l'enregistrement.
                 *
                 * Mesuré : grille de deux agents sur le site d'accueil, un local
                 * et un prêté → `saved: 1, skipped: 1`, et aucune ligne pour le
                 * prêté. L'écran annonçait « Présence enregistrée ».
                 *
                 * Le défaut « agent prêté » avait déjà été corrigé en deux temps
                 * — rendre la fiche visible, puis l'agent désignable — et la
                 * règle unique existe (`visibleInFarm`). Ce garde-ci était le
                 * troisième endroit, celui qui ÉCRIT.
                 *
                 * On retient la visibilité et non `assignable` (qui exige en plus
                 * « Actif ») : le rôle de ce garde est le PÉRIMÈTRE, pas le
                 * statut. Sinon, corriger le pointage du matin pour un agent
                 * suspendu l'après-midi deviendrait impossible.
                 */
                if ($employeeId <= 0
                    || ! Employee::visibleInCurrentFarm()->whereKey($employeeId)->exists()) {
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
