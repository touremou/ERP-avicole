<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CE QUI DISPARAÎTRAIT AVEC CET ENREGISTREMENT.
 *
 * La corbeille propose une « suppression irréversible », et son propre
 * commentaire annonçait la garde correspondante : « On empêche la suppression
 * physique si l'élément a laissé des traces (ex : factures, pointages) ». La
 * ligne suivante avouait le contraire — « c'est ici que l'on POURRAIT vérifier
 * des relations complexes ». Le code décrivait un contrôle qu'il ne faisait pas.
 *
 * Ce que cela coûte n'est pas théorique : les clés étrangères de cette base sont
 * en CASCADE.
 *
 *   • supprimer un LOT archivé emporte ses pointages journaliers, ses
 *     interventions sanitaires, ses achats d'aliment, ses collectes d'œufs, ses
 *     reproducteurs, ses tâches — soit tout l'historique technique ET le coût de
 *     revient de la bande ;
 *   • supprimer un EMPLOYÉ archivé emporte ses bulletins de paie, ses pointages,
 *     ses congés… ET SES LOTS (`batches.employee_id` est en cascade), qui
 *     emportent à leur tour tout ce qui précède.
 *
 * Un clic sur « Suppression irréversible » pouvait donc effacer des années
 * d'élevage, en annonçant « Suppression irréversible effectuée ».
 *
 * ─── LA DÉTECTION EST DÉRIVÉE DU SCHÉMA ───
 *
 * On ne dresse pas la liste des dépendances à la main : elle serait incomplète
 * le jour où une table s'ajoute, et une garde incomplète sur un geste
 * irréversible vaut à peine mieux que pas de garde. On interroge donc les CLÉS
 * ÉTRANGÈRES réelles : toute table qui pointe vers celle-ci, quelle qu'elle
 * soit, compte.
 */
class DependencyGuard
{
    /**
     * Enregistrements qui seraient détruits ou orphelins avec celui-ci.
     *
     * @return array<string, int>  table => nombre de lignes qui pointent dessus
     */
    public static function blockers(Model $item): array
    {
        $table = $item->getTable();
        $key   = $item->getKey();

        $blockers = [];

        foreach (Schema::getTables() as $autre) {
            $nom = $autre['name'];

            if ($nom === $table) {
                continue;
            }

            foreach (Schema::getForeignKeys($nom) as $fk) {
                if (($fk['foreign_table'] ?? null) !== $table) {
                    continue;
                }

                // On ne gère que les clés simples : une clé composite ne
                // désigne pas un parent à elle seule.
                $colonnes = $fk['columns'] ?? [];
                if (count($colonnes) !== 1) {
                    continue;
                }

                $nombre = DB::table($nom)->where($colonnes[0], $key)->count();

                if ($nombre > 0) {
                    $blockers[$nom] = ($blockers[$nom] ?? 0) + $nombre;
                }
            }
        }

        return $blockers;
    }

    /**
     * CE QUI SERAIT DÉTRUIT — pas seulement ce qui pointe ici.
     *
     * `blockers()` compte TOUTE référence, et c'est la bonne question pour un
     * lot ou un employé : leurs dépendants doivent tous survivre.
     *
     * Pour un COMPTE UTILISATEUR, elle est trop large. Les cent soixante-treize
     * clés `SET NULL` de cette base — l'auteur d'un pointage, d'une dépense —
     * laissent l'enregistrement intact et n'ont donc rien à empêcher ; et
     * plusieurs enfants en CASCADE sont des réglages PERSONNELS, qui doivent
     * partir avec le compte. Appliquer `blockers()` tel quel rendrait tout
     * utilisateur indéboulonnable, ce qui reviendrait à supprimer la
     * fonctionnalité plutôt qu'à la corriger.
     *
     * On ne demande donc que ce qui compte pour une suppression PHYSIQUE : quels
     * enregistrements la cascade EFFACERAIT.
     *
     * ─── L'INVERSION QUI REND CETTE GARDE SÛRE ───
     *
     * On énumère les tables SANS IMPORTANCE, et on dérive tout le reste du
     * schéma. Une table ajoutée demain et oubliée ici BLOQUERA la suppression —
     * le sens prudent — au lieu d'être silencieusement détruite. C'est la même
     * raison qui fait que `LABELS`, plus bas, n'est qu'une courtoisie
     * d'affichage et jamais la liste de ce qui bloque.
     *
     * @param  array<int, string>  $sansImportance  tables dont la perte est voulue
     * @return array<string, int>  table => nombre de lignes qui disparaîtraient
     */
    public static function cascadeBlockers(Model $item, array $sansImportance = []): array
    {
        $table = $item->getTable();
        $key   = $item->getKey();

        $blockers = [];

        foreach (Schema::getTableListing() as $autre) {
            $nom = str_contains($autre, '.') ? substr($autre, strrpos($autre, '.') + 1) : $autre;

            if ($nom === $table || in_array($nom, $sansImportance, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($nom) as $fk) {
                if (($fk['foreign_table'] ?? null) !== $table) {
                    continue;
                }

                // Seule la CASCADE détruit. `SET NULL` et `RESTRICT` laissent
                // l'enregistrement en place — il n'y a rien à protéger.
                if (strtolower((string) ($fk['on_delete'] ?? '')) !== 'cascade') {
                    continue;
                }

                $colonnes = $fk['columns'] ?? [];
                if (count($colonnes) !== 1) {
                    continue;
                }

                $nombre = DB::table($nom)->where($colonnes[0], $key)->count();

                if ($nombre > 0) {
                    $blockers[$nom] = ($blockers[$nom] ?? 0) + $nombre;
                }
            }
        }

        return $blockers;
    }

    /** Phrase lisible : ce qui empêche la suppression, et en quelle quantité. */
    public static function describe(array $blockers): string
    {
        $parties = [];

        foreach ($blockers as $table => $nombre) {
            $parties[] = "{$nombre} × " . (self::LABELS[$table] ?? $table);
        }

        return implode(', ', $parties);
    }

    /**
     * Libellés lisibles des tables les plus fréquentes.
     *
     * Un nom absent de cette liste s'affiche tel quel : le tableau n'est
     * qu'une COURTOISIE d'affichage, jamais la liste de ce qui bloque — sans
     * quoi une table oubliée deviendrait une suppression autorisée.
     */
    private const LABELS = [
        'daily_checks'          => 'pointages journaliers',
        'health_checks'         => 'interventions sanitaires',
        'health_incidents'      => 'incidents sanitaires',
        'egg_productions'       => 'collectes d\'œufs',
        'feed_purchases'        => 'achats d\'aliment',
        'milk_productions'      => 'traites',
        'reproducers'           => 'reproducteurs',
        'batches'               => 'lots d\'élevage',
        'payslips'              => 'bulletins de paie',
        'employee_attendances'  => 'pointages de présence',
        'employee_leaves'       => 'congés',
        'sales'                 => 'ventes',
        'supplier_invoices'     => 'achats fournisseurs',
        'expenses'              => 'dépenses',
        'slaughter_orders'      => 'ordres d\'abattage',
        'incubations'           => 'incubations',
        'dispatches'            => 'expéditions',
        'cash_register_sessions' => 'clôtures de caisse',
    ];
}
