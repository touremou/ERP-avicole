<?php

namespace App\Http\Controllers;

use App\Actions\Hr\RecordAttendance;
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
 * Module : rh (Ressources Humaines). Saisie d'une grille jour (présent/absent/retard/congé),
 * avec pré-remplissage « congé » depuis les congés validés, puis rapport de
 * présence par employé sur une période.
 */
class AttendanceController extends Controller
{
    /** Grille de pointage du jour (ou d'une date choisie). */
    public function index(Request $request)
    {
        if (Gate::denies('rh.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $date = $this->resolveDate($request->input('date'));

        $employees = Employee::assignableInCurrentFarm()->orderBy('first_name')->get();

        // Pointages déjà saisis ce jour, indexés par employé.
        $existing = EmployeeAttendance::whereDate('attendance_date', $date)
            ->get()->keyBy('employee_id');

        // Employés en congé validé couvrant cette date → pré-statut « congé ».
        $onLeave = EmployeeLeave::approved()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')->flip();

        $rows = $employees->map(function ($emp) use ($existing, $onLeave) {
            /*
             * LE DÉFAUT DE L'ÉCRAN SUIT LE RÉGIME DU CONTRAT.
             *
             * « Présent » était proposé à tout le monde. C'est le bon geste pour
             * un CDI ou un CDD — ils sont attendus, l'opérateur ne coche que les
             * écarts, et enregistrer sans rien changer dit la vérité.
             *
             * Pour un JOURNALIER, c'est l'inverse : il n'est pas attendu, il
             * vient. L'opérateur cochait les trois venus ce matin et enregistrait
             * — les sept autres partaient « présent » parce que c'est ce que
             * l'écran proposait. Depuis que la paie lit ces journées, sept
             * journées dues qu'aucune main n'avait voulu déclarer.
             *
             * Le geste CORRECT — ne rien toucher pour ceux qui ne sont pas venus —
             * produisait donc le pire résultat possible.
             *
             * Un congé validé garde son pré-remplissage dans les deux régimes :
             * c'est un fait déjà établi, pas une présomption. Et un pointage déjà
             * saisi prime toujours, sans quoi rouvrir la grille effacerait le
             * travail de la veille.
             *
             * Règle unique : cf. Employee::isPresumedPresent().
             */
            $defaut = $emp->isPresumedPresent() ? 'present' : 'absent';

            $status = $existing[$emp->id]->status
                ?? ($onLeave->has($emp->id) ? 'conge' : $defaut);

            /*
             * L'HEURE D'ARRIVÉE, RENDUE À L'ÉCRAN QUI LA SAISIT.
             *
             * La colonne existait, le terrain hors-ligne la remplissait
             * (`SyncService::attendanceCreate` la valide, `RecordAttendance` la
             * persiste) — et AUCUN écran ne la montrait ni ne permettait de la
             * saisir. Une heure relevée au téléphone n'était donc lisible nulle
             * part, et un « retard » enregistré au bureau restait une mention
             * sans la pièce qui la justifie.
             *
             * Elle est PRÉ-REMPLIE de ce qui est enregistré : la grille se
             * ré-enregistre telle quelle des dizaines de fois par mois, et un
             * champ vide renverrait « pas d'heure » — effaçant en silence ce que
             * le terrain avait relevé. C'est la même exigence que le défaut de
             * statut : ce que l'écran propose doit dire la vérité sur ce
             * qu'enregistrer va produire.
             *
             * La colonne est de type `time` : MySQL rend « 08:30:00 », un
             * champ <input type="time"> attend « 08:30 ».
             */
            $heure = $existing[$emp->id]->check_in_time ?? null;

            return [
                'employee' => $emp,
                'status'   => $status,
                'check_in' => $heure ? substr((string) $heure, 0, 5) : null,
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
        if (Gate::denies('rh.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'date'             => ['required', 'date', 'before_or_equal:today'],
            'status'           => ['required', 'array'],
            'status.*'         => ['in:' . implode(',', array_keys(EmployeeAttendance::STATUSES))],
            // Même contrat que le terrain hors-ligne, qui l'acceptait déjà :
            // une heure ou rien, jamais une chaîne libre.
            'check_in_time'    => ['nullable', 'array'],
            'check_in_time.*'  => ['nullable', 'date_format:H:i'],
        ]);

        $date = $data['date'];

        // La règle de pointage vit dans l'Action, partagée avec le terrain
        // hors-ligne (SyncService::attendanceCreate) : une seule vérité.
        $heures = $data['check_in_time'] ?? null;

        $rows = collect($data['status'])
            ->map(function ($status, $employeeId) use ($heures) {
                $ligne = ['employee_id' => (int) $employeeId, 'status' => $status];

                // La clé n'est transmise QUE si la grille l'a envoyée : absente,
                // elle ne vaut pas effacement de ce qui a été relevé ailleurs.
                if ($heures !== null) {
                    $ligne['check_in_time'] = $heures[$employeeId] ?? null;
                }

                return $ligne;
            })
            ->values()->all();

        $result = app(RecordAttendance::class)->execute($date, $rows, Auth::id());

        /*
         * UNE LIGNE ÉCARTÉE NE DOIT PLUS ÊTRE MUETTE.
         *
         * `RecordAttendance` rend un compteur `skipped`, et les deux appelants
         * le jetaient : l'écran annonçait « Présence enregistrée pour N
         * employé(s) » sans dire qu'il en manquait. C'est ce silence qui a rendu
         * invisible le rejet des agents prêtés — la grille se referme, tout a
         * l'air normal, et la ligne n'existe pas.
         *
         * Le périmètre est désormais le bon ; ce message est là pour que la
         * prochaine cause d'écartement se voie tout de suite.
         */
        $message = "Présence enregistrée pour {$result['saved']} employé(s).";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} ligne(s) écartée(s) : agent hors du périmètre de ce site.";
        }

        return redirect()->route('attendance.index', ['date' => $date])
            ->with('success', $message);
    }

    /** Rapport de présence par employé sur une période. */
    public function report(Request $request)
    {
        if (Gate::denies('rh.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);

        /*
         * « Rien n'a été saisi » ne se déduit plus des chiffres du tableau.
         *
         * L'avertissement se déclenchait sur `$rows->sum('total') === 0`, ce qui
         * marchait tant que `total` comptait les LIGNES saisies. Depuis que le
         * dénominateur est le mois, `total` ne vaut jamais zéro sur une période
         * réelle : la garde serait devenue muette sans que rien ne le signale.
         *
         * On dit donc la chose directement — aucun pointage n'existe sur la
         * période — plutôt que de la déduire d'un total qui ne la porte plus.
         */
        $aucunPointage = ! EmployeeAttendance::between($from, $to)->exists();

        return view('attendance.report', [
            'rows'          => $this->buildReport($from, $to),
            'from'          => $from,
            'to'            => $to,
            'aucunPointage' => $aucunPointage,
        ]);
    }

    /** Export CSV du rapport (séparateur « ; » + BOM UTF-8 pour Excel). */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (Gate::denies('rh.L')) {
            abort(403, 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);
        $rows = $this->buildReport($from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
            \App\Support\CsvExport::putRow($out, ['Employé', 'Poste', 'Présent', 'Retard', 'Absent', 'Congé', 'Jours ouvrés', 'Taux présence %'], ';');
            foreach ($rows as $r) {
                \App\Support\CsvExport::putRow($out, [
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
        if (Gate::denies('rh.L')) {
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
        $employees = Employee::assignableInCurrentFarm()->orderBy('first_name')->get();

        $attendance = EmployeeAttendance::between($from, $to)->get()->groupBy('employee_id');

        /*
         * LE DÉNOMINATEUR EST LE MOIS, PAS LE NOMBRE DE LIGNES SAISIES.
         *
         * `$total` valait `array_sum($counts)` — la somme des pointages
         * enregistrés. Le taux mesurait donc le zèle de saisie, pas la présence :
         *
         *   • 10 jours pointés sur 26, tous « présent »      → 100 %
         *   • 26 jours pointés, 24 présents et 2 absents     →  92,3 %
         *
         * Le site qui pointe tous les jours et DÉCLARE ses absences affichait un
         * taux plus bas que celui qui ne pointe qu'une fois sur trois. Un
         * indicateur qui ne peut que monter quand on cesse de l'alimenter ne
         * mesure rien — et c'est ce chiffre que le bureau regarde avant de
         * valider une paie.
         *
         * La paie, elle, ne se pose pas la question : elle compte « jours ouvrés
         * − congés − absences », les jours non pointés étant présumés travaillés.
         * On lui emprunte son dénominateur, `PayrollService::workingDaysBetween()`,
         * bâti sur le réglage `rh.rest_day` : les deux écrans répondent enfin à la
         * même question.
         *
         * Les COLONNES du tableau ne changent pas : elles restent le décompte de
         * ce qui a été saisi. Seuls le total, les jours travaillés et le taux
         * s'expriment désormais en jours du mois.
         */
        $joursOuvres = \App\Services\PayrollService::workingDaysBetween(
            \Carbon\Carbon::parse($from)->startOfDay(),
            \Carbon\Carbon::parse($to)->startOfDay(),
        );

        return $employees->map(function ($emp) use ($attendance, $joursOuvres) {
            $records = $attendance->get($emp->id, collect());

            $counts = [
                'present' => $records->where('status', 'present')->count(),
                'retard'  => $records->where('status', 'retard')->count(),
                'absent'  => $records->where('status', 'absent')->count(),
                'conge'   => $records->where('status', 'conge')->count(),
            ];

            // Un pointage posé un jour de repos ne retire pas une journée due —
            // même règle que la retenue de paie, qui les écarte explicitement.
            $surJourOuvre = fn (string $statut) => $records
                ->where('status', $statut)
                ->reject(fn ($r) => \App\Services\PayrollService::isRestDay(
                    \Carbon\Carbon::parse($r->attendance_date)
                ))
                ->count();

            /*
             * LE RAPPORT SUIT LE MÊME RÉGIME QUE LA PAIE.
             *
             * Un JOURNALIER n'a pas de journée DUE : il a des journées
             * CONSTATÉES. Lui appliquer le dénominateur du mois le faisait
             * afficher « 26 jours travaillés » quand son bulletin en porte cinq
             * — deux écrans du même mois, deux réponses, et c'est ce rapport que
             * le bureau consulte avant de valider la paie.
             *
             * Son total est donc le nombre de journées qu'il a faites, et son
             * taux n'a pas de dénominateur : on ne peut pas lui reprocher un jour
             * qu'on ne lui devait pas. Le vrai renseignement, pour lui, est le
             * NOMBRE — que la colonne porte déjà.
             *
             * Règle unique : cf. Employee::isPresumedPresent().
             */
            if (! $emp->isPresumedPresent()) {
                $faites = $surJourOuvre('present') + $surJourOuvre('retard');

                return [
                    'employee'      => $emp,
                    'counts'        => $counts,
                    'total'         => $faites,
                    'worked'        => $faites,
                    'presence_rate' => 100.0,
                ];
            }

            $conges  = min($surJourOuvre('conge'), $joursOuvres);
            $dus     = max(0, $joursOuvres - $conges);          // jours réellement dus
            $absents = min($surJourOuvre('absent'), $dus);
            $worked  = $dus - $absents;

            return [
                'employee'      => $emp,
                'counts'        => $counts,
                'total'         => $joursOuvres,
                'worked'        => $worked,
                // Un mois entièrement en congé ne doit pas sortir à 0 % : il n'y
                // avait aucune journée due, donc aucun manquement.
                'presence_rate' => $dus > 0 ? round($worked / $dus * 100, 1) : 100.0,
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
