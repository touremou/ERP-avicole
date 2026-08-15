<?php

namespace App\Observers;

use App\Models\SupplierPayment;
use App\Services\TreasuryPostingService;

/**
 * Comptabilise les règlements fournisseurs en trésorerie (décaissement ;
 * avoir négatif → entrée), de façon idempotente et réversible.
 */
class SupplierPaymentObserver
{
    public function created(SupplierPayment $payment): void
    {
        app(TreasuryPostingService::class)->postSupplierPayment($payment);

        // Visibilité du promoteur, hors site, sur l'argent qui SORT. L'alerte
        // vit ici et non dans l'écran d'achat : trois chemins créent un
        // règlement fournisseur (l'écran de règlement et les deux chemins
        // d'achat d'aliment). Les brancher un par un rouvrirait le trou au
        // quatrième — c'est exactement ce qui était arrivé à l'ajustement de
        // stock (#215, #229, #230, #234).
        //
        // Jamais bloquante : une notification en échec ne doit pas empêcher
        // l'enregistrement d'un règlement déjà versé.
        rescue(
            fn () => app(\App\Services\NotificationHub::class)->notifySupplierPaymentMade($payment),
            fn ($e) => \Illuminate\Support\Facades\Log::warning(
                "Alerte de règlement fournisseur non émise : {$e->getMessage()}"
            ),
            report: false
        );
    }

    public function deleted(SupplierPayment $payment): void
    {
        app(TreasuryPostingService::class)->reverseFor($payment);
    }
}
