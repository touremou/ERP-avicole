<?php

namespace App\Http\Controllers;

use App\Models\EggMovement;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;

class EggMovementController extends Controller
{
    /**
     * Enregistre une sortie de stock (Vente, Don, etc.)
     * Rigueur : Vérification de la disponibilité physique avant validation
     */
    public function store(Request $request)
    {
        // --- SÉCURITÉ 0 : PERMISSIONS ---
        if (Gate::denies('C')) {
            return back()->with('error', '🔒 ACCÈS REFUSÉ : Privilèges insuffisants pour sortir des produits du stock.');
        }

        $request->validate([
            'type'     => 'required|in:vente,don,ajustement,casse_magasin',
            'grade'    => 'required|in:XL,L,M,S',
            'quantity' => 'required|integer|min:1', 
            'notes'    => 'nullable|string|max:255'
        ]);

        // --- SÉCURITÉ 1 : VÉRIFICATION DISPONIBILITÉ ---
        $stock = Stock::where('item_name', $request->grade)
                      ->where('category', 'oeufs')
                      ->first();

        if (!$stock) {
            return back()->with('error', "Erreur : L'article '{$request->grade}' n'existe pas dans le référentiel stock.");
        }

        // Conversion de la demande (Unités) en Alvéoles pour comparer au stock
        $requestedAlv = (float) ($request->quantity / 30);

        if ($stock->current_quantity < $requestedAlv) {
            return back()->withErrors([
                'quantity' => "📦 STOCK INSUFFISANT : Vous demandez " . number_format($requestedAlv, 2) . " alvéoles, mais il n'en reste que " . number_format($stock->current_quantity, 2) . " en magasin."
            ])->withInput();
        }

        return DB::transaction(function () use ($request) {
            // Ajout de l'ID utilisateur pour la traçabilité
            $data = $request->all();
            $data['user_id'] = Auth::id();

            // 1. Création du mouvement logistique interne
            $mov = EggMovement::create($data);

            // 2. Synchronisation avec le module Stock (Soustraction)
            // On passe 'Unité' : le service divisera par 30 pour impacter le stock en 'Alvéole'
            StockIntegrationService::syncMovement(
                $request->grade,
                'oeufs',
                $request->quantity,
                'out',
                "Mouvement " . ucfirst($request->type) . " (Ref #{$mov->id})",
                'Unité' 
            );

            return back()->with('success', "Sortie de stock synchronisée : {$request->quantity} œufs " . $request->grade . " déduits.");
        });
    }

    /**
     * Annulation d'une sortie
     * Rigueur : Seul un Superviseur (S) peut annuler une sortie déjà validée
     */
    public function destroy(EggMovement $eggMovement)
    {
        if (Gate::denies('S')) {
            return back()->with('error', '🔒 SÉCURITÉ : Seul un administrateur peut annuler un mouvement de stock validé.');
        }

        return DB::transaction(function () use ($eggMovement) {
            // 1. Réintégration du stock (On rajoute ce qui était sorti)
            StockIntegrationService::syncMovement(
                $eggMovement->grade,
                'oeufs',
                $eggMovement->quantity,
                'in',
                "ANNULATION Mouvement #{$eggMovement->id}",
                'Unité'
            );

            // 2. Suppression logique (ou physique selon configuration)
            $eggMovement->delete();

            return back()->with('success', 'Mouvement annulé et stock réintégré en magasin.');
        });
    }
}