<?php

namespace App\Http\Controllers;

use App\Models\FeedPurchase;
use App\Models\Provider;
use App\Http\Requests\FeedPurchase\StoreFeedPurchaseRequest;
use App\Http\Requests\FeedPurchase\UpdateFeedPurchaseRequest;
use App\Actions\FeedPurchase\CreateFeedPurchase;
use App\Actions\FeedPurchase\UpdateFeedPurchase;
use App\Actions\FeedPurchase\DeleteFeedPurchase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Exception;

class FeedPurchaseController extends Controller
{
    public function store(StoreFeedPurchaseRequest $request, CreateFeedPurchase $createPurchase)
    {
        try {
            $purchase = $createPurchase->execute($request->validated());
            $consoType = $purchase->metadata['conso_type'] ?? 'Aliment';
            
            return back()->with('success', "✅ {$consoType} ajouté au stock et affecté au lot {$purchase->batch->code}.");
        } catch (Exception $e) {
            Log::error("Échec Ravitaillement: " . $e->getMessage());
            return back()->with('error', "Erreur lors du ravitaillement : " . $e->getMessage());
        }
    }

    /**
     * Formulaire de rectification d'un ravitaillement.
     *
     * La vue (`feed-purchases/edit.blade.php`), la validation
     * (UpdateFeedPurchaseRequest), l'action (UpdateFeedPurchase) et la route
     * existaient ; seule cette méthode manquait. Le crayon affiché en face de
     * chaque achat sur la fiche de bande menait donc à une erreur serveur :
     * corriger un achat d'aliment était impossible, alors que tout était en place
     * pour le faire.
     */
    public function edit(FeedPurchase $feedPurchase)
    {
        if (Gate::denies('provenderie.M')) {
            return back()->with('error', 'Rectification réservée aux managers.');
        }

        $batch = $feedPurchase->batch;

        if (! $batch) {
            return redirect()->route('batches.index')
                ->with('error', 'Cet achat n\'est rattaché à aucune bande : rectification impossible.');
        }

        // Même source que le formulaire de création (cf. BatchController) : une
        // seconde liste divergerait au premier fournisseur désactivé.
        $providers = Provider::orderBy('name')->get();

        return view('feed-purchases.edit', compact('feedPurchase', 'batch', 'providers'));
    }

    public function update(UpdateFeedPurchaseRequest $request, FeedPurchase $feedPurchase, UpdateFeedPurchase $updatePurchase)
    {
        $updatedPurchase = $updatePurchase->execute($feedPurchase, $request->validated());

        return redirect()->route('batches.show', $updatedPurchase->batch_id)
            ->with('success', '✅ Achat rectifié et stock synchronisé.');
    }

    public function destroy(FeedPurchase $feedPurchase, DeleteFeedPurchase $deletePurchase)
    {
        if (Gate::denies('provenderie.S')) return back()->with('error', 'Seul un superviseur peut annuler un achat validé.');

        $deletePurchase->execute($feedPurchase);
        
        return back()->with('success', 'Achat annulé. L\'inventaire a été décrémenté.');
    }
}