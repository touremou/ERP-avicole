<?php

namespace App\Actions\MillProduction;

use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Stock;
// NOUVEAUX IMPORTS
use App\Actions\Provenderie\RecordProductionConsumptionAction;
use App\Actions\Provenderie\NormalizeFormulaNameAction;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteMillProduction
{
    // INJECTION DES DEUX NOUVELLES ACTIONS (À LA PLACE DE STOCKSERVICE)
    public function __construct(
        private RecordProductionConsumptionAction $recordConsumption,
        private NormalizeFormulaNameAction $normalizeName
    ) {}

    public function execute(MillProduction $production): MillProduction
    {
        return DB::transaction(fn () => $this->completeLocked($production));
    }

    /**
     * DEUX CLÔTURES SIMULTANÉES CONSOMMAIENT LA MATIÈRE DEUX FOIS.
     *
     * La garde « déjà clôturée » lisait le statut de l'objet REÇU — une copie
     * chargée par la requête, avant que l'autre n'écrive. Deux requêtes
     * concurrentes voyaient donc toutes deux « Planifié » et passaient.
     *
     * Mesuré : deux clôtures du même ordre de 200 kg font tomber le maïs
     * concassé de 1 000 à 600 kg. QUATRE CENTS KILOS consommés pour deux cents
     * produits, et deux entrées d'aliment fini pour une seule fabrication.
     *
     * C'est le double-clic sur « Clôturer » depuis une connexion lente — le
     * geste exact que cette base garde partout ailleurs par un uuid
     * d'idempotence ou un refus de rejeu.
     *
     * La synchro mobile, elle, verrouillait déjà la ligne avant d'appeler cette
     * action (`MillProduction::lockForUpdate()`). Le web appelait la MÊME action
     * sans verrou : la protection tenait un chemin sur deux. Elle vit maintenant
     * dans l'action, donc dans les deux — et le verrou de la synchro, pris sur
     * la même ligne dans la même transaction, reste sans effet de bord.
     */
    private function completeLocked(MillProduction $production): MillProduction
    {
        // Relecture SOUS VERROU : entre le chargement par le contrôleur et cet
        // instant, un autre appel a pu clôturer l'ordre. C'est cet état-là que
        // la garde doit lire, pas celui de la copie en mémoire.
        $production = MillProduction::lockForUpdate()->findOrFail($production->getKey());

        if ($production->status === 'Terminé') {
            throw new \DomainException("L'OP #{$production->batch_number} est déjà clôturée.");
        }

        /*
         * UN ORDRE ANNULÉ NE SE CLÔTURE PAS.
         *
         * La synchro mobile refuse ce cas explicitement — « L'OP #:op a été
         * annulée » — juste à côté de son refus de double clôture. L'action, qui
         * porte pourtant le premier des deux refus, ignorait le second : le web
         * pouvait donc clôturer un ordre annulé.
         *
         * Ce n'est pas un changement de statut sans effet. La clôture CONSOMME
         * les matières premières : mesuré, un ordre annulé puis clôturé fait
         * passer le maïs de 1 000 à 800 kg et repasse l'ordre en « Terminé ».
         *
         * Or l'annulation existe précisément pour les ordres qui n'auront pas
         * lieu — panne, erreur de saisie — et son propre commentaire le dit :
         * « la consommation des matières premières n'a lieu qu'à la clôture,
         * donc aucun stock à contre-passer pour un OP planifié ». Cette phrase
         * n'est vraie que si un ordre annulé ne peut plus être clôturé.
         */
        if ($production->status === 'Annulé') {
            throw new \DomainException("L'OP #{$production->batch_number} a été annulée : "
                . 'elle ne peut plus être clôturée. Créez un nouvel ordre de production.');
        }

        $production->load(['formula.items.rawMaterial', 'formula.productionType.species', 'machine', 'machines']);
        $quantityProduced = (float) $production->quantity_produced;

        // ─── 1. VÉRIFICATION PRÉALABLE DES STOCKS MP ───
        $insufficientItems = [];
        foreach ($production->formula->items as $item) {
            $material = $item->rawMaterial;
            if (! $material) continue;

            $needed = ($item->percentage / 100) * $quantityProduced;
            if ($material->stock_qty < $needed) {
                $insufficientItems[] = "{$material->name} (besoin: " . round($needed, 1) .
                    " {$material->unit}, dispo: " . round($material->stock_qty, 1) . ")";
            }
        }

        if (! empty($insufficientItems)) {
            throw new \RuntimeException(
                "Stock insuffisant pour : " . implode(', ', $insufficientItems)
            );
        }

        // ─── 2. MAPPING NOM DE STOCK FINI (UTILISATION DE LA NOUVELLE ACTION) ───
        // On passe la formule pour cibler le secteur d'aliment de son espèce
        // (multiespèces : Chair/Ponte mais aussi Engraissement, Laitière...).
        $stockItemName = $this->normalizeName->execute(
            $production->formula->name,
            $production->formula
        );

        // ─── 2.bis PROVISION DU SILO D'ALIMENT FINI ───
        // Multiespèces : le silo cible peut ne pas encore exister (aucun
        // aliment n'est seedé). On le crée à la volée dans le bon secteur
        // pour que l'entrée de stock ci-dessous aboutisse quelle que soit
        // l'espèce, au lieu d'échouer sur « article introuvable ».
        $this->ensureFinishedFeedStock($stockItemName, $production->formula);

        return DB::transaction(function () use ($production, $quantityProduced, $stockItemName) {

            // ─── 3. DÉSTOCKAGE MP (UTILISATION DE LA NOUVELLE ACTION) ───
            $totalCost = $this->recordConsumption->execute($production);
            $realCostPerKg = $quantityProduced > 0
                ? round($totalCost / $quantityProduced, 2)
                : 0;

            /*
             * ─── 3.bis GARDE-FOU VALORISATION ───
             *
             * UNE SEULE matière sans prix suffit à fausser le coût de revient.
             *
             * Ce contrôle ne se déclenchait que si le coût TOTAL tombait à zéro —
             * c'est-à-dire si TOUTES les matières manquaient de prix. Une formule
             * dont une seule ligne n'était pas tarifée passait donc sans un mot.
             *
             * Mesuré : maïs 70 % à 3 000 GNF/kg, tourteau de soja 30 % sans prix.
             * L'aliment entrait au silo à 2 100 GNF/kg au lieu de 3 600 — 42 % de
             * sous-évaluation, sur une clôture ACCEPTÉE.
             *
             * ─── POURQUOI C'EST LA RACINE ───
             *
             * Ce coût fixe le CMP du silo d'aliment fini. Il devient donc le
             * `feed_unit_cost` figé à chaque pointage de consommation, donc le
             * `feed_cogs` de chaque bande qui en mange, donc sa marge, sa clôture,
             * le coût de sa campagne et la ligne « Aliment » du compte de
             * résultat. Une erreur ici se propage à tout ce qui chiffre l'élevage,
             * et toujours dans le même sens : elle flatte.
             *
             * ─── ON REFUSE, ON N'ESTIME PAS ───
             *
             * C'est la règle industrielle : un mouvement d'inventaire ne se poste
             * pas à un coût qu'on sait faux. Inventer un prix de repli rendrait le
             * total « complet », donc invisible, donc jamais corrigé. Le message
             * nomme les matières à tarifer et l'écran où le faire : le refus est
             * actionnable, il ne bloque pas, il oriente.
             *
             * Un ingrédient ORPHELIN (matière supprimée du référentiel) tombe sous
             * la même règle : sa part n'est ni déstockée ni valorisée — la formule
             * ne décrit plus ce qu'on fabrique.
             */
            $sansPrix = $production->formula->items
                ->filter(fn ($item) => $item->rawMaterial && (float) $item->rawMaterial->unit_cost <= 0)
                ->map(fn ($item) => $item->rawMaterial->name)
                ->values()
                ->all();

            if (! empty($sansPrix)) {
                throw new \DomainException(
                    "Clôture impossible : le coût de revient de l'aliment produit serait faussé. " .
                    "Les matières premières suivantes n'ont pas de prix unitaire (unit_cost = 0) : " .
                    implode(', ', $sansPrix) .
                    ". Renseignez les prix dans le module Provenderie > Matières Premières avant de relancer."
                );
            }

            $orphelins = $production->formula->items
                ->filter(fn ($item) => ! $item->rawMaterial)
                ->count();

            if ($orphelins > 0) {
                throw new \DomainException(
                    "Clôture impossible : {$orphelins} ingrédient(s) de la formule « {$production->formula->name} » " .
                    "renvoient à une matière première supprimée. Leur part n'est ni déstockée ni valorisée : " .
                    "le coût de revient serait sous-évalué. Corrigez la formule avant de relancer."
                );
            }

            // ─── 4. ENTRÉE STOCK ALIMENT FINI (valorisée au coût de revient) ───
            // Le CMP de l'article aliment fini intègre le coût de revient réel
            // de cette production : l'inventaire — et donc la consommation des
            // lots — est valorisé au prix de fabrication, comparable à un achat.
            $synced = StockIntegrationService::syncMovement(
                $stockItemName,
                'conso',
                $quantityProduced,
                'in',
                "Production OP #{$production->batch_number}",
                'KG',
                $realCostPerKg
            );

            if (! $synced) {
                throw new \RuntimeException(
                    "L'article '{$stockItemName}' est introuvable dans le catalogue stock. " .
                    "Vérifier le mapping."
                );
            }
            // ─── 4.5. VÉRIFICATION SÉCURITÉ MACHINES ───
            foreach ($production->machines as $machine) {
                if ($machine->status === 'En Panne') {
                    throw new \DomainException(
                        "Clôture impossible : la machine '{$machine->name}' est déclarée 'En Panne'. " .
                        "Veuillez enregistrer la maintenance et la remettre en statut 'Opérationnel' avant de valider l'OP."
                    );
                }
            }

            /*
             * ─── 5. USURE DES MACHINES ───
             *
             * On parcourt la relation PIVOT, et elle seule.
             *
             * Une variable `$allMachines` fusionnait ici la machine principale et
             * les machines du pivot, dédoublonnées — puis la boucle l'IGNORAIT.
             * Du code mort, mais qui annonçait une intention trompeuse : cette
             * fusion est inutilisable telle quelle, car un élément venu de
             * `$production->machine` ne porte AUCUN pivot, donc pas de
             * `snapshot_capacity_per_hour`. L'employer aurait planté à la clôture.
             *
             * La capacité FIGÉE au lancement n'existe que sur le pivot : c'est elle
             * qui donne des heures justes même si la machine a été reparamétrée
             * depuis. Le pivot est donc la bonne source, et la machine principale y
             * figure toujours — le web l'y met (machine_ids[0]) et la synchro le
             * fait désormais aussi.
             *
             * L'enjeu n'est pas cosmétique : `total_hours_run` déclenche
             * `needs_maintenance`, donc la maintenance préventive. Des heures non
             * comptées, c'est une machine qui casse sans avoir été révisée.
             */
            foreach ($production->machines as $machine) {
                // On utilise la capacité figée au moment de la création de l'OP !
                $capacityAtTheTime = (float) $machine->pivot->snapshot_capacity_per_hour;
                
                if ($capacityAtTheTime <= 0) continue;

                $hoursWorked = $quantityProduced / $capacityAtTheTime;
                $machine->increment('total_hours_run', $hoursWorked);
            }

            // ─── 6. FINALISATION OP ───
            $production->update([
                'status'          => 'Terminé',
                'finished_at'     => now(),
                'real_cost_per_kg' => $realCostPerKg,
            ]);

            return $production->fresh();
        });
    }

    /**
     * Garantit l'existence du silo d'aliment fini (article de stock « conso »)
     * correspondant, en le créant à 0 dans le secteur de la formule s'il est
     * absent. Idempotent (firstOrCreate sur item_name + category).
     */
    private function ensureFinishedFeedStock(string $itemName, Formula $formula): void
    {
        Stock::firstOrCreate(
            ['item_name' => $itemName, 'category' => Stock::CAT_CONSO],
            [
                'feed_type'        => $itemName,
                'unit'             => 'KG',
                'current_quantity' => 0,
                'alert_threshold'  => 0,
                'metadata'         => [
                    'poultry_type' => $formula->feedSector(),
                    'conso_type'   => 'Aliment',
                ],
            ]
        );
    }
}