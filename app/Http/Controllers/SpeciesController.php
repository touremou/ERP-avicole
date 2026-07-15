<?php
namespace App\Http\Controllers;

use App\Models\Species;
use App\Models\ProductionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SpeciesController extends Controller
{
    public function index()
    {
        if (Gate::denies('admin.S')) return redirect()->route('dashboard')->with('error', 'Accès réservé aux super-admins.');

        $species = Species::with('productionTypes')
            ->withCount('batches')   // pour n'autoriser la suppression que si 0 lot
            ->orderBy('sort_order')
            ->get()
            ->groupBy('family');

        $families = [
            'volaille'       => ['label' => 'Volailles',         'icon' => '🐔', 'color' => 'amber'],
            'petit_ruminant' => ['label' => 'Petits Ruminants',  'icon' => '🐑', 'color' => 'sky'],
            'grand_ruminant' => ['label' => 'Grands Ruminants',  'icon' => '🐄', 'color' => 'green'],
            'aquaculture'    => ['label' => 'Pisciculture',       'icon' => '🐟', 'color' => 'blue'],
            'porcin'         => ['label' => 'Porcins',            'icon' => '🐷', 'color' => 'pink'],
            'lagomorphe'     => ['label' => 'Lapins',             'icon' => '🐇', 'color' => 'rose'],
            'autre'          => ['label' => 'Autres',             'icon' => '🐾', 'color' => 'slate'],
        ];

        return view('admin.species.index', compact('species', 'families'));
    }

    public function toggle(Species $species)
    {
        if (Gate::denies('admin.S')) abort(403);

        // Garde métier : on ne désactive pas une espèce encore EN PRODUCTION.
        // Désactiver la masque des sélecteurs (création de lot, normes, POS…) —
        // laisser des lots actifs orphelins d'une espèce cachée casse les KPI,
        // le pointage et l'abattage. Même esprit que Plot::isOccupied côté
        // cultures : on protège la donnée vivante.
        if ($species->is_active) {
            $activeBatches = $species->batches()->active()->count();
            if ($activeBatches > 0) {
                return back()->with('error',
                    "Impossible de désactiver « {$species->name_fr} » : {$activeBatches} lot(s) encore actif(s). "
                    . "Terminez ou transférez ces lots avant de désactiver l'espèce."
                );
            }
        }

        $species->update(['is_active' => ! $species->is_active]);

        $status = $species->is_active ? 'activée' : 'désactivée';
        return back()->with('success', "Espèce « {$species->name_fr} » {$status} sur ce site.");
    }

    /**
     * Suppression définitive d'une espèce — réservée aux cas de MÉNAGE :
     * ligne orpheline (créée à la main / reliquat d'un ancien prototype) sans
     * aucun lot. Toute espèce référencée par au moins un lot (même terminé)
     * est protégée : la supprimer nierait l'historique et casserait les clés
     * étrangères. Ses types de production partent avec elle (cascade).
     */
    public function destroy(Species $species)
    {
        if (Gate::denies('admin.S')) abort(403);

        if ($species->batches()->exists()) {
            return back()->with('error',
                "Impossible de supprimer « {$species->name_fr} » : des lots y sont rattachés. "
                . "Une espèce utilisée se désactive (elle reste dans l'historique), elle ne se supprime pas."
            );
        }

        $name = $species->name_fr;
        $species->productionTypes()->delete();
        $species->delete();

        return back()->with('success', "Espèce « {$name} » supprimée (aucun lot n'y était rattaché).");
    }

    public function productionTypesForSpecies(Species $species)
    {
        // Endpoint JSON pour le sélecteur dynamique dans batch create/edit
        return response()->json(
            $species->productionTypes()->active()->get(['id','slug','name_fr','cycle_days_default','kpi_primary'])
        );
    }
}
