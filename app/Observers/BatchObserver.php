<?php

namespace App\Observers;

use App\Models\Batch;
use App\Services\CumulativeMortalityAlert;
use Illuminate\Support\Facades\Log;

class BatchObserver
{
    /**
     * Après mise à jour : vérifier la mortalité cumulée et alerter si la ligne
     * rouge vient d'être franchie.
     *
     * La règle elle-même vit dans CumulativeMortalityAlert : le pointage
     * journalier écrit l'effectif par requête directe, sans passer par Eloquent,
     * et devait donc pouvoir l'appeler lui aussi. Tant qu'elle vivait ici, le
     * geste quotidien — celui par lequel la mortalité arrive réellement — ne
     * déclenchait rien.
     */
    public function updated(Batch $batch): void
    {
        if (! $batch->wasChanged('current_quantity')) {
            return;
        }

        app(CumulativeMortalityAlert::class)
            ->evaluate($batch, (int) $batch->getOriginal('current_quantity'));
    }

    /**
     * Avant suppression soft-delete : cascade les enfants.
     */
    public function deleting(Batch $batch): void
    {
        if ($batch->isForceDeleting()) {
            return;
        }

        /*
         * ON NE CASCADE QUE VERS CE QUI EST RÉCUPÉRABLE.
         *
         * Cette cascade appelait `delete()` sur QUATRE relations, dont trois
         * dont le modèle n'utilisait pas la suppression douce. Sur celles-là,
         * `delete()` DÉTRUIT définitivement — alors que le lot, lui, part à la
         * corbeille et se présente comme récupérable.
         *
         * Mettre un lot à la corbeille effaçait donc pour de bon ses
         * interventions sanitaires, ses collectes d'œufs et ses achats
         * d'aliment. Aucun garde de dépendances ne s'y oppose : `destroy()` ne
         * vérifie que le droit.
         *
         *   • `healthChecks` : le trait vient d'être posé (la colonne
         *     `deleted_at` existait déjà) — la cascade est donc réversible ;
         *   • `eggProductions` : la table porte bien `deleted_at`, MAIS elle a
         *     un UNIQUE (batch_id, production_date). Une ligne masquée
         *     occuperait toujours la place, et la ressaisie d'une collecte du
         *     même jour échouerait sur la contrainte. On ne cascade donc pas ;
         *   • `feedPurchases` : pas de colonne `deleted_at` du tout.
         *
         * Pour ces deux dernières, les enregistrements RESTENT — rattachés à un
         * lot en corbeille, donc hors des écrans qui filtrent sur les lots
         * actifs, mais intacts. Un achat d'aliment et une collecte sont des
         * pièces comptables : les détruire pour obtenir un simple masquage est
         * hors de proportion.
         */
        $batch->dailyChecks()->delete();
        $batch->healthChecks()->delete();
    }

    /**
     * Restauration : rétablir les enfants soft-deleted.
     */
    public function restoring(Batch $batch): void
    {
        /*
         * LE GARDE « ANTI-CRASH » EMPÊCHAIT LA RESTAURATION QU'IL PROTÉGEAIT.
         *
         * Les deux appels étaient conditionnés par
         * `method_exists($batch->dailyChecks(), 'withTrashed')` — une vérification
         * qui rend TOUJOURS false : `withTrashed` n'est pas déclarée sur
         * `HasMany`, elle est atteinte par `__call` qui la transmet au
         * constructeur de requête. `method_exists()` ne voit que les méthodes
         * DÉCLARÉES ; l'appel, lui, fonctionne parfaitement.
         *
         * Conséquence : un lot sorti de la corbeille revenait SANS son histoire.
         * Ses pointages journaliers et ses interventions sanitaires restaient
         * supprimés — mortalité, consommation d'aliment, vaccinations. Le lot
         * réapparaissait avec son effectif juste (les cascades étant symétriques)
         * mais vide de tout ce qui l'explique.
         *
         * La suppression, elle, cascade bien : `deleting()` appelle
         * `->delete()` sans condition. L'aller marchait, le retour non.
         *
         * On appelle donc directement, sur les deux relations que la cascade de
         * suppression touche — et sur elles seules. C'est un test qui le vérifie
         * désormais, là où un `method_exists` ne pouvait rien dire.
         */
        $batch->dailyChecks()->withTrashed()->restore();
        $batch->healthChecks()->withTrashed()->restore();
    }
}
