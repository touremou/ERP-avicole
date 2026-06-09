<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductionNorm; // Assurez-vous d'importer le modèle

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
            ['batch_type' => 'chair', 'week_number' => 1, 'phase_name' => 'Démarrage', 'target_weight' => 190, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 2, 'phase_name' => 'Démarrage', 'target_weight' => 480, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 3, 'phase_name' => 'Croissance', 'target_weight' => 960, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 4, 'phase_name' => 'Croissance', 'target_weight' => 1550, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 5, 'phase_name' => 'Finition', 'target_weight' => 2200, 'target_laying_rate' => 0],
            ['batch_type' => 'chair', 'week_number' => 6, 'phase_name' => 'Finition', 'target_weight' => 2800, 'target_laying_rate' => 0],

            // --- PONTE (ISA Brown) ---
            ['batch_type' => 'ponte', 'week_number' => 1, 'phase_name' => 'Démarrage', 'target_weight' => 75, 'target_laying_rate' => 0],
            ['batch_type' => 'ponte', 'week_number' => 18, 'phase_name' => 'Pré-Ponte', 'target_weight' => 1550, 'target_laying_rate' => 10],
            ['batch_type' => 'ponte', 'week_number' => 25, 'phase_name' => 'Ponte', 'target_weight' => 1900, 'target_laying_rate' => 94],
            
            // Ajoutez d'autres semaines selon vos besoins
        ];

        foreach ($norms as $norm) {
            ProductionNorm::updateOrCreate(
                ['batch_type' => $norm['batch_type'], 'week_number' => $norm['week_number']],
                $norm
            );
        }
    }
}