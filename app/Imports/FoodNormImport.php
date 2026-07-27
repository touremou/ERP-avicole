<?php

namespace App\Imports;

use App\Models\FoodNorm;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithStartRow; // On commence à la ligne 2

/**
 * IMPORT DU RÉFÉRENTIEL NORMÉ — idempotent.
 *
 * L'ancienne version faisait `new FoodNorm([...])` sur chaque ligne : réimporter
 * le classeur après avoir corrigé une cible AJOUTAIT un jeu complet de normes au
 * lieu de mettre à jour l'existant, et les écrans lisaient ensuite l'un des
 * doublons au hasard. La clef métier est (type d'animal, phase) : on met donc à
 * jour sur cette clef.
 *
 * Le fichier est positionnel (cf. FoodNorm::IMPORT_COLUMNS) et sans en-tête
 * vérifiable : une colonne décalée écrirait l'énergie dans la protéine sans un
 * mot. On refuse donc les lignes dont le type est vide ou dont l'énergie n'est
 * pas un nombre — ce qui écarte aussi les lignes de titre et les lignes vides du
 * bas de classeur, qui créaient des normes fantômes.
 */
class FoodNormImport implements ToModel, WithStartRow, WithCustomCsvSettings
{
    public function model(array $row)
    {
        $animalType = trim((string) ($row[1] ?? ''));
        $phase      = trim((string) ($row[2] ?? ''));

        if ($animalType === '' || $phase === '' || $this->number($row[3] ?? null) === null) {
            return null; // ligne de titre, de séparation ou de bas de classeur
        }

        FoodNorm::updateOrCreate(
            ['animal_type' => $animalType, 'phase' => $phase],
            [
                'name'            => trim((string) ($row[0] ?? '')) ?: "{$animalType} — {$phase}",
                'target_em'       => $this->number($row[3] ?? null) ?? 0,
                'target_pb'       => $this->number($row[4] ?? null) ?? 0,
                'target_lys'      => $this->number($row[5] ?? null) ?? 0,
                'target_meth'     => $this->number($row[6] ?? null) ?? 0,
                'target_ca'       => $this->number($row[7] ?? null) ?? 0,
                'target_p'        => $this->number($row[8] ?? null) ?? 0,
                'target_price_kg' => $this->number($row[9] ?? null),
                'is_active'       => true,
            ]
        );

        return null; // l'écriture est faite ci-dessus, rien à insérer en plus
    }

    public function startRow(): int
    {
        return 2; // Saute l'entête du CSV
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';', // Indispensable pour votre fichier
        ];
    }

    /**
     * Tolère la virgule décimale et les espaces de milliers : un Excel
     * francophone écrit « 3 000,50 », que `(float)` tronquait à 3.
     */
    private function number($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = str_replace([' ', "\u{00A0}", ' '], '', (string) $value);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }
}
