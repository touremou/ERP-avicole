# Audit des paramètres système (Paramètres > …)

Objectif : recenser les paramètres définis en base (`settings`) mais **non lus**
par le code, afin d'éviter l'effet « le réglage ne s'applique pas » (cause de
confusion côté administrateur).

Méthode : pour chaque clé `groupe.clé`, recherche d'une référence dans `app/`,
`resources/` et `database/seeders/`. 97 paramètres au total, 42 non référencés
au moment de l'audit.

## Déjà traités

- `general.species_enabled` — **supprimé** (la source de vérité est la table `species`).
- `production.egg_grades` — **câblé** : pilote désormais réellement les calibres (voir `EggProduction::activeGrades()`).
- `general.company_logo` — **câblé** : sélecteur de fichier + affichage menu/PDF.
- `general.timezone` — **câblé** : appliqué au runtime (`AppServiceProvider`).

## Paramètres non câblés — échafaudage de modules en cours

⚠️ **Ne PAS supprimer** : ces clés correspondent à des modules/espèces déjà
présents ou planifiés (volaille secondaire, ruminants, pisciculture, couvoir,
énergie, RH, ventes, WhatsApp). Ce sont des réglages **à câbler au fur et à
mesure** de la construction de ces modules, pas du code mort.

| Groupe | Clés non référencées |
|--------|----------------------|
| elevage | batch_prefix_chair, batch_prefix_ponte, batch_prefix_repro, cycle_caille_chair, cycle_caille_ponte, cycle_caprin_lait, cycle_dinde_chair, cycle_ovin_reproducteur, gmq_cible_caprin, gmq_cible_ovin, lait_cible_chevre, tabaski_target_weight |
| pisciculture | cycle_carpe, cycle_tilapia, fc_cible, taux_survie_cible |
| couvoir | fertility_target, hatchability_target, mirage_day |
| energie | autonomy_alert_hours, kwh_price_edg, water_price_m3 |
| provenderie | fc_alert, fc_target_chair, fc_target_ponte |
| rh | annual_leave_days, overtime_rate, payment_methods, payslip_footer |
| ventes | credit_limit_default, invoice_footer, invoice_prefix_bl, invoice_prefix_tva, payment_delay_days |
| whatsapp | admin_phone, api_url, daily_summary_hour |
| abattoir | yield_cutting |
| production | peak_laying_week |
| general | country |

## Recommandation

- **Court terme** : masquer dans l'UI les groupes dont AUCUN paramètre n'est
  encore câblé (couvoir, énergie, pisciculture…) pour ne pas laisser croire
  qu'ils sont actifs, OU afficher un badge « à venir ».
- **À la construction de chaque module** : câbler ses paramètres (lire via
  `setting('groupe.clé')`) — comme cela a été fait pour `egg_grades`.
- **`general.country`** : informatif (à afficher sur les documents imprimés) —
  à câbler dans les en-têtes PDF si besoin.

> Principe retenu : un paramètre visible doit s'appliquer. Tant qu'un module
> n'est pas câblé, ses réglages restent de l'échafaudage documenté ici plutôt
> que supprimés (la suppression casserait la reprise du module).
