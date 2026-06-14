<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductionNorm; // Assurez-vous d'importer le modèle
use App\Models\Species;

// CHANGEZ "class DatabaseSeeder" PAR "class ProductionNormSeeder"
class ProductionNormSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $norms = [
            // --- CHAIR (Ross 308) ---
            ['batch_type' => 'chair', 'week_number' => 1, 'phase_name' => 'Démarrage', 'model_name' => 'Ross 308', 'target_weight' => 190, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 2, 'phase_name' => 'Démarrage', 'model_name' => 'Ross 308', 'target_weight' => 480, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 3, 'phase_name' => 'Croissance', 'model_name' => 'Ross 308', 'target_weight' => 960, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 4, 'phase_name' => 'Croissance', 'model_name' => 'Ross 308', 'target_weight' => 1550, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 5, 'phase_name' => 'Finition', 'model_name' => 'Ross 308', 'target_weight' => 2200, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 6, 'phase_name' => 'Finition', 'model_name' => 'Ross 308', 'target_weight' => 2800, 'target_laying_rate' => 0],

            // --- PONTE (ISA Brown) ---
            ['batch_type' => 'ponte', 'week_number' => 1, 'phase_name' => 'Démarrage', 'model_name' => 'ISA Brown', 'target_weight' => 75, 'target_laying_rate' => 0],
            ['batch_type' => 'ponte', 'week_number' => 18, 'phase_name' => 'Pré-Ponte', 'model_name' => 'ISA Brown', 'target_weight' => 1550, 'target_laying_rate' => 10],
            ['batch_type' => 'ponte', 'week_number' => 25, 'phase_name' => 'Ponte', 'model_name' => 'ISA Brown', 'target_weight' => 1900, 'target_laying_rate' => 94],

            // --- POUSSINIÈRE (Cobb 500) ---
            ['batch_type' => 'poussiniere', 'week_number' => 1, 'phase_name' => 'Démarrage', 'model_name' => 'Cobb 500', 'target_weight' => 180, 'target_laying_rate' => 0],

            // --- REPRODUCTEURS (volaille & autres espèces) ---
            ['batch_type' => 'reproducteur', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Goliath', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'reproducteur', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Bélier Djallonké', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'reproducteur', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Bouc Sahélien', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'reproducteur', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Lapin Californien', 'target_weight' => 0, 'target_laying_rate' => 0],

            // --- ENGRAISSEMENT (ovins, caprins, porcins, lapins) ---
            ['batch_type' => 'engraissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Mouton Djallonké', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'engraissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Chèvre du Sahel', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'engraissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Lapin Néo-Zélandais', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'engraissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Porc Large White', 'target_weight' => 0, 'target_laying_rate' => 0],

            // --- LAITIÈRE (caprins) ---
            ['batch_type' => 'laitiere', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Chèvre Saanen', 'target_weight' => 0, 'target_laying_rate' => 0],

            // --- PISCICULTURE (grossissement & alevinage) ---
            ['batch_type' => 'grossissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Tilapia du Nil', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'grossissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Carpe Commune', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'grossissement', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Silure Africain', 'target_weight' => 0, 'target_laying_rate' => 0],
            ['batch_type' => 'alevinage', 'week_number' => 1, 'phase_name' => 'Référence', 'model_name' => 'Alevin Tilapia', 'target_weight' => 0, 'target_laying_rate' => 0],
        ];

        $speciesBySlug = Species::pluck('id', 'slug');

        foreach ($norms as $norm) {
            // Rattachement à l'espèce déduite du nom de souche.
            $slug = ProductionNorm::guessSpeciesSlug($norm['model_name']);
            $norm['species_id'] = $slug ? ($speciesBySlug[$slug] ?? null) : null;

            ProductionNorm::updateOrCreate(
                [
                    'batch_type'  => $norm['batch_type'],
                    'week_number' => $norm['week_number'],
                    'model_name'  => $norm['model_name'],
                ],
                $norm
            );
        }
    }
}