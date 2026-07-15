<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\TreasuryPostingService;

/**
 * Comptabilise les encaissements de vente dans la trésorerie, quel que soit le
 * chemin (RecordPayment, paiement immédiat à la vente, POS, remboursement) :
 *  - création → écriture d'entrée (ou sortie si montant négatif / avoir) ;
 *  - suppression → contre-passation.
 */
class PaymentObserver
{
    public function created(Payment $payment): void
    {
        app(TreasuryPostingService::class)->postPayment($payment);
    }

    public function deleted(Payment $payment): void
    {
        app(TreasuryPostingService::class)->reverseFor($payment);
    }
}
