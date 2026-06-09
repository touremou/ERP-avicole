<?php

namespace App\Http\Controllers;

use App\Models\FeedPurchase;
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

    public function update(UpdateFeedPurchaseRequest $request, FeedPurchase $feedPurchase, UpdateFeedPurchase $updatePurchase)
    {
        $updatedPurchase = $updatePurchase->execute($feedPurchase, $request->validated());

        return redirect()->route('batches.show', $updatedPurchase->batch_id)
            ->with('success', '✅ Achat rectifié et stock synchronisé.');
    }

    public function destroy(FeedPurchase $feedPurchase, DeleteFeedPurchase $deletePurchase)
    {
        if (Gate::denies('S')) return back()->with('error', 'Seul un superviseur peut annuler un achat validé.');

        $deletePurchase->execute($feedPurchase);
        
        return back()->with('success', 'Achat annulé. L\'inventaire a été décrémenté.');
    }
}