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
- `energie.autonomy_alert_hours` — seuil d'autonomie gasoil (en heures de fonctionnement) comparé à la consommation horaire moyenne des groupes électrogènes (`EnergySource::is_fuel_low` / `fuel_autonomy_hours`) ; affiché sur le tableau de bord énergie, la fiche source d'énergie, les alertes (`UtilityService::getAlerts()`) et la notification WhatsApp gasoil critique.
- `production.peak_laying_week` — semaine de référence du pic de ponte comparée à la semaine d'âge courante de chaque lot pondeuse, affichée sous forme de badge « Montée / Pic / Post-pic » dans le suivi technique (`egg-productions.index`).
- `elevage.cycle_caille_ponte` / `cycle_caille_chair` / `cycle_dinde_chair` / `cycle_caprin_lait` / `cycle_ovin_reproducteur` — durées de cycle par espèce/type utilisées en repli (`Batch::calculateExpectedEndDate()`) pour calculer `expected_end_date` quand le lot n'a pas de `production_type_id`.
- `elevage.gmq_cible_caprin` / `gmq_cible_ovin` — cible de Gain Moyen Quotidien (g/j) selon l'espèce, utilisée pour la coloration du badge GMQ sur la fiche lot (`batches.show`).
- `elevage.lait_cible_chevre` — cible de production laitière par tête (L/j) comparée au rendement du jour sur la liste des lots laitiers (`milk-productions.index`).
- `elevage.tabaski_target_weight` — poids cible de vente (kg) pour les lots ovins, utilisé comme cible de la barre de progression « Poids Moyen » sur la fiche lot (`batches.show`) en l'absence de norme zootechnique.
- `whatsapp.daily_summary_hour` — heure de planification du résumé quotidien WhatsApp (`avismart:daily-summary` dans `routes/console.php`).
- `whatsapp.admin_phone` — destinataire de secours pour les alertes critiques (mortalité, stock, gasoil, fraude) même si l'admin n'est pas explicitement abonné (`NotificationHub::broadcast()`) ; pré-remplit aussi le numéro personnel sur la page Notifications si celui-ci est vide.
- `whatsapp.driver` — détermine si le bouton « Tester » (Notifications) peut réellement délivrer un message (mode "log" = aucun envoi réel, banni avec message explicite).
- `whatsapp.large_sale_threshold` — montant d'une vente validée au-delà duquel l'alerte est escaladée en critique (donc envoyée au numéro admin de secours même sans abonnement) ; `NotificationHub::notifySaleCreated()`. 0 = désactivé.
- `whatsapp.business_hours_start` / `whatsapp.business_hours_end` — plage des heures ouvrées ; toute vente validée ou tout encaissement enregistré hors de cette plage est escaladé en alerte critique (`NotificationHub::isAfterHours()`). Plage vide = détection désactivée.
- `whatsapp.api_url` — URL de base personnalisée pour les drivers `ultramsg`/`wati` (instance auto-hébergée), utilisée par `WhatsAppService`.

## Restants — en attente de leur fonctionnalité consommatrice

Ces clés n'ont **aucun code consommateur aujourd'hui** : les câbler revient à
**construire la fonctionnalité** correspondante (KPI, calcul de coût, colonne
BDD, module). Elles sont conservées (ni masquées ni supprimées) et seront
câblées à la construction de chaque module.

| Groupe | Clés | Ce qu'il reste à construire pour les câbler |
|--------|------|----------------------------------------------|
| _(aucune)_ | — | Tous les chantiers recensés dans cet audit ont été câblés. Toute nouvelle clé ajoutée au catalogue `settings` devra suivre le même principe : être référencée par un consommateur réel avant (ou en même temps) que sa publication dans l'UI. |

> Principe retenu : un paramètre visible doit s'appliquer. Tout réglage disposant
> d'un consommateur a été câblé ; les restants sont câblés au fil de la
> construction de leur module (jamais masqués ni supprimés, pour ne pas casser
> la reprise du module).
