<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use Exception;

class DeleteStockAction
{
    public function execute(Stock $stock): void
    {
        // Règle métier stricte encapsulée ici
        if ($stock->movements()->exists()) {
            throw new Exception("Impossible de supprimer un article possédant un historique. Utilisez la modification pour le désactiver.");
        }

        $stock->delete();
    }
}