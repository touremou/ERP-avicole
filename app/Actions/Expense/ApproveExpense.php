<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Valide une dépense « en_attente » → « valide ».
 * Dès cet instant, elle entre dans les résultats financiers (P&L + marge lot).
 */
class ApproveExpense
{
    /**
     * LA GARDE ÉTAIT EN CARTON EN PARALLÈLE.
     *
     * Le contrôle `status !== 'en_attente'` se faisait sur une dépense lue SANS
     * VERROU et HORS TRANSACTION. Deux requêtes simultanées — un double-clic sur
     * « Valider », une connexion lente — le passaient toutes les deux, validaient
     * la même dépense deux fois, et l'observateur postait DEUX décaissements.
     *
     * Prouvé par drill deux processus (protocole docs/audit/drills-concurrence.md),
     * MySQL/InnoDB, sur la même dépense de 300 000 GNF : deux écritures et
     * 600 000 GNF sortis, sur deux essais sur cinq. Les trois autres ont été
     * sauvés par un interblocage InnoDB — un accident de verrouillage, pas une
     * garde.
     *
     * Le dépôt avait déjà tiré la leçon deux fois (C1 sur le stock, C3 sur
     * l'encaissement) et l'a écrite : « Un verrou ne protège que ce qu'on lit
     * SOUS lui […] verrou → relecture verrouillante → contrôle → écriture, le
     * tout dans la transaction. » La validation de dépense était restée en
     * arrière.
     */
    public function execute(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense) {
            $verrouillee = Expense::lockForUpdate()->findOrFail($expense->id);

            if ($verrouillee->status !== 'en_attente') {
                throw new \RuntimeException("Seule une dépense en attente peut être validée.");
            }

            $verrouillee->update([
                'status'      => 'valide',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            Log::info("Dépense validée : {$verrouillee->reference} par user " . Auth::id());

            // L'appelant tient l'instance qu'il nous a passée : on la remet en
            // phase, sans quoi il rendrait un statut périmé à l'écran.
            $expense->setRawAttributes($verrouillee->getAttributes(), true);

            return $expense;
        });
    }
}
