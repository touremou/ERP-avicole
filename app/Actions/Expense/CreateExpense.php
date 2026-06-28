<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crée une dépense au statut « en_attente » (à valider par un responsable).
 *
 * La référence (DEP-XXXXX) est générée automatiquement. L'uuid est soit fourni
 * (saisie terrain hors-ligne, pour l'idempotence à la synchro), soit généré
 * par le trait HasStandardUuid.
 */
class CreateExpense
{
    public function execute(array $data, ?UploadedFile $justificatif = null): Expense
    {
        return DB::transaction(function () use ($data, $justificatif) {
            // Justificatif (facture, reçu, note de frais) : même convention de
            // stockage que les autres pièces du SI (disque public, chemin en BDD).
            $justificatifPath = $justificatif
                ? $justificatif->store('expenses/justificatifs', 'public')
                : null;

            $expense = Expense::create([
                'uuid'              => $data['uuid'] ?? null,
                'reference'         => \App\Services\DocumentNumberingService::generate('expense'),
                'batch_id'          => $data['batch_id'] ?? null,
                'user_id'           => $data['user_id'] ?? Auth::id(),
                'category'          => $data['category'],
                'label'             => $data['label'],
                'amount'            => $data['amount'],
                'expense_date'      => $data['expense_date'],
                'payment_method'    => $data['payment_method'] ?? 'especes',
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
                'status'            => 'en_attente',
                'supplier_name'     => $data['supplier_name'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'justificatif_path' => $justificatifPath,
            ]);

            Log::info("Dépense créée : {$expense->reference} ({$expense->amount}).");

            return $expense->fresh(['batch', 'user']);
        });
    }
}
