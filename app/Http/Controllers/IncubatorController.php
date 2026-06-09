<?php

namespace App\Http\Controllers;

use App\Models\Incubator;
use App\Models\Incubation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IncubatorController extends Controller
{
    /**
     * Liste des incubateurs avec Statistiques de Performance (Vue L)
     * Optimisation : Calculs par agrégats SQL
     */
    public function index() 
    {
        if (Gate::denies('L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        // On utilise selectRaw pour calculer les stats directement via le moteur SQL (Plus rapide)
        $incubators = Incubator::with(['maintenances'])
            ->withCount(['incubations as total_cycles' => function($q) {
                $q->where('status', 'clos');
            }])
            ->withSum(['incubations as total_produced' => function($q) {
                $q->where('status', 'clos');
            }], 'hatched_chicks')
            ->paginate(10);

        // Injection des moyennes de performance
        $incubators->getCollection()->transform(function($incubator) {
            // Moyenne de réussite historique
            $incubator->avg_performance = DB::table('incubations')
                ->where('incubator_id', $incubator->id)
                ->where('status', 'clos')
                ->avg('hatchability_rate') ?? 0;

            return $incubator;
        });

        return view('incubators.index', compact('incubators'));
    }

    /**
     * Création d'un nouvel actif (Vue C)
     */
    public function store(Request $request) 
    {
        if (Gate::denies('C')) return back()->with('error', 'Action non autorisée.');

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:incubators,name',
            'capacity' => 'required|integer|min:1',
        ]);
        
        $data['status'] = 'Disponible';
        
        Incubator::create($data);
        return back()->with('success', 'Nouvelle unité d\'incubation enregistrée.');
    }

    /**
     * Enregistrement Maintenance & SAV (Vue M)
     */
    public function addMaintenance(Request $request, Incubator $incubator) 
    {
        if (Gate::denies('M')) return back()->with('error', 'Modification de maintenance interdite.');

        $data = $request->validate([
            'maintenance_date' => 'required|date|before_or_equal:today',
            'type'             => 'required|string|in:Désinfection,Étalonnage,Entretien,Réparation', 
            'description'      => 'required|string',
            'performed_by'     => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($incubator, $data) {
            $incubator->maintenances()->create($data);

            // Remise en service automatique après maintenance
            if ($incubator->status === 'Maintenance') {
                $incubator->update(['status' => 'Disponible']);
            }

            return back()->with('success', 'Rapport technique validé. Machine opérationnelle.');
        });
    }
    /**
     * Formulaire d'édition (Vue M)
     */
    public function edit(Incubator $incubator)
    {
        // Vérification des droits (M pour Modification)
        if (Gate::denies('M')) {
            return redirect()->route('incubators.index')->with('error', 'Accès refusé.');
        }

        return view('incubators.edit', compact('incubator'));
    }

    /**
     * Mise à jour technique (Vue M)
     */
    public function update(Request $request, Incubator $incubator)
    {
        if (Gate::denies('M')) return back()->with('error', 'Action non autorisée.');

        $isBusy = $incubator->incubations()->where('status', '!=', 'clos')->exists();

        $data = $request->validate([
            'name'     => 'required|string|max:255|unique:incubators,name,'.$incubator->id,
            'capacity' => 'required|integer|min:1',
            'status'   => 'required|in:Disponible,Occupé,Maintenance,Panne',
        ]);

        // Verrou de sécurité : on ne réduit pas la capacité si un lot est dedans
        if ($isBusy && $request->capacity < $incubator->capacity) {
            return back()->with('error', '⚠️ ALERTE : Impossible de réduire la capacité alors qu\'une incubation est en cours.');
        }

        $incubator->update($data);

        return redirect()->route('incubators.index')->with('success', 'Configuration machine mise à jour.');
    }

    /**
     * Suppression (Vue S)
     */
    public function destroy(Incubator $incubator) 
    {
        if (Gate::denies('S')) return back()->with('error', 'Suppression réservée à l\'administrateur.');

        if ($incubator->incubations()->where('status', '!=', 'clos')->exists()) {
            return back()->with('error', '🛑 ERREUR : Cette machine est actuellement en cycle de production.');
        }

        $incubator->delete();
        return back()->with('success', 'L\'unité a été retirée du parc industriel.');
    }
}