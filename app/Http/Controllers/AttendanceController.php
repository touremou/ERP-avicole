<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * AttendanceController — pointage de présence quotidien de l'équipe (RH léger).
 *
 * Module : annuaire (RH). Saisie d'une grille jour (présent/absent/retard/congé),
 * avec pré-remplissage « congé » depuis les congés validés, puis rapport de
 * présence par employé sur une période.
 */
class AttendanceController extends Controller
{
    /** Grille de pointage du jour (ou d'une date choisie). */
    public function index(Request $request)
    {
        if (Gate::denies('annuaire.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $date = $this->resolveDate($request->input('date'));

        $employees = Employee::active()->orderBy('first_name')->get();

        // Pointages déjà saisis ce jour, indexés par employé.
        $existing = EmployeeAttendance::whereDate('attendance_date', $date)
            ->get()->keyBy('employee_id');

        // Employés en congé validé couvrant cette date → pré-statut « congé ».
        $onLeave = EmployeeLeave::approved()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')->flip();

        $rows = $employees->map(function ($emp) use ($existing, $onLeave) {
            $status = $existing[$emp->id]->status
                ?? ($onLeave->has($emp->id) ? 'conge' : 'present');

            return [
                'employee' => $emp,
                'status'   => $status,
                'locked'   => $onLeave->has($emp->id) && ! isset($existing[$emp->id]), // congé non encore pointé
            ];
        });

        return view('attendance.index', [
            'rows'     => $rows,
            'date'     => $date,
            'statuses' => EmployeeAttendance::STATUSES,
            'saved'    => $existing->isNotEmpty(),
        ]);
    }

    /** Enregistre/met à jour la grille de pointage d'une date. */
    public function store(Request $request)
    {
        if (Gate::denies('annuaire.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'date'           => ['required', 'date', 'before_or_equal:today'],
            'status'         => ['required', 'array'],
            'status.*'       => ['in:' . implode(',', array_keys(EmployeeAttendance::STATUSES))],
        ]);

        $date = $data['date'];
        $count = 0;

        foreach ($data['status'] as $employeeId => $status) {
            if (! Employee::whereKey($employeeId)->exists()) {
                continue; // anti-injection
            }

            // whereDate() compare la DATE seule : robuste que la colonne stocke
            // « Y-m-d » (MySQL) ou « Y-m-d 00:00:00 » (sqlite via le cast date).
            $record = EmployeeAttendance::where('employee_id', (int) $employeeId)
                ->whereDate('attendance_date', $date)
                ->first();

            if ($record) {
                $record->update(['status' => $status, 'recorded_by' => Auth::id()]);
            } else {
                EmployeeAttendance::create([
                    'employee_id'     => (int) $employeeId,
                    'attendance_date' => $date,
                    'status'          => $status,
                    'recorded_by'     => Auth::id(),
                ]);
            }
            $count++;
        }

        return redirect()->route('attendance.index', ['date' => $date])
            ->with('success', "Présence enregistrée pour {$count} employé(s).");
    }

    /** Rapport de présence par employé sur une période. */
    public function report(Request $request)
    {
        if (Gate::denies('annuaire.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);

        return view('attendance.report', [
            'rows' => $this->buildReport($from, $to),
            'from' => $from,
            'to'   => $to,
        ]);
    }

    /** Export CSV du rapport (séparateur « ; » + BOM UTF-8 pour Excel). */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (Gate::denies('annuaire.L')) {
            abort(403, 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);
        $rows = $this->buildReport($from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($out, ['Employé', 'Poste', 'Présent', 'Retard', 'Absent', 'Congé', 'Jours pointés', 'Taux présence %'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['employee']->first_name . ' ' . $r['employee']->last_name,
                    $r['employee']->job_title ?? '',
                    $r['counts']['present'], $r['counts']['retard'],
                    $r['counts']['absent'], $r['counts']['conge'],
                    $r['total'], $r['total'] > 0 ? $r['presence_rate'] : '',
                ], ';');
            }
            fclose($out);
        }, "presence-{$from}_{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Export PDF du rapport. */
    public function exportPdf(Request $request)
    {
        if (Gate::denies('annuaire.L')) {
            abort(403, 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);
        $rows = $this->buildReport($from, $to);

        return \Pdf::loadView('attendance.pdf.report', compact('rows', 'from', 'to'))
            ->setPaper('a4', 'portrait')
            ->download("presence-{$from}_{$to}.pdf");
    }

    /** Période (from, to) demandée, bornée et ordonnée. */
    private function resolvePeriod(Request $request): array
    {
        $from = $this->resolveDate($request->input('from'), now()->startOfMonth());
        $to   = $this->resolveDate($request->input('to'), now());

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    /**
     * Agrégat de présence par employé sur une période — source unique partagée
     * par l'affichage et les exports CSV/PDF.
     */
    private function buildReport(string $from, string $to): \Illuminate\Support\Collection
    {
        $employees = Employee::active()->orderBy('first_name')->get();

        $attendance = EmployeeAttendance::between($from, $to)->get()->groupBy('employee_id');

        return $employees->map(function ($emp) use ($attendance) {
            $records = $attendance->get($emp->id, collect());
            $counts = [
                'present' => $records->where('status', 'present')->count(),
                'retard'  => $records->where('status', 'retard')->count(),
                'absent'  => $records->where('status', 'absent')->count(),
                'conge'   => $records->where('status', 'conge')->count(),
            ];
            $total  = array_sum($counts);
            $worked = $counts['present'] + $counts['retard'];

            return [
                'employee'      => $emp,
                'counts'        => $counts,
                'total'         => $total,
                'worked'        => $worked,
                'presence_rate' => $total > 0 ? round($worked / $total * 100, 1) : 0.0,
            ];
        });
    }

    /** Date valide (≤ aujourd'hui) ou défaut. */
    private function resolveDate(?string $value, ?Carbon $default = null): string
    {
        $default ??= now();
        try {
            $d = $value ? Carbon::parse($value) : $default;
        } catch (\Throwable) {
            $d = $default;
        }

        return $d->toDateString();
    }
}
