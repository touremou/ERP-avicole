<?php

namespace App\Actions\Treasury;

use App\Models\Expense;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\DB;

/**
 * REPRISE DES DÉCAISSEMENTS POSÉS PAR LES DÉPENSES MIROIR.
 *
 * Un achat fournisseur validé posait DEUX sorties de trésorerie : celle de sa
 * dépense miroir, et celle de son règlement. Le solde de trésorerie est donc
 * sous-estimé du total des premières.
 *
 * Cette action retire ces écritures et rend leur montant aux comptes. Elle ne
 * touche à rien d'autre : ni les dépenses ordinaires, ni le carburant, ni les
 * règlements — seulement les écritures dont la SOURCE est une dépense reliée à
 * un achat fournisseur.
 *
 * Elle vit ici, et non dans la migration qui l'appelle, pour être mesurable par
 * un test : une réparation qui change des soldes visibles ne doit pas être le
 * seul morceau du correctif que personne ne vérifie.
 *
 * Rejouable : une seconde exécution ne trouve plus rien et rend 0.
 */
class ReverseMirrorExpensePostings
{
    /** @return array{count:int, restored:float} */
    public function execute(): array
    {
        $mirrorIds = Expense::withoutGlobalScopes()
            ->whereNotNull('supplier_invoice_id')
            ->pluck('id');

        if ($mirrorIds->isEmpty()) {
            return ['count' => 0, 'restored' => 0.0];
        }

        $ecritures = TreasuryTransaction::withoutGlobalScopes()
            ->where('source_type', (new Expense)->getMorphClass())
            ->whereIn('source_id', $mirrorIds)
            ->get(['id', 'treasury_account_id', 'direction', 'amount']);

        if ($ecritures->isEmpty()) {
            return ['count' => 0, 'restored' => 0.0];
        }

        $rendu = 0.0;

        DB::transaction(function () use ($ecritures, &$rendu) {
            foreach ($ecritures as $e) {
                // Delta signé : une sortie rend son montant, une entrée le reprend.
                $delta = $e->direction === 'in' ? -(float) $e->amount : (float) $e->amount;

                if ($e->treasury_account_id) {
                    TreasuryAccount::withoutGlobalScopes()
                        ->whereKey($e->treasury_account_id)
                        ->increment('current_balance', $delta);
                }

                $rendu += $delta;
            }

            TreasuryTransaction::withoutGlobalScopes()
                ->whereIn('id', $ecritures->pluck('id'))
                ->delete();
        });

        return ['count' => $ecritures->count(), 'restored' => round($rendu, 2)];
    }
}
