<?php

namespace App\Actions\FeedPurchase;

use App\Models\FeedPurchase;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateFeedPurchase
{
    public function execute(FeedPurchase $feedPurchase, array $data): FeedPurchase
    {
        $feedPurchase = DB::transaction(function () use ($feedPurchase, $data) {
            $oldQuantity = (float) $feedPurchase->quantity;
            $newQuantity = (float) $data['quantity'];
            $oldType     = $feedPurchase->feed_type;
            $newType     = $data['feed_type'];

            // 1. RÉGULARISATION PAR DELTA (Mouvement net)
            if ($oldType !== $newType) {
                StockIntegrationService::syncMovement($oldType, 'conso', $oldQuantity, 'out', "Rectification Type (Annulation)");
                StockIntegrationService::syncMovement($newType, 'conso', $newQuantity, 'in', "Rectification Type (Nouveau)");
            } else {
                $diff = $newQuantity - $oldQuantity;
                if ($diff != 0) {
                    StockIntegrationService::syncMovement($newType, 'conso', abs($diff), $diff > 0 ? 'in' : 'out', "Ajustement quantité achat");
                }
            }

            // 2. UPDATE COMPTABLE
            $feedPurchase->update([
                'feed_type'     => $data['feed_type'],
                'quantity'      => $data['quantity'],
                'unit_price'    => $data['unit_price'] / max($data['quantity'], 1),
                'total_price'   => $data['unit_price'],
                'supplier'      => $data['supplier'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'metadata'      => array_merge($feedPurchase->metadata ?? [], $data['metadata'] ?? [])
            ]);

            return $feedPurchase;
        });

        // 3. PROPAGATION AP (post-commit, non bloquant) : la facture fournisseur
        // liée suit le nouveau montant/fournisseur. On PRÉSERVE l'état de paiement
        // (soldé → règlement re-calé sur le nouveau total ; crédit → reste impayé).
        $this->syncSupplierInvoice($feedPurchase, $data);

        return $feedPurchase;
    }

    private function syncSupplierInvoice(FeedPurchase $feedPurchase, array $data): void
    {
        try {
            $invoice = SupplierInvoice::where('feed_purchase_id', $feedPurchase->id)->first();
            if (! $invoice) {
                return; // pas de facture liée (achat sans fournisseur, ou antérieur)
            }

            $wasPaid    = $invoice->payment_status === 'solde';
            $dateAvant  = $invoice->invoice_date?->toDateString();
            $providerId = $invoice->provider_id;
            if (! empty($data['supplier'])) {
                $providerId = Provider::firstOrCreate(
                    ['name' => trim($data['supplier'])],
                    ['type' => 'Aliment', 'phone' => '—', 'status' => 'Actif']
                )->id;
            }

            DB::transaction(function () use ($invoice, $feedPurchase, $data, $wasPaid, $dateAvant, $providerId) {
                $invoice->update([
                    'provider_id'  => $providerId,
                    'invoice_date' => $data['purchase_date'],
                    'label'        => 'Aliment — ' . $feedPurchase->feed_type . ' (' . $feedPurchase->display_label . ')',
                    'total_amount' => $feedPurchase->total_price,
                ]);

                if ($wasPaid) {
                    $this->recalerLeReglement($invoice, $feedPurchase, $data, $dateAvant);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('AP feed-purchase update sync échouée: ' . $e->getMessage());
        }
    }

    /**
     * RE-CALE LE RÈGLEMENT D'UN ACHAT SOLDÉ SUR SON NOUVEAU TOTAL.
     *
     * Trois règles tiennent ce geste, et chacune répare une sortie d'argent qui
     * tombait au mauvais endroit.
     *
     * ─── 1. PAR LE MODÈLE, JAMAIS PAR LA REQUÊTE ───
     *
     * On écrivait `$invoice->payments()->delete()` : un DELETE direct en base,
     * aucun modèle chargé, donc aucun événement Eloquent.
     * `SupplierPaymentObserver::deleted` ne partait pas, et avec lui la
     * contre-passation en trésorerie. L'écriture de décaissement restait en
     * place — orpheline, sa pièce n'existant plus — pendant que le règlement
     * recréé, porteur d'un identifiant neuf, en postait une SECONDE.
     *
     * Un achat de 500 000 GNF rectifié à 650 000 sortait 1 150 000 de la
     * caisse. Et corriger le seul libellé, à montant inchangé, en sortait
     * 1 000 000 pour 500 000 : le bloc ne demande jamais si le total a bougé.
     *
     * Irrattrapable, de surcroît : `recomputeBalance()` — le recours quand un
     * solde paraît faux — recalcule À PARTIR de ces écritures et fige l'erreur.
     *
     * ─── 2. ON RECTIFIE LE MONTANT, PAS LA FAÇON DONT L'ARGENT EST SORTI ───
     *
     * Le règlement était recréé « especes », payé par celui qui rectifie. Un
     * achat pris à crédit puis réglé PAR VIREMENT devenait donc un règlement en
     * espèces : la banque restait débitée sans retour, la caisse était débitée
     * à son tour. Deux comptes faux, en sens inverses, pour un achat payé une
     * fois.
     *
     * Le mode, le compte, le payeur et le motif sont des faits que le
     * formulaire d'achat ne connaît pas : ils sont repris tels quels.
     *
     * La DATE suit la même logique, avec une nuance : un règlement daté du jour
     * de l'achat est le règlement fait À L'ACHAT — il suit la date rectifiée.
     * Tout autre porte la sienne, qui est le jour où l'argent est réellement
     * sorti.
     *
     * ─── 3. UN SEUL RÈGLEMENT SE RE-CALE ───
     *
     * Plusieurs règlements sont des versements distincts, saisis un par un à
     * l'écran de règlement. Les fondre en une pièce unique détruirait des faits
     * que la rectification d'un achat n'a pas à arbitrer : on les laisse, et la
     * facture dit alors ce qui est vrai — partiellement réglée au nouveau
     * total, ou trop-perçue. C'est précisément ce à quoi sert le registre.
     */
    private function recalerLeReglement(
        SupplierInvoice $invoice,
        FeedPurchase $feedPurchase,
        array $data,
        ?string $dateAvant
    ): void {
        $reglements = $invoice->payments()->get();

        if ($reglements->count() !== 1) {
            return;
        }

        $ancien = $reglements->first();

        // Par le MODÈLE : c'est ce qui déclenche la contre-passation.
        $ancien->delete();

        $dateReglement = $ancien->payment_date?->toDateString();

        SupplierPayment::create([
            'supplier_invoice_id' => $invoice->id,
            'amount'              => $feedPurchase->total_price,
            'payment_date'        => ($dateReglement === null || $dateReglement === $dateAvant)
                ? $data['purchase_date']
                : $dateReglement,
            'method'              => $ancien->method ?: 'especes',
            'treasury_account_id' => $ancien->treasury_account_id,
            'reference'           => $ancien->reference,
            'notes'               => $this->motifRectifie($ancien->notes),
            'paid_by'             => $ancien->paid_by ?: Auth::id(),
        ]);
    }

    /** Le motif du règlement re-calé : le sien, marqué une seule fois. */
    private function motifRectifie(?string $motif): string
    {
        $motif = trim((string) $motif) ?: "Réglé à l'achat (aliment)";

        return str_contains($motif, 'rectifié') ? $motif : $motif . ' — rectifié';
    }
}
