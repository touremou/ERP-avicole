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
    }

    public function deleted(SupplierPayment $payment): void
    {
        app(TreasuryPostingService::class)->reverseFor($payment);
    }
}
