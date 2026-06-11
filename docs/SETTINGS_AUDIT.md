# Audit des paramètres système (Paramètres > …)

Objectif : recenser les paramètres définis en base (`settings`) mais **non lus**
par le code, afin d'éviter l'effet « le réglage ne s'applique pas » (cause de
confusion côté administrateur).

Méthode : pour chaque clé `groupe.clé`, recherche d'une référence dans `app/`,
`resources/` et `database/seeders/`. 97 paramètres au total, 42 non référencés
au moment de l'audit.

## Déjà câblés (le réglage s'applique réellement)

- `general.species_enabled` — supprimé (source de vérité = table `species`).
- `production.egg_grades` — pilote les calibres (`EggProduction::activeGrades()`).
- `general.company_logo` — sélecteur de fichier + affichage menu/PDF.
- `general.timezone` — appliqué au runtime (`AppServiceProvider`).
- `general.country` — en-tête des documents imprimés.
- `ventes.invoice_prefix_bl` / `ventes.invoice_prefix_tva` — `SaleNumberingService`.
- `ventes.invoice_footer` — pied de page des factures/BL.
- `ventes.payment_delay_days` — échéance affichée sur la facture.
- `ventes.credit_limit_default` — plafond crédit pré-rempli à la création client.
- `rh.payment_methods` — options du mode de paiement (paie).
- `rh.payslip_footer` — pied de page du bulletin imprimé.
- `rh.annual_leave_days` — dotation initiale du solde de congés à l'embauche (+ affichage fiche agent).
- `couvoir.fertility_target` / `couvoir.hatchability_target` — cibles + coloration KPI (repro).
- `couvoir.mirage_day` — date de mirage prévue (J+n) affichée sur chaque incubation en cours.
- `elevage.batch_prefix_chair` / `batch_prefix_ponte` / `batch_prefix_repro` — préfixe du code de lot appliqué selon le type (formulaire de création).
- `abattoir.yield_cutting` — cible de rendement découpe (affichage + coloration en temps réel).
- `provenderie.fc_target_chair` / `fc_target_ponte` / `fc_alert` — évaluation de l'indice de consommation (IC) par type sur la fiche lot.
- `energie.water_price_m3` — coût d'un relevé d'eau estimé depuis le volume si non saisi.
- `energie.kwh_price_edg` — KPI « valeur produite (éq. EDG) » au tableau de bord énergie.
- `rh.overtime_rate` — calcul des heures supplémentaires (prime majorée) sur le bulletin.
- `pisciculture.taux_survie_cible` / `pisciculture.fc_cible` — cibles de survie et d'indice de consommation affichées (badges colorés) sur le rapport Pisciculture (`reports.aquaculture`).
- `pisciculture.cycle_tilapia` / `pisciculture.cycle_carpe` — durée de cycle de grossissement par espèce, utilisée pour le badge « Cycle » (jours restants avant récolte) du rapport Pisciculture.

## Restants — en attente de leur fonctionnalité consommatrice

Ces clés n'ont **aucun code consommateur aujourd'hui** : les câbler revient à
**construire la fonctionnalité** correspondante (KPI, calcul de coût, colonne
BDD, module). Elles sont conservées (ni masquées ni supprimées) et seront
câblées à la construction de chaque module.

| Groupe | Clés | Ce qu'il reste à construire pour les câbler |
|--------|------|----------------------------------------------|
| energie | autonomy_alert_hours | Alerte d'autonomie du groupe électrogène (heures restantes) |
| production | peak_laying_week | Courbe de ponte / écart au pic |
| elevage | cycle_caille_*, cycle_dinde_chair, cycle_caprin_lait, cycle_ovin_reproducteur, gmq_cible_*, lait_cible_chevre, tabaski_target_weight | Modules ruminants / volaille secondaire (cycles & cibles) |
| whatsapp | api_url, admin_phone, daily_summary_hour | Intégration notifications WhatsApp |

> Principe retenu : un paramètre visible doit s'appliquer. Tout réglage disposant
> d'un consommateur a été câblé ; les restants sont câblés au fil de la
> construction de leur module (jamais masqués ni supprimés, pour ne pas casser
> la reprise du module).
