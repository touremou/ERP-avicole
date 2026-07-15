<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Consultation (lecture seule) du journal d'audit : qui a modifié quoi, quand.
 * Réservé à l'administrateur. Le journal lui-même est immuable côté application
 * (aucune route de modification/suppression exposée).
 */
class AuditLogController extends Controller
{
    public function index()
    {
        if (Gate::denies('admin.S')) {
            return redirect()->route('dashboard')->with('error', 'Accès réservé à l\'administrateur.');
        }

        $activities = Activity::with('causer')
            ->where('log_name', 'audit')
            ->latest()
            ->paginate(50);

        return view('audit.index', compact('activities'));
    }
}
