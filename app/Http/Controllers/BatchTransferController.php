<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Actions\Batch\TransferBatch;
use App\Http\Requests\Batch\TransferBatchRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Controller de transfert (mutation) de lots entre bâtiments.
 *
 * Toute la logique est dans :
 * - TransferBatchRequest : validation + vérifications métier (capacité, type, auto-transfert, lot actif)
 * - TransferBatch Action : historisation, statuts bâtiments, planning sanitaire
 *
 * Bugs corrigés : B-12 (no-op current_quantity), S-07 (multi-lots), S-09 (lot clôturé)
 */
class BatchTransferController extends Controller
{
    /**
     * Transfère un lot vers un nouveau bâtiment.
     */
    public function transfer(TransferBatchRequest $request, Batch $batch, TransferBatch $action): RedirectResponse
    {
        try {
            $result = $action->execute($batch, $request->validated());

            return back()->with('success',
                "Mutation validée : lot {$result->code} transféré vers {$result->building->name}."
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Échec transfert lot {$batch->id}: {$e->getMessage()}");

            return back()->withErrors(['error' => 'Échec du transfert : ' . $e->getMessage()])
                ->withInput();
        }
    }
}
