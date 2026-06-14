<?php

namespace App\Actions\Building;

use App\Models\Building;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecommissionBuilding
{
    public function execute(Building $building): void
    {
        // Empêcher la destruction d'un outil de production en cours d'utilisation
        if ($building->batches()->active()->exists()) {
            throw ValidationException::withMessages([
                'building' => 'DANGER D\'INTÉGRITÉ : Impossible de retirer ce bâtiment du parc industriel car un lot y est actuellement actif.'
            ]);
        }

        DB::transaction(function () use ($building) {
            $building->delete(); // Exécute le SoftDelete si configuré sur le modèle
        });
    }
}