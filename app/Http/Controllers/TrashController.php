<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Provider;
use App\Models\Employee;
use App\Models\Batch;
use Illuminate\Http\Request;
use App\Support\DependencyGuard;
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

        /*
         * LA GARDE QUE CE FICHIER ANNONÇAIT SANS LA FAIRE.
         *
         * Le commentaire d'origine disait : « On empêche la suppression physique
         * si l'élément a laissé des traces (ex : factures, pointages) » — et la
         * ligne suivante avouait qu'on POURRAIT le vérifier. Entre les deux,
         * `forceDelete()` partait sans rien contrôler.
         *
         * Or les clés étrangères de cette base sont en CASCADE : supprimer un lot
         * archivé emporte ses pointages, ses soins, ses collectes d'œufs et ses
         * achats d'aliment ; supprimer un employé archivé emporte ses bulletins,
         * ses présences — et SES LOTS, donc tout ce qui précède. Un clic pouvait
         * effacer des années d'élevage en annonçant « effectuée ».
         *
         * On refuse désormais, et on NOMME ce qui retient : un refus qui ne dit
         * pas pourquoi pousse à chercher un autre moyen de supprimer.
         */
        $blockers = DependencyGuard::blockers($item);

        if ($blockers !== []) {
            return back()->with('error',
                "Suppression définitive refusée : cet élément est encore lié à "
                . DependencyGuard::describe($blockers)
                . ". Ces enregistrements seraient détruits avec lui. Il reste archivé."
            );
        }

        $item->forceDelete();

        return back()->with('success', "Suppression irréversible effectuée.");
    }

    /**
     * VIDAGE TOTAL (Maintenance système)
     */
    public function clearAll()
    {
        if (Gate::denies('admin.S')) return back();

        /*
         * LE MÊME GESTE, EN MASSE — donc la MÊME règle.
         *
         * Cette méthode passait un `forceDelete()` sur TOUTE la corbeille, en
         * quatre lignes, et annonçait « La base de données a été nettoyée ».
         * Avec des clés étrangères en cascade, c'était l'effacement possible de
         * l'historique de plusieurs bandes et de plusieurs années de paie, d'un
         * seul clic et sans un mot sur ce qui partait.
         *
         * On supprime donc un par un, en épargnant ce qui a laissé des traces, et
         * on RESTITUE le compte des deux côtés : ce qui est parti, ce qui est
         * resté. Un « nettoyage » muet ne dit pas à l'administrateur qu'il vient
         * de conserver — ou de détruire — quoi que ce soit.
         */
        $supprimes = 0;
        $conserves = 0;

        foreach ([Employee::class, Building::class, Provider::class, Batch::class] as $model) {
            foreach ($model::onlyTrashed()->get() as $item) {
                if (DependencyGuard::blockers($item) !== []) {
                    $conserves++;
                    continue;
                }

                $item->forceDelete();
                $supprimes++;
            }
        }

        $message = "{$supprimes} élément(s) supprimé(s) définitivement.";

        if ($conserves > 0) {
            $message .= " {$conserves} conservé(s) : ils portent encore des enregistrements"
                . " (pointages, paie, production…) qui auraient été détruits avec eux.";
        }

        return redirect()->route('trash.index')->with('success', $message);
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