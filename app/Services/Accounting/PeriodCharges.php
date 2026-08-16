<?php

namespace App\Services\Accounting;

use App\Models\Batch;
use App\Models\EnergyReading;
use App\Models\Expense;
use App\Models\HealthCheck;
use App\Models\HealthIncident;
use App\Models\Payslip;
use App\Models\WaterReading;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CHARGES D'ÉLEVAGE D'UNE PÉRIODE — déclaration UNIQUE.
 *
 * Deux écrans répondaient à « combien ai-je gagné ce mois-ci », et ils ne
 * comptaient pas les mêmes charges.
 *
 *   • le COMPTE DE RÉSULTAT additionne l'achat des animaux, l'aliment consommé,
 *     la santé, LA PAIE, l'eau, l'énergie réseau, le carburant et les dépenses
 *     validées ;
 *   • le TABLEAU DE BORD ne retenait que l'aliment, la santé et les dépenses
 *     validées.
 *
 * Manquaient donc à la marge du tableau de bord LES DEUX PLUS GROS POSTES d'un
 * élevage : la masse salariale et l'achat des bandes. Le promoteur, à
 * l'étranger, voit ce tableau EN PREMIER — et il annonçait systématiquement un
 * résultat plus favorable que la réalité, sans rien dire de ce qu'il omettait.
 *
 * Le commentaire du tableau de bord affirmait pourtant inclure « la main
 * d'œuvre » : il parlait de la CATÉGORIE DE DÉPENSE « main-d'œuvre journalière »,
 * pas des bulletins de paie. Exact au mot près, trompeur à la lecture.
 *
 * Les composantes vivent désormais ICI, et les deux écrans les appellent. Une
 * règle recopiée est une règle qui diverge : c'est la leçon de tout cet audit.
 *
 * ─── CONVENTIONS CONSERVÉES TELLES QUELLES ───
 *
 * Elles viennent du compte de résultat, qui les documente et les tenait déjà :
 *
 *   • ALIMENT : imputé à la CONSOMMATION, pas à l'achat (rattachement) ;
 *   • ÉNERGIE : les groupes électrogènes sont EXCLUS — leur coût est une
 *     estimation du gasoil brûlé, déjà porté par la ligne « Carburant » du
 *     registre des dépenses. Les compter ici doublerait le carburant ;
 *   • CARBURANT : lu comme poste dédié depuis les dépenses, et retiré de la
 *     ventilation générique pour la même raison ;
 *   • PAIE : les bulletins des périodes qui COMMENCENT dans l'intervalle, au
 *     net versé.
 *
 * ─── DÉMARQUE ALIMENT : POURQUOI ELLE, ET ELLE SEULE ───
 *
 * La démarque était chiffrée, exportée, affichée en tuile — et ne pesait sur
 * aucun résultat. Un sac volé sortait du magasin sans jamais coûter un franc au
 * compte de résultat : le bénéfice affiché était surévalué du montant des
 * pertes, précisément sur le poste que le promoteur, à l'étranger, surveille.
 *
 * On n'ajoute pourtant QUE l'aliment, et c'est un raisonnement, pas une
 * timidité. L'aliment est imputé À LA CONSOMMATION (ci-dessus) : ce qui est volé
 * n'est jamais consommé, donc jamais compté — l'ajouter ne peut pas
 * double-compter. Les autres catégories sont chargées À L'ACHAT : les matières
 * premières de la provenderie passent par le module Dépenses (convention
 * verrouillée par RawMaterialEntryIsInventoryOnlyTest), les consommables aussi.
 * Leur démarque est déjà dans les charges, au jour de l'achat ; l'y remettre
 * gonflerait les charges d'autant.
 *
 * Les GAINS d'inventaire ne sont pas déduits. Ce n'est pas une symétrie oubliée :
 * le coût de l'aliment ne vient pas du stock mais des pointages, si bien qu'un
 * écart positif ne correspond à aucune charge déjà prise — la déduire créerait
 * un avoir sorti de nulle part. On reconnaît la perte, pas le gain latent.
 */
class PeriodCharges
{
    /**
     * @return array<string, float>  libellé de charge => montant
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        $expensesByCategory = Expense::validated()
            ->betweenDates($from, $to)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $costs = [
            'Achats animaux (lots)' => (float) Batch::live()
                ->whereBetween('arrival_date', [$from, $to])
                ->sum('total_acquisition_cost'),

            'Aliment' => self::feedConsumedCost($from, $to),

            /*
             * DEUX SOURCES, comme la marge du lot — qui les additionnait déjà.
             *
             * Cette ligne ne sommait que les actes du registre. Le coût de
             * traitement d'un INCIDENT sanitaire, saisi au diagnostic
             * vétérinaire, était lu par la marge du lot, la tuile du module
             * santé et le rapport sanitaire — jamais ici. Une épidémie traitée
             * à 2 000 000 amputait la marge du lot de 2 000 000 et le résultat
             * de la ferme de zéro : la somme des marges ne pouvait pas tomber
             * juste, et l'écart se cherchait du côté des ventes.
             *
             * Aucun double comptage, et Batch le disait déjà en toutes lettres :
             * « champ dédié, non capté ailleurs ». La boucle était fermée pour
             * le lot ; elle restait ouverte pour la ferme.
             *
             * Borne de période : `incident_date`, comme le rapport sanitaire.
             * Deux écrans qui chiffrent la même épidémie doivent la ranger dans
             * le même mois.
             */
            'Santé / prophylaxie' =>
                (float) HealthCheck::whereBetween('intervention_date', [$from, $to])->sum('cost')
                + (float) HealthIncident::whereBetween('incident_date', [$from, $to])->sum('treatment_cost'),

            'Main d\'œuvre (paie)' => (float) Payslip::whereHas(
                'period',
                fn ($q) => $q->whereBetween('start_date', [$from, $to])
            )->sum('net_salary'),

            'Eau' => (float) WaterReading::whereBetween('reading_date', [$from, $to])->sum('cost'),

            // PAS de filtre `energy_sources.deleted_at` ICI, et c'est délibéré.
            // Supprimer une source ne doit pas RÉÉCRIRE le passé : le gasoil brûlé
            // par un groupe a bien coûté ce qu'il a coûté, et l'exclure ferait
            // baisser après coup le coût énergie d'une période déjà arrêtée. Un
            // balayage des lectures brutes signale cette jointure ; la garder est
            // un choix, pas un oubli (cf. MobileFarmContextReachTest).
            'Énergie réseau (EDG)' => (float) EnergyReading::query()
                ->join('energy_sources', 'energy_sources.id', '=', 'energy_readings.energy_source_id')
                ->whereBetween('energy_readings.reading_date', [$from, $to])
                ->where('energy_sources.type', '!=', 'groupe')
                ->sum('energy_readings.cost'),

            'Carburant' => (float) ($expensesByCategory['carburant'] ?? 0),

            'Démarque aliment' => self::feedShrinkageCost($from, $to),
        ];

        foreach ($expensesByCategory as $category => $total) {
            if ($category === 'carburant') {
                continue;   // déjà porté par la ligne dédiée
            }

            $label = 'Dépenses : ' . (Expense::CATEGORIES[$category] ?? ucfirst((string) $category));
            $costs[$label] = ($costs[$label] ?? 0) + (float) $total;
        }

        return $costs;
    }

    /**
     * Valeur de l'aliment PERDU sur la période (démarque).
     *
     * Les pertes seules — cf. l'en-tête pour la raison de ne pas y opposer les
     * gains d'inventaire. La valeur est celle FIGÉE par l'ajustement
     * (`value_impact`, au CMP du jour), la même que lit l'écran de démarque :
     * deux chiffres tirés du même champ ne peuvent pas diverger.
     */
    public static function feedShrinkageCost(Carbon $from, Carbon $to): float
    {
        // Le tri « aliment / autre consommable » vit sur le modèle Stock
        // (isFeed) : la catégorie `conso` porte « Aliment & Santé », et les
        // médicaments sont chargés au prix d'achat, donc déjà comptés.
        return round((float) \App\Models\StockAdjustment::with('stock')
            ->where('type', 'perte')
            ->whereBetween('adjustment_date', [$from, $to])
            ->get()
            ->filter(fn ($a) => $a->stock?->isFeed())
            ->sum('value_impact'), 2);
    }

    /**
     * Coût de l'ALIMENT CONSOMMÉ sur la période (COGS aliment).
     *
     * Chaque consommation de pointage est valorisée au coût FIGÉ à la saisie
     * (`feed_unit_cost`, cohérent avec la fiche lot) ; à défaut, repli sur le
     * CMP courant de l'article correspondant.
     *
     * C'est la version du COMPTE DE RÉSULTAT qui fait foi, et non celle,
     * simplifiée, que le tableau de bord utilisait : celle-ci ignorait le repli
     * et comptait donc zéro pour toute consommation dont la valorisation
     * n'avait pas été figée. Aligner les deux écrans sur la moins complète
     * aurait corrigé la divergence en dégradant le chiffre juste.
     */
    public static function feedConsumedCost(Carbon $from, Carbon $to): float
    {
        // Carte de repli : CMP courant par nom d'article / type d'aliment.
        $cmpByName = [];

        foreach (\App\Models\Stock::where('category', \App\Models\Stock::CAT_CONSO)->get() as $s) {
            $cmp = (float) ($s->last_unit_price ?? $s->unit_price ?? 0);

            if ($cmp <= 0) {
                continue;
            }

            if ($s->item_name) {
                $cmpByName[trim($s->item_name)] = $cmp;
            }

            if ($s->feed_type) {
                $cmpByName[trim($s->feed_type)] = $cmp;
            }
        }

        $total = 0.0;

        \App\Models\DailyCheck::whereBetween('check_date', [$from, $to])
            ->where('feed_consumed', '>', 0)
            ->get(['feed_consumed', 'feed_unit_cost', 'feed_type'])
            ->each(function ($f) use (&$total, $cmpByName) {
                $snapshot = (float) ($f->feed_unit_cost ?? 0);

                $unitCost = $snapshot > 0
                    ? $snapshot
                    : (float) ($cmpByName[trim((string) $f->feed_type)] ?? 0);

                $total += (float) $f->feed_consumed * $unitCost;
            });

        return round($total, 2);
    }
}
