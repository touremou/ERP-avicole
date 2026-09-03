<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\Stock;
use App\Models\Batch;
use App\Services\NotificationHub;
use App\Services\StockIntegrationService;
use App\Services\UnitConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ValidateSale
{
    /**
     * Valide une vente : effectue le déstockage et met à jour les compteurs.
     *
     * Le déstockage ne se fait QU'À LA VALIDATION, pas à la création (brouillon).
     * Ça permet de modifier/annuler un brouillon sans impacter les stocks.
     */
    public function execute(Sale $sale): Sale
    {
        if ($sale->status !== 'brouillon') {
            throw new Exception("La vente {$sale->reference} est déjà validée (statut: {$sale->status}).");
        }

        return DB::transaction(function () use ($sale) {

            // ─── 0. CRÉDIT CLIENT ───
            // C'est ICI que la créance naît : le solde ne bouge qu'à la
            // validation (recalculateBalance ignore les brouillons), et la
            // marchandise ne sort qu'ici. La règle n'était pourtant appliquée
            // qu'à la CRÉATION, et sur le seul formulaire du bureau : une vente
            // venue du terrain, ou créée avant que le client soit suspendu,
            // passait sans jamais rencontrer son plafond.
            //
            // Le crédit examiné est ce qui restera dû sur CETTE vente, une fois
            // déduits les règlements déjà encaissés dessus.
            $sale->loadMissing('client');
            $reste = (float) $sale->total_amount - (float) $sale->payments()->sum('amount');

            if ($sale->client && $raison = $sale->client->creditRefusalReason($reste)) {
                throw new Exception("Vente {$sale->reference} : {$raison}");
            }

            // ─── 1. VÉRIFIER ET DÉSTOCKER CHAQUE LIGNE ───
            foreach ($sale->items as $item) {

                // Articles stockés (œufs, lait, aliment, produits_finis, matériel)
                if ($item->requiresDestock()) {
                    $this->destockItem($item);
                }

                // Animal vif vendu à la tête → décrémenter l'effectif du lot
                if ($item->decrementsBatchCount()) {
                    $this->destockBatch($item);
                }

                // Fumier, "autre", ventes au poids (carcasse) : pas de déstockage physique
            }

            // ─── 2. MARQUER COMME VALIDÉ ───
            $sale->update([
                'status'       => 'valide',
                'validated_at' => now(),
            ]);

            // ─── 3. METTRE À JOUR LE SOLDE CLIENT ───
            $sale->client->recalculateBalance();

            Log::info("Vente validée : {$sale->reference} — Déstockage effectué.");

            // Visibilité admin/propriétaire (hors site) sur chaque vente validée
            app(NotificationHub::class)->notifySaleCreated($sale->fresh(['client']));

            return $sale->fresh();
        });
    }

    /**
     * Déstocke un article du stock (œufs, aliment, matériel).
     */
    private function destockItem($item): void
    {
        // AUDIT C1 (prouvé par drill parallèle) : sans verrou, deux validations
        // simultanées du même dernier stock passaient TOUTES LES DEUX le
        // contrôle ci-dessous (sur-vente silencieuse). lockForUpdate sérialise
        // le contrôle de disponibilité — la transaction de execute() l'englobe.
        // Résolution PARTAGÉE avec l'annulation et le retour (SaleItem::
        // resolveStock) : c'est la divergence entre ces trois chemins qui
        // faisait revenir la marchandise dans un autre article que celui d'où
        // elle était sortie. Le verrou est repris ici, sur l'article résolu.
        $resolved = $item->resolveStock();
        $stock = $resolved ? Stock::lockForUpdate()->find($resolved->id) : null;

        if (! $stock) {
            // product_id explicite mais stock disparu → vraie anomalie (FK), on bloque.
            if ($item->product_id) {
                throw new Exception("Stock introuvable pour '{$item->product_name}'. Impossible de valider.");
            }

            // Aucun stock cible (article catalogue NON suivi en stock, ou ligne en
            // saisie libre sans article de stock) : la vente est permise, on ne
            // décrémente simplement aucun stock.
            Log::warning("ValidateSale: ligne #{$item->id} ('{$item->product_name}') sans stock lié — vente sans déstockage.");
            return;
        }

        /*
         * ─── ON CONTRÔLE CE QU'ON VA RÉELLEMENT SORTIR ───
         *
         * Ce contrôle comparait `$item->quantity` — la quantité dans l'unité de
         * SAISIE — à `$stock->current_quantity`, qui est dans l'unité PIVOT de
         * la catégorie (KG pour l'aliment, Alvéole pour les œufs). Deux nombres
         * dans deux unités, comparés directement. La sortie juste en dessous,
         * elle, convertit. Le garde et le geste ne parlaient pas la même langue.
         *
         * Mesuré, dans les deux sens :
         *
         *   • 5 sacs d'aliment (250 kg) vendus sur 100 kg en stock : « 5 < 100 »
         *     → contrôle PASSÉ, 250 kg sortis, stock plafonné à zéro. Une vente
         *     de 150 kg qui n'existaient pas, validée sans un mot ;
         *   • 300 œufs vendus à la pièce sur 20 alvéoles (600 œufs, largement
         *     assez) : « 300 > 20 » → vente REFUSÉE.
         *
         * La conversion se fait donc UNE fois, et le même nombre sert au
         * contrôle et au mouvement.
         */
        $needed = UnitConverter::toStockBase(
            (float) $item->quantity,
            $item->stockInputUnit(),
            $stock->category,
        );

        if ((float) $stock->current_quantity + 0.0001 < $needed) {
            throw new Exception(
                "Stock insuffisant pour '{$item->product_name}' : " .
                "besoin {$item->quantity} {$item->unit} (= {$needed} {$stock->unit}), " .
                "disponible {$stock->current_quantity} {$stock->unit}."
            );
        }

        // Utiliser StockIntegrationService pour la traçabilité.
        // On passe l'IDENTITÉ RÉELLE du stock déjà résolu ($stock->item_name +
        // $stock->category) — et non des valeurs dérivées du product_type — afin
        // que findStock() retrouve exactement cet article, quelle que soit sa
        // catégorie (œufs, lait, aliment… mais aussi litière, matériel, etc.).
        //
        // `strictOut` : sans lui, une sortie plus grande que le stock est
        // PLAFONNÉE à zéro, en silence — c'est ce plafonnement qui a absorbé les
        // 150 kg manquants ci-dessus.
        //
        // Aucun test ne le tue, et c'est VOULU : le contrôle ci-dessus le rend
        // inatteignable. Son propre contrat est couvert ailleurs
        // (`CropMatterConservationTest`). Il est ici pour qu'une divergence
        // future entre le contrôle et le mouvement s'ANNONCE au lieu de se
        // dissoudre dans le stock — c'est-à-dire pour que le défaut corrigé ici
        // ne puisse pas se reformer en silence.
        StockIntegrationService::syncMovement(
            $stock->item_name,
            $stock->category,
            (float) $item->quantity,
            'out',
            "Vente {$item->sale->reference} — Client: {$item->sale->client->name}",
            $item->stockInputUnit(),
            strictOut: true,
        );
    }

    /**
     * Décrémente l'effectif d'un lot (animal vif vendu à la tête, toute espèce).
     */
    private function destockBatch($item): void
    {
        // AUDIT C1 : même motif que destockItem — le contrôle d'effectif doit
        // être sérialisé (deux ventes parallèles du dernier sujet, sinon).
        $batch = Batch::lockForUpdate()->find($item->batch_id);

        if (! $batch) {
            throw new Exception("Lot introuvable (id={$item->batch_id}) pour la ligne '{$item->product_name}'.");
        }

        if ($batch->status !== 'Actif') {
            throw new Exception("Le lot {$batch->code} n'est pas actif (statut: {$batch->status}).");
        }

        // Biosécurité : lot sous QUARANTAINE sanitaire → vente à la tête
        // interdite. La levée est une DÉCISION, prise via le module Santé.
        //
        // (Ce commentaire invoquait le « délai d'attente médicamenteux » — qui
        // est l'autre règle, celle du bloc suivant. Les deux sont distinctes :
        // la quarantaine se lève sur décision, le délai d'attente tout seul à
        // l'échéance. Le texte annonçait donc une garde que le code ne faisait
        // pas.)
        if ($quarantine = $batch->activeQuarantine()) {
            throw new Exception(
                "Le lot {$batch->code} est en QUARANTAINE sanitaire"
                . ($quarantine->quarantine_started_at ? ' depuis le ' . $quarantine->quarantine_started_at->format('d/m/Y') : '')
                . " — vente interdite jusqu'à la levée (incident santé n°{$quarantine->id})."
            );
        }

        /*
         * DÉLAI D'ATTENTE (résidus médicamenteux) — le TROISIÈME objet de la
         * même règle, et le seul qui restait ouvert.
         *
         * Après un vaccin ou un traitement, la notice interdit la consommation
         * avant l'échéance. La règle bloquait l'abattage interne
         * (SlaughterService) et, depuis #235, la mise en vente des œufs. Vendre
         * les bêtes VIVANTES restait libre — or l'acheteur les abat, et le
         * résidu part avec elles. La contrainte ne disparaît pas parce que
         * l'animal change de mains : elle sort de l'exploitation sans être
         * levée.
         *
         * Ce n'est pas une politique inventée ici : le garde ci-dessus
         * annonçait déjà cette interdiction dans son propre commentaire, en
         * l'attribuant par erreur à la quarantaine.
         *
         * La levée est AUTOMATIQUE à l'échéance — aucune décision à prendre,
         * juste le temps qui passe. On la nomme, pour que le refus dise quand
         * il tombera.
         */
        if ($withdrawal = $batch->activeWithdrawal()) {
            throw new Exception(
                "Le lot {$batch->code} est sous DÉLAI D'ATTENTE jusqu'au "
                . $withdrawal->withdrawal_until->format('d/m/Y')
                . " ({$withdrawal->withdrawal_days_left} j restants) suite à « {$withdrawal->product_name} »"
                . ' du ' . $withdrawal->intervention_date->format('d/m/Y')
                . ' — vente sur pied interdite (résidus médicamenteux).'
            );
        }

        $qty = (int) $item->quantity;
        if ($batch->current_quantity < $qty) {
            throw new Exception(
                "Effectif insuffisant dans le lot {$batch->code} : " .
                "besoin {$qty}, disponible {$batch->current_quantity}."
            );
        }

        $batch->decrement('current_quantity', $qty);

        Log::info("Vente {$item->sale->reference} : {$qty} sujets vendus du lot {$batch->code}.");
    }
}
