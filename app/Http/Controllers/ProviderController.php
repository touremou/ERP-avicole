<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Http\Requests\Provider\StoreProviderRequest;
use App\Http\Requests\Provider\UpdateProviderRequest;
use App\Actions\Provider\CreateProvider;
use App\Actions\Provider\UpdateProvider;
use App\Actions\Provider\DeleteProvider;
use App\Actions\Provider\ToggleProviderBlacklist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ProviderController extends Controller
{
    /**
     * Liste des partenaires (Vue L)
     */
    public function index(Request $request)
    {
        if (Gate::denies('annuaire.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        // Filtre de statut : par défaut, seuls les partenaires actifs sont
        // affichés. 'all' liste tout (y compris blacklistés/inactifs), pour
        // permettre leur gestion/réactivation depuis l'annuaire.
        $status = $request->query('status', 'Actif');

        $query = Provider::orderBy('name', 'asc');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $providers = $query->get();

        return view('providers.index', compact('providers', 'status'));
    }

    /**
     * Affichage du formulaire de création (Vue C)
     */
    public function create() 
    {
        if (Gate::denies('annuaire.C')) return back()->with('error', 'Privilèges insuffisants.');
        return view('providers.create');
    }

    /**
     * Enregistrement d'un nouveau partenaire (Vue C)
     */
    public function store(StoreProviderRequest $request, CreateProvider $action) 
    {
        if (Gate::denies('annuaire.C')) return back()->with('error', 'Privilèges insuffisants.');
        try {
            $provider = $action->execute($request->validated(), $request->file('logo'));
            return redirect()->route('providers.index')->with('success', "Partenaire {$provider->name} enregistré avec succès.");
        } catch (\Exception $e) {
            Log::error("Erreur création fournisseur: " . $e->getMessage());
            return back()->withErrors(['error' => 'Erreur technique lors de la création.'])->withInput();
        }
    }

    /**
     * Fiche détaillée (Vue L)
     */
    public function show($id)
    {
        // AJOUT: La vérification de Gate L manquait dans l'ancien code
        if (Gate::denies('annuaire.L')) return back()->with('error', 'Accès restreint.');

        // On n'expose que les lots RÉELS du partenaire ; les lots virtuels
        // de traçabilité (œufs externes en transit, effectif nul) ne sont ni
        // comptés ni listés, et ne doivent pas verrouiller l'archivage.
        $provider = Provider::with(['batches' => fn ($q) => $q->live()])->findOrFail($id);
        return view('providers.show', compact('provider'));
    }

    /**
     * Affichage du formulaire d'édition (Vue M)
     */
    public function edit($id)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Modification interdite au grade actuel.');
        
        $provider = Provider::findOrFail($id);
        return view('providers.edit', compact('provider'));
    }

    /**
     * Mise à jour de la fiche partenaire (Vue M)
     */
    public function update(UpdateProviderRequest $request, Provider $provider, UpdateProvider $action)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Modification interdite permission actuel.');
        try {
            $action->execute($provider, $request->validated(), $request->file('logo'));
            return redirect()->route('providers.index')->with('success', 'Fiche fournisseur mise à jour avec succès.');
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour fournisseur: " . $e->getMessage());
            return back()->withErrors(['error' => 'Erreur technique lors de la mise à jour.'])->withInput();
        }
    }

    /**
     * Gestion de la Liste Noire (Vue M)
     */
    public function blacklist(Request $request, Provider $provider, ToggleProviderBlacklist $action)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Action non autorisée.');

        try {
            $isBlacklisting = !($request->status === 'Actif');
            $action->execute($provider, $isBlacklisting);

            $message = $isBlacklisting ? "Partenaire placé sur liste noire." : "Partenaire réactivé avec succès.";
            return redirect()->route('providers.show', $provider->id)->with('success', $message);
        } catch (\Exception $e) {
            Log::error("Erreur blacklist fournisseur: " . $e->getMessage());
            return back()->with('error', 'Une erreur est survenue.');
        }
    }

    /**
     * Désactivation / Archivage (Vue S)
     */
    public function destroy(Provider $provider, DeleteProvider $action)
    {
        if (Gate::denies('annuaire.S')) return back()->with('error', 'Suppression réservée à l\'administrateur.');

        try {
            $action->execute($provider);
            return redirect()->route('providers.index')->with('success', 'Partenaire archivé avec succès.');
        } catch (\Exception $e) {
            return back()->with('error', '🛑 IMPOSSIBLE : ' . $e->getMessage());
        }
    }
}