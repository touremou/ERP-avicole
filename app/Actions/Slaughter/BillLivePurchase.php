<?php

namespace App\Actions\Slaughter;

use App\Models\SlaughterReception;
use App\Models\SupplierInvoice;
use App\Services\DocumentNumberingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Achat de sujets vifs (E8, pendant du façon) — appelée à l'enregistrement
 * d'une réception `origin = achat` dont le prix est connu :
 *
 *  1. calcule le coût d'achat selon la base figée (par sujet | au kg vif |
 *     forfait) ;
 *  2. génère une FACTURE FOURNISSEUR BROUILLON (dette envers l'éleveur) dans
 *     le module Achats — à sa validation, elle poste UNE dépense « achat
 *     animaux » (P&L / SYSCOHADA 602) et alimente dettes + DPO ; le règlement
 *     passe par la Trésorerie. La validation reste une décision humaine.
 *
 * Best-effort : un échec de facturation ne doit JAMAIS annuler la réception
 * (le contrôle sanitaire est fait) — le coût reste consigné sur la réception
 * (purchase_total_cost présent, supplier_invoice_id absent) et se rattrape en
 * ligne via le module Achats.
 */
class BillLivePurchase
{
    public function execute(SlaughterReception $reception): SlaughterReception
    {
        if (! $reception->isPurchase()) {
            return $reception;
        }

        $cost = $reception->computePurchaseCost();
        if ($cost === null || $cost <= 0) {
            // Achat au prix non renseigné : rien à facturer automatiquement —
            // l'achat se saisit au bureau (module Achats) quand le prix est fixé.
            return $reception;
        }

        $reception->forceFill(['purchase_total_cost' => $cost])->save();

        try {
            $provider = $reception->provider;
            $basisLabel = SlaughterReception::PURCHASE_BASES[$reception->purchase_basis] ?? $reception->purchase_basis;

            $invoice = SupplierInvoice::create([
                'provider_id'   => $reception->provider_id,
                'reference'     => DocumentNumberingService::generate('supplier_invoice'),
                'invoice_date'  => $reception->reception_date,
                'category'      => 'achat_animaux',
                'label'         => "Achat vif — {$reception->received_quantity} sujets"
                    . ($provider ? " ({$provider->name})" : '')
                    . " · {$basisLabel}",
                'total_amount'  => $cost,
                'status'        => 'brouillon',
                'posts_expense' => true,
                'user_id'       => Auth::id(),
                'notes'         => "Réception vif du {$reception->reception_date->format('d/m/Y')} "
                    . "— {$reception->received_quantity} sujets, "
                    . number_format((float) $reception->total_live_weight_kg, 1) . ' kg vif.',
            ]);

            $reception->forceFill(['supplier_invoice_id' => $invoice->id])->save();

            Log::info("Achat vif réception {$reception->id} : {$cost} GNF → facture fournisseur brouillon {$invoice->reference}.");
        } catch (\Throwable $e) {
            Log::warning("Achat vif réception {$reception->id} : facture non générée ({$e->getMessage()}) — coût {$cost} GNF consigné sur la réception.");
        }

        return $reception->fresh();
    }
}
