<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * MUTATIONS ET MISES À DISPOSITION.
 *
 * Le « prêt » n'avait jamais été décidé : il se déduisait du droit d'accès donné
 * au COMPTE d'un agent. Personne ne pouvait donc dire depuis quand, jusqu'à
 * quand, ni pourquoi — et chaque écran redécouvrait la règle à sa façon.
 *
 * Ici, on la déclare.
 */
class EmployeeAssignmentController extends Controller
{
    /** Muter : le dossier change de site, donc la paie suit. */
    public function transfer(Request $request, Employee $employee)
    {
        if (Gate::denies('rh.S')) {
            return back()->with('error', "Seule la direction RH peut muter un agent : la paie change de site.");
        }

        $data = $request->validate([
            'farm_id'    => ['required', 'integer', Rule::exists('farms', 'id')],
            'start_date' => ['required', 'date'],
            'reason'     => ['nullable', 'string', 'max:255'],
        ]);

        if ((int) $data['farm_id'] === (int) $employee->farm_id) {
            return back()->with('error', "{$employee->first_name} est déjà rattaché à ce site.");
        }

        $farm = Farm::findOrFail($data['farm_id']);

        $employee->transferTo(
            (int) $data['farm_id'],
            $data['start_date'],
            $data['reason'] ?? null,
            Auth::id(),
        );

        return back()->with('success',
            "{$employee->first_name} {$employee->last_name} est muté à {$farm->name} "
            . "depuis le " . \Illuminate\Support\Carbon::parse($data['start_date'])->format('d/m/Y')
            . '. Sa paie relève désormais de ce site.'
        );
    }

    /** Mettre à disposition : il travaille ailleurs, son dossier ne bouge pas. */
    public function lend(Request $request, Employee $employee)
    {
        if (Gate::denies('rh.M')) {
            return back()->with('error', 'Non autorisé.');
        }

        $data = $request->validate([
            'farm_id'    => ['required', 'integer', Rule::exists('farms', 'id')],
            'start_date' => ['required', 'date'],
            // Un prêt SANS terme devient une mutation de fait que personne n'a
            // décidée — exactement ce qui s'était produit avec les accès de
            // compte. Le terme est donc exigé ; il reste prolongeable.
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'reason'     => ['nullable', 'string', 'max:255'],
        ]);

        if ((int) $data['farm_id'] === (int) $employee->farm_id) {
            return back()->with('error', "Inutile : son dossier est déjà sur ce site.");
        }

        $farm = Farm::findOrFail($data['farm_id']);

        $employee->lendTo(
            (int) $data['farm_id'],
            $data['start_date'],
            $data['end_date'],
            $data['reason'] ?? null,
            Auth::id(),
        );

        return back()->with('success',
            "{$employee->first_name} est mis à disposition de {$farm->name} jusqu'au "
            . \Illuminate\Support\Carbon::parse($data['end_date'])->format('d/m/Y')
            . ". Sa paie reste à son site d'origine."
        );
    }

    /**
     * Clore une affectation aujourd'hui.
     *
     * Sert surtout aux mises à disposition REPRISES de l'ancien fonctionnement :
     * elles n'ont jamais été décidées, et le promoteur doit pouvoir écarter
     * celles qui ne correspondent à rien.
     */
    public function end(EmployeeAssignment $assignment)
    {
        if (Gate::denies('rh.M')) {
            return back()->with('error', 'Non autorisé.');
        }

        $employee = $assignment->employee;

        // Borné à ce que ce site voit : sans ce contrôle, un identifiant deviné
        // permettrait de mettre fin à l'affectation d'un agent d'un autre site.
        if (! $employee || ! Employee::visibleInCurrentFarm()->whereKey($employee->id)->exists()) {
            return back()->with('error', "Cette affectation ne concerne pas cette ferme.");
        }

        // Clore le rattachement PRINCIPAL laisserait l'agent sans site : il
        // disparaîtrait de tous les écrans sans que rien ne l'explique. Pour
        // quitter un site, on mute ; pour quitter l'entreprise, on archive.
        if ($assignment->type === 'mutation') {
            return back()->with('error',
                "Le rattachement principal ne se clôt pas : mutez l'agent vers un autre site, "
                . "ou archivez son dossier s'il quitte l'exploitation."
            );
        }

        if ($assignment->end_date && $assignment->end_date->isPast()) {
            return back()->with('error', 'Cette mise à disposition est déjà terminée.');
        }

        $assignment->update(['end_date' => today()]);

        return back()->with('success',
            "Mise à disposition de {$employee->first_name} close aujourd'hui."
        );
    }
}
