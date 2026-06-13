<?php

namespace App\Actions\Provenderie;

use App\Models\Stock;
use Illuminate\Support\Facades\Log;

class NormalizeFormulaNameAction
{
    /**
     * Mappe un nom de formule vers le nom exact dans la table stocks.
     */
    public function execute(string $formulaName): string
    {
        $upper = mb_strtoupper($formulaName);

        $mappings = [
            // Ponte
            'PONTE DÉMARRAGE'  => 'Ponte Démarrage (Poussin)',
            'PONTE DEMARRAGE'  => 'Ponte Démarrage (Poussin)',
            'PONTE CROISSANCE' => 'Ponte Croissance (Poulette)',
            'PONTE 1'          => 'Ponte 1 (Pic de ponte)',
            'PONTE PIC'        => 'Ponte 1 (Pic de ponte)',
            'PONTE 2'          => 'Ponte 2 (Entretien)',
            'PONTE ENTRETIEN'  => 'Ponte 2 (Entretien)',

            // Chair
            'CHAIR DÉMARRAGE'  => 'Chair Démarrage',
            'CHAIR DEMARRAGE'  => 'Chair Démarrage',
            'CHAIR CROISSANCE' => 'Chair Croissance',
            'CHAIR FINITION'   => 'Chair Finition',

            // Reproducteur
            'REPRO DÉMARRAGE'  => 'Ponte Démarrage (Poussin)',
            'REPRO DEMARRAGE'  => 'Ponte Démarrage (Poussin)',
            'REPRO CROISSANCE' => 'Ponte Croissance (Poulette)',
        ];

        foreach ($mappings as $pattern => $stockName) {
            if (str_contains($upper, $pattern)) {
                return $stockName;
            }
        }

        // Fallback en base de données
        $stock = Stock::where('item_name', $formulaName)
            ->where('category', Stock::CAT_CONSO)
            ->first();

        if ($stock) {
            return $stock->item_name;
        }

        Log::warning("[Provenderie] Nom de formule non mappé : '{$formulaName}'. Recherche exacte utilisée.");
        
        return $formulaName;
    }
}