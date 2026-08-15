<?php

namespace App\Http\Controllers;

use App\Actions\Stock\MoveStockAction;
use App\Models\EggProduction;
use App\Models\Stock;
use App\Services\UnitConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * EggMovementController — mouvement MANUEL d'un calibre d'œufs.
 *
 * Ce contrôleur portait aussi un tri par calibre complet (formulaire, création,
 * correction, ~180 lignes). Ce tri-là était MORT à trois titres : aucune route
 * ne le desservait, sa vue n'existait pas, et il écrivait dans des colonnes
 * absentes du schéma (`is_sorted`, `qty_s`, `collection_date`,
 * `total_collected`…) — la table porte `is_graded`, `grade_s`,
 * `production_date`, `total_eggs_collected`. Il ne pouvait donc que planter s'il
 * était un jour rebranché, tout en se lisant comme la règle de tri en vigueur.
 * Le vrai tri vit dans GradeEggProduction, et lui seul.
 *
 * Ne reste ici que ce qui est réellement routé : le mouvement manuel.
 */
class EggMovementController extends Controller
{
    /**
     * Mouvement manuel d'œufs (ajustement d'inventaire, sortie, entrée).
     *
     * IL ÉCRIVAIT PAR StockIntegrationService — l'outil des flux AUTOMATIQUES
     * (tri, production, abattage), où l'écriture dérive d'un document et n'a
     * personne à alerter. D'où deux manques, propres au geste manuel :
     *
     *   • aucune ALERTE sur un ajustement, alors que réécrire à la main un
     *     niveau de stock alerte partout ailleurs (#215, #229, #230, #234) ;
     *   • une sortie supérieure au stock était plafonnée à zéro en silence,
     *     faisant disparaître la matière manquante au lieu de la signaler.
     *
     * On passe donc par le chemin canonique du magasin (MoveStockAction), qui
     * alerte et surveille le franchissement de seuil. La seule chose propre aux
     * œufs reste ici : la conversion Unité → Alvéole, déléguée à UnitConverter
     * comme partout ailleurs.
     */
    public function storeMovement(Request $request, MoveStockAction $action)
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Action réservée aux managers.');

        $validated = $request->validate([
            'calibre'   => 'required|in:' . implode(',', EggProduction::gradeCodes()),
            'type'      => 'required|in:in,out,adjustment',
            'quantity'  => 'required|integer|min:1',
            'unit'      => 'required|in:unite,alveole',
            'reason'    => 'required|string|max:500',
        ]);

        $stock = Stock::where('category', Stock::CAT_OEUFS)
            ->where('item_name', $validated['calibre'])
            ->first();

        if (! $stock) {
            return back()->with('error', "Mouvement impossible : article '{$validated['calibre']}' introuvable dans le stock œufs.");
        }

        $inputUnit = $validated['unit'] === 'alveole' ? 'Alvéole' : 'Unité';
        $quantity  = UnitConverter::toStockBase((float) $validated['quantity'], $inputUnit, Stock::CAT_OEUFS);

        // Disponibilité, miroir de MoveStockRequest : l'ancien chemin plafonnait
        // silencieusement à zéro, ce qui faisait disparaître la matière manquante
        // au lieu de la signaler.
        if ($validated['type'] === 'out' && (float) $stock->current_quantity < $quantity) {
            return back()->withErrors([
                'quantity' => "Stock insuffisant (disponible : {$stock->current_quantity} {$stock->unit}).",
            ])->withInput();
        }

        $action->execute($stock->id, $validated['type'], $quantity, $validated['reason'], Auth::id());

        return back()->with('success',
            "Mouvement {$validated['type']} de {$validated['quantity']} {$inputUnit}(s) — Calibre {$validated['calibre']} enregistré."
        );
    }
}
