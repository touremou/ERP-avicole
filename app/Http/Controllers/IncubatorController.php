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
        if (Gate::denies('production.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        // On utilise selectRaw pour calculer les stats directement via le moteur SQL (Plus rapide)
        $incubators = Incubator::with(['maintenances'])
            ->withCount(['incubations as total_cycles' => function($q) {
                $q->where('status', 'clos');
            }])
            ->withSum(['incubations as total_produced' => function($q) {
                $q->where('status', 'clos');
            }], 'hatched_chicks')
            ->paginate((int) setting('general.items_per_page', 20));

        /*
         * LA FIABILITÉ D'UNE MACHINE SE LIT PAR L'ACCESSEUR — PAS EN SQL.
         *
         * Ce bloc calculait `avg_performance` par un `avg('hatchability_rate')`
         * de constructeur de requête : une moyenne SQL sur une COLONNE QUE
         * PERSONNE N'ÉCRIT. `RecordHatching` croyait la remplir, mais elle est
         * absente du `$fillable` d'`Incubation` et l'assignation était jetée en
         * silence. La colonne vaut NULL pour tous les cycles de l'historique.
         *
         * Mesuré : cycle miré à 800 fertiles sur 900, éclos à 700 poussins.
         * L'écran Couvoir affiche « Taux Éclosion 87,5 % » — il lit l'accesseur.
         * Cet écran-ci, même machine, même cycle, affichait « Fiabilité 0 % ».
         *
         * Tous les autres lecteurs du taux moyennent une COLLECTION, donc
         * passent par l'accesseur : `Incubator::global_success_rate`,
         * `IncubationController` (machineStats, avg_fertility, avg_reussite),
         * les vues, les journaux de synchro. Ce `avg()` en base était le seul à
         * lire la colonne — et le seul à se tromper.
         *
         * Le correctif précédent sur ces trois lignes avait déjà déplacé le
         * calcul de `DB::table()` vers le modèle, pour respecter la suppression
         * douce. Il visait juste sur les LIGNES retenues et laissait intacte la
         * colonne vide qu'il moyennait.
         *
         * `Incubator::global_success_rate` porte exactement cette règle, et son
         * `$this->incubations()` — relation Eloquent sur un modèle SoftDeletes —
         * écarte bien les cycles supprimés, comme le voulait ce correctif. La
         * vue le lit désormais directement : une déclaration, un lecteur.
         */
        return view('incubators.index', compact('incubators'));
    }

    /**
     * Création d'un nouvel actif (Vue C)
     */
    public function store(Request $request) 
    {
        if (Gate::denies('production.C')) return back()->with('error', 'Action non autorisée.');

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
        if (Gate::denies('production.M')) return back()->with('error', 'Modification de maintenance interdite.');

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
        if (Gate::denies('production.M')) {
            return redirect()->route('incubators.index')->with('error', 'Accès refusé.');
        }

        return view('incubators.edit', compact('incubator'));
    }

    /**
     * Mise à jour technique (Vue M)
     */
    public function update(Request $request, Incubator $incubator)
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Action non autorisée.');

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
        if (Gate::denies('production.S')) return back()->with('error', 'Suppression réservée à l\'administrateur.');

        if ($incubator->incubations()->where('status', '!=', 'clos')->exists()) {
            return back()->with('error', '🛑 ERREUR : Cette machine est actuellement en cycle de production.');
        }

        $incubator->delete();
        return back()->with('success', 'L\'unité a été retirée du parc industriel.');
    }
}