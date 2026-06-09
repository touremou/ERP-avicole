<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Provider;
use App\Models\Employee;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TrashController extends Controller
{
    /**
     * CENTRALISATION DE LA CORBEILLE (Vue L)
     */
    public function index()
    {
        if (Gate::denies('admin.S')) {
            return redirect()->route('dashboard')->with('error', 'Accès réservé aux administrateurs (Grade S).');
        }

        $buildings = Building::onlyTrashed()->get();
        $providers = Provider::onlyTrashed()->get();
        $employees = Employee::onlyTrashed()->get();
        // Optionnel : Ajouter les Lots (Batches) archivés
        $batches   = Batch::onlyTrashed()->with('building')->get();

        return view('trash.index', compact('buildings', 'providers', 'employees', 'batches'));
    }

    /**
     * RESTAURATION D'UN ÉLÉMENT
     */
    public function restore($type, $id)
    {
        if (Gate::denies('admin.S')) return back();

        $model = $this->getModel($type);
        $item = $model::onlyTrashed()->findOrFail($id);
        
        $item->restore();

        return back()->with('success', "Réintégration réussie : l'élément est de nouveau actif.");
    }

    /**
     * SUPPRESSION DÉFINITIVE (Nettoyage physique)
     * Rigueur : Vérifier une dernière fois l'absence de liens vitaux.
     */
    public function forceDelete($type, $id)
    {
        if (Gate::denies('admin.S')) return back();

        $model = $this->getModel($type);
        $item = $model::onlyTrashed()->findOrFail($id);
        
        // Sécurité : On empêche la suppression physique si l'élément a laissé des traces (ex: factures, pointages)
        // Note: C'est ici que l'on pourrait vérifier des relations complexes avant le point de non-retour.
        
        $item->forceDelete(); 

        return back()->with('success', "Suppression irréversible effectuée.");
    }

    /**
     * VIDAGE TOTAL (Maintenance système)
     */
    public function clearAll()
    {
        if (Gate::denies('admin.S')) return back();

        Employee::onlyTrashed()->forceDelete();
        Building::onlyTrashed()->forceDelete();
        Provider::onlyTrashed()->forceDelete();
        Batch::onlyTrashed()->forceDelete();

        return redirect()->route('trash.index')->with('success', "La base de données a été nettoyée.");
    }

    /**
     * MAPPING SÉCURISÉ DES MODÈLES
     */
    private function getModel($type)
    {
        return match($type) {
            'building' => Building::class,
            'provider' => Provider::class,
            'employee' => Employee::class,
            'batch'    => Batch::class,
            default    => abort(404, "Type d'archive inconnu."),
        };
    }
}