<?php
namespace App\Imports;

use App\Models\FoodNorm;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow; // On commence à la ligne 2
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class FoodNormImport implements ToModel, WithStartRow, WithCustomCsvSettings
{
    public function model(array $row)
    {
        // On utilise les index numériques [0, 1, 2...] au lieu des noms
        return new FoodNorm([
            'name'            => $row[0], // Colonne A
            'animal_type'     => $row[1], // Colonne B
            'phase'           => $row[2], // Colonne C
            'target_em'       => $row[3], // Colonne D
            'target_pb'       => $row[4], // ...
            'target_lys'      => $row[5],
            'target_meth'     => $row[6],
            'target_ca'       => $row[7],
            'target_p'        => $row[8],
            'target_price_kg' => $row[9],
            'is_active'       => true,
        ]);
    }

    public function startRow(): int
    {
        return 2; // Saute l'entête du CSV
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';' // Indispensable pour votre fichier
        ];
    }
}