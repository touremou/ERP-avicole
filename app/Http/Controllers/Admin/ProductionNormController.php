<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionNormController extends Controller
{
    /**
     * Métadonnées d'affichage (icône, couleur, libellé) par type de production.
     */
    public const TYPE_META = [
        'chair'         => ['label' => 'Chair',         'icon' => '🍗', 'color' => 'orange'],
        'ponte'         => ['label' => 'Ponte',         'icon' => '🥚', 'color' => 'blue'],
        'reproducteur'  => ['label' => 'Reproducteur',  'icon' => '🧬', 'color' => 'emerald'],
        'poussiniere'   => ['label' => 'Poussinière',   'icon' => '🐣', 'color' => 'purple'],
        'engraissement' => ['label' => 'Engraissement', 'icon' => '🐑', 'color' => 'amber'],
        'laitiere'      => ['label' => 'Laitière',      'icon' => '🥛', 'color' => 'rose'],
        'grossissement' => ['label' => 'Grossissement', 'icon' => '🐟', 'color' => 'cyan'],
        'alevinage'     => ['label' => 'Alevinage',     'icon' => '🐠', 'color' => 'indigo'],
    ];

    /**
     * Liste des normes filtrées par type (chair, ponte, etc.)
     */
    public function index(Request $request)
    {
        // Onglets : tous les types de production déclarés au référentiel
        // (+ valeurs historiques volaille pour compatibilité ascendante)
        $batchTypes = ProductionType::pluck('slug')
            ->merge(['chair', 'ponte', 'poussiniere', 'reproducteur'])
            ->unique()
            ->sortBy(fn ($slug) => array_search($slug, array_keys(self::TYPE_META)) ?: 99)
            ->values();

        $type = $request->get('type', 'chair');
        $norms = ProductionNorm::with('species:id,name_fr,icon')
                    ->where('batch_type', $type)
                    ->orderBy('week_number')
                    ->get();

        // Espèces proposées pour rattacher explicitement une souche.
        $species = Species::orderBy('sort_order')->orderBy('name_fr')->get();

        return view('admin.norms.index', compact('norms', 'type', 'batchTypes', 'species'));
    }

    /**
     * Enregistre une nouvelle norme
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'batch_type'         => 'required|string',
            'species_id'         => 'nullable|integer|exists:species,id',
            'week_number'        => 'required|integer|min:1',
            'phase_name'         => 'required|string',
            'model_name'         => 'nullable|string',
            'target_weight'      => 'nullable|numeric|min:0',
            'target_laying_rate' => 'nullable|numeric|min:0|max:100',
            'target_feed_daily'  => 'nullable|numeric|min:0',
            'target_water_daily' => 'nullable|numeric|min:0',
        ]);

        // Clé d'unicité alignée sur l'index DB (batch_type, week_number,
        // model_name) pour ne pas écraser une souche par une autre.
        ProductionNorm::updateOrCreate(
            [
                'batch_type'  => $data['batch_type'],
                'week_number' => $data['week_number'],
                'model_name'  => $data['model_name'] ?? null,
            ],
            $data
        );

        return back()->with('success', 'Référentiel mis à jour avec succès.');
    }

    /**
     * Met à jour une norme existante (via l'ID)
     */
    public function update(Request $request, ProductionNorm $norm)
    {
        $data = $request->validate([
            'batch_type'         => 'required|string',
            'species_id'         => 'nullable|integer|exists:species,id',
            'week_number'        => 'required|integer|min:1',
            'phase_name'         => 'required|string',
            'model_name'         => 'nullable|string',
            'target_weight'      => 'nullable|numeric|min:0',
            'target_laying_rate' => 'nullable|numeric|min:0|max:100',
            'target_feed_daily'  => 'nullable|numeric|min:0',
            'target_water_daily' => 'nullable|numeric|min:0',
        ]);

        $norm->update($data);

        return back()->with('success', 'La norme a été modifiée avec succès.');
    }

    /**
     * Supprime une ligne spécifique du référentiel
     */
    public function destroy(ProductionNorm $norm)
    {
        $norm->delete();

        return back()->with('success', 'La ligne a été supprimée du référentiel.');
    }

    /**
     * Importation massive via CSV
     */
    /**
 * Importation massive via CSV avec détection automatique du type
 */
public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        
        fgetcsv($handle); // Sauter l'entête

        // Table de correspondance slug d'espèce -> id, pour rattacher
        // automatiquement les souches importées à leur espèce.
        $speciesBySlug = Species::pluck('id', 'slug');

        DB::beginTransaction();

        try {
            $count = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (empty($data[0])) continue;

                // --- LOGIQUE DE DÉTECTION AUTOMATIQUE DU TYPE ---
                // On récupère le nom du modèle (colonne G)
                $modelName = strtolower($data[6] ?? 'standard');
                
                // On définit le type en fonction du nom du modèle si non précisé
                // Vous pouvez ajuster cette liste selon vos besoins
                $detectedType = match(true) {
                    str_contains($modelName, 'isa'), str_contains($modelName, 'lohmann') => 'ponte',
                    str_contains($modelName, 'cobb'), str_contains($modelName, 'ross')  => 'chair',
                    str_contains($modelName, 'goliath'), str_contains($modelName, 'sasso') => 'reproducteur',
                    default => $request->batch_type // Valeur de repli (celle de l'onglet actif)
                };

                // Rattachement automatique de l'espèce d'après le nom de souche.
                $detectedSlug = ProductionNorm::guessSpeciesSlug($data[6] ?? null);
                $detectedSpeciesId = $detectedSlug ? ($speciesBySlug[$detectedSlug] ?? null) : null;

                ProductionNorm::updateOrCreate(
                    [
                        'batch_type'  => $detectedType,
                        'week_number' => $data[0],
                        'model_name'  => $data[6] ?? 'Standard', // On ajoute le modèle dans la clé unique pour éviter d'écraser Isa par Cobb
                    ],
                    [
                        'species_id'         => $detectedSpeciesId,
                        'phase_name'         => $data[1] ?? 'Production',
                        'target_weight'      => $data[2] ?? 0,
                        'target_laying_rate' => $data[3] ?? 0,
                        'target_feed_daily'  => $data[4] ?? 0,
                        'target_water_daily' => $data[5] ?? 0,
                    ]
                );
                $count++;
            }
            fclose($handle);
            DB::commit();

            return back()->with('success', "$count lignes ont été ventilées dans les catégories correspondantes.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Erreur lors de l\'importation : ' . $e->getMessage());
        }
    }
}