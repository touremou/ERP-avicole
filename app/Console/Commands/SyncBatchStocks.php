<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

class SyncBatchStocks extends Command
{
    protected $signature = 'stocks:sync';
    protected $description = 'Synchronise les stocks de sujets et les stocks d\'œufs (Calibres & Pertes)';

    public function handle()
    {
        $this->info("🚀 Début de la synchronisation globale...");

        DB::transaction(function () {
            $this->syncSujets();
            $this->syncOeufs();
        });

        $this->info('✅ Synchronisation ERP terminée avec succès.');
    }

    /**
     * SYNCHRO 1 : Les Sujets (Population des lots)
     */
    private function syncSujets()
    {
        $batches = Batch::active()->get();
        $this->warn("--- Synchronisation des Sujets ---");

        foreach ($batches as $batch) {
            // On calcule l'impact total depuis les pointages quotidiens
            $totalImpact = $batch->dailyChecks->sum(function($c) {
                return ((int)$c->mortality + (int)$c->qty_quarantine_in + (int)$c->qty_sorted_out) 
                       - (int)$c->qty_quarantine_out;
            });

            $newQty = max(0, $batch->initial_quantity - $totalImpact);
            
            if ($batch->current_quantity != $newQty) {
                $batch->update(['current_quantity' => $newQty]);
                $this->line("Lot {$batch->code} : rectifié à {$newQty} sujets.");
            }
        }
    }

    /**
     * SYNCHRO 2 : Les Œufs (Stocks Magasin)
     * Aligne la table 'stocks' sur la somme des 'egg_productions'
     */
    private function syncOeufs()
    {
        $this->warn("--- Synchronisation des Œufs ---");

        // Mapping : Nom de l'article en Stock => Colonne dans EggProduction
        $mapping = [
            'S'        => 'grade_s',
            'M'        => 'grade_m',
            'L'        => 'grade_l',
            'XL'       => 'grade_xl',
            'Cassé'    => 'broken_eggs',
            'Anomalie' => 'small_eggs'
        ];

        foreach ($mapping as $stockName => $prodField) {
            // 1. On récupère le stock correspondant
            $stock = Stock::where('item_name', $stockName)
                          ->where('category', Stock::CAT_OEUFS)
                          ->first();

            if (!$stock) {
                $this->error("Stock '{$stockName}' introuvable dans la base.");
                continue;
            }

            // 2. Somme totale produite pour ce champ
            $totalProduit = EggProduction::sum($prodField);

            // 3. Conversion intelligente :
            // Si le champ est 'broken_eggs' ou 'small_eggs', la valeur est en UNITÉS.
            // Si l'unité du stock est 'Alvéole', on convertit (œufs par alvéole configuré).
            $finalQty = $totalProduit;

            if (in_array($prodField, ['broken_eggs', 'small_eggs']) && $stock->unit === 'Alvéole') {
                $finalQty = \App\Services\UnitConverter::eggsToTrays((float) $totalProduit);
            }

            // 4. Mise à jour du stock
            $stock->update(['current_quantity' => max(0, $finalQty)]);
            
            $displayQty = number_format($finalQty, 2);
            $this->line("Article '{$stockName}' synchronisé : {$displayQty} {$stock->unit}");
        }
    }
}