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

        $species = Species::with(['productionTypes', 'breeds' => fn ($q) => $q->withCount('batches')])
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
     * Corriger la durée d'incubation d'une espèce.
     *
     * La durée vivait codée en dur dans un contrôleur : la ferme ne pouvait donc
     * rien corriger, alors que les valeurs varient réellement (un canard de
     * Barbarie incube 35 jours quand un canard commun en demande 28). Vider le
     * champ fait retomber sur le réglage `couvoir.incubation_days`, ce qui est un
     * choix explicite et non un oubli.
     */
    public function updateIncubation(Request $request, Species $species)
    {
        if (Gate::denies('admin.M')) abort(403);

        $data = $request->validate([
            // Bornes larges mais physiques : 10 jours est en dessous de tout
            // oiseau connu, 60 au-dessus. Elles arrêtent la faute de frappe, pas
            // l'espèce inhabituelle.
            'incubation_days' => 'nullable|integer|min:10|max:60',
        ]);

        $species->update(['incubation_days' => $data['incubation_days'] ?? null]);

        return back()->with('success', $data['incubation_days']
            ? "Incubation de « {$species->name_fr} » : {$data['incubation_days']} jours."
            : "Durée d'incubation de « {$species->name_fr} » remise au réglage de la ferme.");
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

    // ─────────────────────────────────────────────────────────────
    // TAXONOMIE ANIMALE — espèces et souches paramétrées par l'administrateur.
    //
    // Demande de l'exploitation : impossible d'ajouter des moutons « locaux »
    // ou une souche de volaille non prévue. Les espèces n'étaient
    // qu'activables ; les souches n'existaient que dans les courbes de
    // référence. L'administrateur crée désormais les unes et les autres ; le
    // terrain les reçoit à la synchronisation (référentiel `animal_breeds`).
    // ─────────────────────────────────────────────────────────────

    /** Familles proposées pour une nouvelle espèce (celles de l'écran). */
    public const FAMILLES = ['volaille', 'petit_ruminant', 'grand_ruminant', 'aquaculture', 'porcin', 'lagomorphe', 'autre'];

    public function storeSpecies(Request $request)
    {
        if (Gate::denies('admin.S')) abort(403);

        $data = $request->validate([
            'name_fr'          => 'required|string|max:100',
            'local_name'       => 'nullable|string|max:100',
            'family'           => ['required', \Illuminate\Validation\Rule::in(self::FAMILLES)],
            'icon'             => 'nullable|string|max:10',
            'unit_label'       => 'nullable|string|max:30',
            'habitat_label'    => 'nullable|string|max:50',
            'production_type'  => 'required|string|max:100',
            'cycle_days'       => 'required|integer|min:1|max:3650',
        ]);

        $slug = \Illuminate\Support\Str::slug($data['name_fr']);
        if ($slug === '' || Species::where('slug', $slug)->exists()) {
            return back()->withInput()->with('error', "Une espèce « {$data['name_fr']} » existe déjà.");
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $slug) {
            $espece = Species::create([
                'slug' => $slug, 'name_fr' => $data['name_fr'], 'local_name' => $data['local_name'] ?? null,
                'family' => $data['family'], 'icon' => ($data['icon'] ?? null) ?: '🐾', 'color' => 'slate',
                'unit_label' => ($data['unit_label'] ?? null) ?: 'Tête', 'habitat_label' => ($data['habitat_label'] ?? null) ?: 'Bâtiment',
                'tracks_eggs' => false, 'tracks_milk' => false, 'tracks_water_quality' => $data['family'] === 'aquaculture',
                'is_active' => true, 'sort_order' => (int) Species::max('sort_order') + 1,
            ]);

            // Un lot exige un type de production : la nouvelle espèce en reçoit un.
            \App\Models\ProductionType::create([
                'species_id' => $espece->id, 'slug' => \Illuminate\Support\Str::slug($data['production_type']) ?: 'elevage',
                'name_fr' => $data['production_type'], 'kpi_primary' => 'gmq', 'is_active' => true,
                'cycle_days_default' => $data['cycle_days'],
                'metrics_enabled' => ['mortality' => true, 'feed' => true, 'weight' => true, 'eggs' => false,
                    'milk' => false, 'water_quality' => $data['family'] === 'aquaculture', 'born' => false, 'weaned' => false],
            ]);
        });

        return back()->with('success', "Espèce « {$data['name_fr']} » créée. Ajoutez-lui ses souches ou races.");
    }

    public function updateSpecies(Request $request, Species $species)
    {
        if (Gate::denies('admin.S')) abort(403);

        $data = $request->validate([
            'name_fr'       => 'required|string|max:100',
            'local_name'    => 'nullable|string|max:100',
            'icon'          => 'nullable|string|max:10',
            'unit_label'    => 'nullable|string|max:30',
            'habitat_label' => 'nullable|string|max:50',
        ]);

        // Le slug ne change pas : des règles de l'application s'y rattachent.
        $species->update(array_filter($data, fn ($v) => $v !== null) + ['local_name' => $data['local_name'] ?? null]);

        return back()->with('success', "Espèce « {$species->name_fr} » mise à jour.");
    }

    public function storeBreed(Request $request, Species $species)
    {
        if (Gate::denies('admin.S')) abort(403);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('animal_breeds', 'name')->where('species_id', $species->id)],
            'description' => 'nullable|string|max:255',
        ], ['name.unique' => "Cette souche existe déjà pour {$species->name_fr}."]);

        $species->breeds()->create($data + ['is_active' => true]);

        return back()->with('success', "Souche « {$data['name']} » ajoutée à {$species->name_fr}.");
    }

    public function updateBreed(Request $request, \App\Models\AnimalBreed $breed)
    {
        if (Gate::denies('admin.S')) abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('animal_breeds', 'name')
                    ->where('species_id', $breed->species_id)->ignore($breed->id)],
            // Durée du cycle de la souche : règle le stade des lots (Batch::current_phase).
            'cycle_days' => 'nullable|integer|min:1|max:3650',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($breed, $data) {
            // Les lots gardent le NOM de leur souche (courbes de référence) :
            // un renommage les suit.
            $breed->batches()->update(['model_name' => $data['name']]);
            $breed->update(['name' => $data['name'], 'cycle_days' => $data['cycle_days'] ?? null]);
        });

        return back()->with('success', "Souche « {$data['name']} » mise à jour.");
    }

    public function toggleBreed(\App\Models\AnimalBreed $breed)
    {
        if (Gate::denies('admin.S')) abort(403);

        // Désactivée : plus proposée pour un NOUVEAU lot ; les lots existants la gardent.
        $breed->update(['is_active' => ! $breed->is_active]);

        return back()->with('success', "Souche « {$breed->name} » " . ($breed->is_active ? 'réactivée' : 'désactivée') . '.');
    }

    public function destroyBreed(\App\Models\AnimalBreed $breed)
    {
        if (Gate::denies('admin.S')) abort(403);

        if ($breed->batches()->exists()) {
            return back()->with('error', "« {$breed->name} » est la souche de lots existants : désactivez-la plutôt (elle reste dans leur historique).");
        }

        $breed->delete();

        return back()->with('success', "Souche « {$breed->name} » supprimée.");
    }
}
