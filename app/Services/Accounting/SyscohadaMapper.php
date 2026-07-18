<?php

namespace App\Services\Accounting;

use App\Models\Expense;

/**
 * Rattachement des lignes du Compte de Résultat aux comptes du plan comptable
 * SYSCOHADA révisé (OHADA), pour présenter le P&L « par nature » à un
 * expert-comptable ou une banque — SANS moteur d'écritures : c'est une vue de
 * regroupement des flux déjà consolidés, pas une comptabilité en partie double.
 *
 * On mappe chaque libellé de charge/produit à un compte (3 chiffres), puis on
 * regroupe par classe à 2 chiffres (le niveau du compte de résultat SYSCOHADA).
 */
class SyscohadaMapper
{
    /** Charges (classe 6) — libellé P&L exact → [compte, libellé du compte]. */
    private const CHARGE_ACCOUNTS = [
        'Achats animaux (lots)'          => ['602', 'Achats de matières premières et fournitures liées'],
        'Aliment'                        => ['602', 'Achats de matières premières et fournitures liées'],
        'Production végétale (cultures)' => ['602', 'Achats de matières premières et fournitures liées'],
        'Santé / prophylaxie'            => ['604', 'Achats stockés de matières et fournitures consommables'],
        'Eau'                            => ['605', 'Autres achats (eau, énergie, carburant)'],
        'Énergie réseau (EDG)'           => ['605', 'Autres achats (eau, énergie, carburant)'],
        'Carburant'                      => ['605', 'Autres achats (eau, énergie, carburant)'],
        "Main d'œuvre (paie)"            => ['661', 'Rémunérations directes versées au personnel'],
    ];

    /** Charges issues du registre des dépenses — clé de catégorie Expense → compte. */
    private const EXPENSE_ACCOUNTS = [
        'carburant'     => ['605', 'Autres achats (eau, énergie, carburant)'],
        'transport'     => ['612', 'Transports'],
        'entretien'     => ['624', 'Entretien, réparations et maintenance'],
        'fournitures'   => ['605', 'Autres achats (eau, énergie, carburant)'],
        'communication' => ['628', 'Frais de télécommunications'],
        'administratif' => ['631', 'Frais bancaires et administratifs'],
        'taxes'         => ['646', 'Impôts et taxes'],
        'location'      => ['622', 'Locations et charges locatives'],
        'main_oeuvre'   => ['661', 'Rémunérations directes versées au personnel'],
        'sante_animale' => ['604', 'Achats stockés de matières et fournitures consommables'],
        'eau_energie'   => ['605', 'Autres achats (eau, énergie, carburant)'],
        'divers'        => ['658', 'Charges diverses de gestion courante'],
    ];

    /** Repli explicite pour toute charge non cartographiée (reste en classe 60). */
    private const CHARGE_FALLBACK = ['608', 'Achats — autres'];

    /** Produits (classe 7) : toute vente d'une ferme intégrée → 701. */
    private const PRODUIT_DEFAULT = ['701', 'Ventes de produits finis'];

    /** Libellés des classes à 2 chiffres (regroupement du compte de résultat). */
    private const CLASS_LABELS = [
        '60' => 'Achats',
        '61' => 'Transports',
        '62' => 'Services extérieurs A',
        '63' => 'Services extérieurs B',
        '64' => 'Impôts et taxes',
        '65' => 'Autres charges',
        '66' => 'Charges de personnel',
        '67' => 'Frais financiers',
        '70' => 'Ventes',
        '71' => "Subventions d'exploitation",
        '75' => 'Autres produits',
    ];

    /** Compte SYSCOHADA d'une ligne de charge (libellé du P&L). */
    public function chargeAccount(string $label): array
    {
        if (isset(self::CHARGE_ACCOUNTS[$label])) {
            return self::CHARGE_ACCOUNTS[$label];
        }

        // « Dépenses : {Catégorie} » → clé de catégorie Expense → compte.
        $prefix = 'Dépenses : ';
        if (str_starts_with($label, $prefix)) {
            $catLabel = substr($label, strlen($prefix));
            $key = array_search($catLabel, Expense::CATEGORIES, true);
            if ($key !== false && isset(self::EXPENSE_ACCOUNTS[$key])) {
                return self::EXPENSE_ACCOUNTS[$key];
            }
        }

        return self::CHARGE_FALLBACK;
    }

    /** Compte SYSCOHADA d'une ligne de produit (toutes ventes → 701). */
    public function produitAccount(string $label): array
    {
        return self::PRODUIT_DEFAULT;
    }

    /**
     * Regroupe des lignes [libellé => montant] par classe SYSCOHADA à 2 chiffres,
     * avec le détail par compte et les sous-totaux. Renvoie une liste triée par
     * numéro de classe :
     *   [ ['class' => '60', 'class_label' => 'Achats', 'total' => …,
     *      'accounts' => [ ['account' => '602', 'label' => …, 'amount' => …], … ] ], … ]
     */
    public function group(array $lines, string $kind): array
    {
        $classes = [];

        foreach ($lines as $label => $amount) {
            $amount = (float) $amount;
            if ($amount == 0.0) continue;

            [$account, $accLabel] = $kind === 'produit'
                ? $this->produitAccount((string) $label)
                : $this->chargeAccount((string) $label);

            $class = substr($account, 0, 2);

            $classes[$class]['class']       = $class;
            $classes[$class]['class_label'] = self::CLASS_LABELS[$class] ?? $class;
            $classes[$class]['total']       = ($classes[$class]['total'] ?? 0) + $amount;

            $classes[$class]['accounts'][$account]['account'] = $account;
            $classes[$class]['accounts'][$account]['label']   = $accLabel;
            $classes[$class]['accounts'][$account]['amount']  =
                ($classes[$class]['accounts'][$account]['amount'] ?? 0) + $amount;
        }

        ksort($classes);

        return array_values(array_map(function ($c) {
            ksort($c['accounts']);
            $c['accounts'] = array_values($c['accounts']);
            return $c;
        }, $classes));
    }
}
