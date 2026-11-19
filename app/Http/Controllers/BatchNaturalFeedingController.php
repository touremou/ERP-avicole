<?php

namespace App\Http\Controllers;

use App\Actions\Batch\RecordGrazing;
use App\Actions\Batch\RecordRawFeeding;
use App\Models\Batch;
use App\Models\GrazingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * ALIMENTATION NATURELLE AU BUREAU — alimentation brute et pâturage.
 *
 * La saisie n'existait qu'au terrain : un chef d'élevage qui reprenait le
 * cahier du berger le soir n'avait aucune porte. Mêmes actions que la
 * synchronisation (RecordRawFeeding, RecordGrazing) — mêmes règles, même
 * imputation du temps au lot.
 */
class BatchNaturalFeedingController extends Controller
{
    public function storeRawFeeding(Request $request, Batch $batch, RecordRawFeeding $action): RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', __('Action non autorisée.'));
        }

        $donnees = $request->validate([
            'fed_at'        => 'required|date|before_or_equal:now',
            'resource'      => 'required|string|max:120',
            'quantity'      => 'nullable|numeric|min:0|max:100000',
            'quantity_unit' => 'nullable|string|in:' . implode(',', \App\Models\RawFeeding::UNITES),
            'minutes_spent' => 'required|integer|min:1|max:720',
            'plot_id'       => ['nullable', 'integer', \App\Support\Ferme::existe('plots')],
            'notes'         => 'nullable|string|max:500',
        ]);

        $action->execute($donnees + ['batch_id' => $batch->id], Auth::user());

        return back()->with('success', __('Distribution enregistrée — le temps passé est imputé au lot.'));
    }

    public function storeGrazing(Request $request, Batch $batch, RecordGrazing $action): RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', __('Action non autorisée.'));
        }

        $donnees = $request->validate([
            'grazed_on'      => 'required|date|before_or_equal:today',
            'left_time'      => 'required|date_format:H:i',
            'returned_time'  => 'required|date_format:H:i',
            'mode'           => 'required|in:' . implode(',', array_keys(GrazingSession::MODES)),
            'heads_out'      => 'required|integer|min:1',
            'heads_back'     => 'required|integer|min:0',
            'herder_minutes' => 'nullable|integer|min:0|max:960',
            'plot_id'        => ['nullable', 'integer', \App\Support\Ferme::existe('plots')],
            'zone'           => 'nullable|string|max:120',
            'notes'          => 'nullable|string|max:500',
        ]);

        $sortie = $action->execute([
            'batch_id'       => $batch->id,
            'left_at'        => "{$donnees['grazed_on']} {$donnees['left_time']}",
            'returned_at'    => "{$donnees['grazed_on']} {$donnees['returned_time']}",
            'mode'           => $donnees['mode'],
            'heads_out'      => $donnees['heads_out'],
            'heads_back'     => $donnees['heads_back'],
            'herder_minutes' => $donnees['herder_minutes'] ?? null,
            'plot_id'        => $donnees['plot_id'] ?? null,
            'zone'           => $donnees['zone'] ?? null,
            'notes'          => $donnees['notes'] ?? null,
        ], Auth::user());

        return back()->with($sortie->manquants > 0 ? 'warning' : 'success', $sortie->manquants > 0
            ? __(':n tête(s) manquante(s) au retour — l’élevage est prévenu. À retrouver ou à déclarer.', ['n' => $sortie->manquants])
            : __('Sortie au pâturage enregistrée.'));
    }
}
