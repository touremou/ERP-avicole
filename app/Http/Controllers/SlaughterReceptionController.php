<?php

namespace App\Http\Controllers;

use App\Actions\Slaughter\RecordSlaughterReception;
use App\Models\Provider;
use App\Models\SlaughterReception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Réception du vif (CCP 1) — contrôle ante-mortem à l'arrivée des volailles.
 * REGISTRE IMMUABLE (RG-06) : création uniquement, aucune route
 * edit/update/destroy — l'enregistrement est validé à la création.
 */
class SlaughterReceptionController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $receptions = SlaughterReception::with(['provider', 'controller'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('reception_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('reception_date', '<=', $request->input('to')))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->input('provider_id')))
            ->when($request->filled('decision'), fn ($q) => $q->where('decision', $request->input('decision')))
            ->latest('reception_date')->latest('id')
            ->paginate((int) setting('general.items_per_page', 15))
            ->withQueryString();

        $providers = Provider::orderBy('name')->get();

        return view('slaughter.receptions.index', compact('receptions', 'providers'));
    }

    public function create()
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $providers = Provider::active()->orderBy('name')->get();

        return view('slaughter.receptions.create', compact('providers'));
    }

    public function store(Request $request)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'provider_id'          => 'required|integer|exists:providers,id',
            'reception_date'       => 'required|date|before_or_equal:today',
            'announced_quantity'   => 'nullable|integer|min:0',
            'received_quantity'    => 'required|integer|min:1',
            'rejected_quantity'    => 'nullable|integer|min:0|lte:received_quantity',
            'total_live_weight_kg' => 'required|numeric|min:0.1',
            'sanitary_state'       => 'required|in:' . implode(',', SlaughterReception::SANITARY_STATES),
            'fasting_respected'    => 'required|in:' . implode(',', SlaughterReception::FASTING),
            'decision'             => 'required|in:' . implode(',', SlaughterReception::DECISIONS),
            'decision_reason'      => 'required_unless:decision,accepte|nullable|string|max:1000',
            'origin'               => 'nullable|in:' . implode(',', SlaughterReception::ORIGINS),
            'purchase_basis'       => 'nullable|in:' . implode(',', array_keys(SlaughterReception::PURCHASE_BASES)),
            'purchase_unit_price'  => 'nullable|numeric|min:0',
            'photo'                => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ], [
            'decision_reason.required_unless' => __('Le motif est obligatoire lorsque la décision n\'est pas « Accepté ».'),
        ]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('receptions', 'public')
            : null;

        $reception = app(RecordSlaughterReception::class)->execute([
            'provider_id'          => $validated['provider_id'],
            'origin'               => $validated['origin'] ?? 'achat',
            'reception_date'       => $validated['reception_date'],
            'announced_quantity'   => $validated['announced_quantity'] ?? null,
            'received_quantity'    => $validated['received_quantity'],
            'rejected_quantity'    => $validated['rejected_quantity'] ?? 0,
            'total_live_weight_kg' => $validated['total_live_weight_kg'],
            'sanitary_state'       => $validated['sanitary_state'],
            'fasting_respected'    => $validated['fasting_respected'],
            'decision'             => $validated['decision'],
            'decision_reason'      => $validated['decision_reason'] ?? null,
            'purchase_basis'       => $validated['purchase_basis'] ?? null,
            'purchase_unit_price'  => $validated['purchase_unit_price'] ?? null,
            'doc_photo_path'       => $photoPath,
            'controller_id'        => Auth::id(),
            'arrived_at'           => now(),
            'synced_at'            => now(),
        ]);

        return redirect()->route('slaughter.receptions.index')
            ->with('success', __('Réception vif enregistrée — décision : :decision.', [
                'decision' => str_replace('_', ' ', $reception->decision),
            ]));
    }
}
