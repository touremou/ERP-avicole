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

        $species->update(['is_active' => ! $species->is_active]);

        $status = $species->is_active ? 'activée' : 'désactivée';
        return back()->with('success', "Espèce « {$species->name_fr} » {$status} sur ce site.");
    }

    public function productionTypesForSpecies(Species $species)
    {
        // Endpoint JSON pour le sélecteur dynamique dans batch create/edit
        return response()->json(
            $species->productionTypes()->active()->get(['id','slug','name_fr','cycle_days_default','kpi_primary'])
        );
    }
}
