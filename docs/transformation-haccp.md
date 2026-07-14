# Module Transformation — cœur sanitaire HACCP

> Implémentation de la spec « BioCrest — évolution du module Transformation »
> (dossier projet, juillet 2026), **fusionnée** avec le module Abattoir
> existant — pas de schéma `tr_*` parallèle (hypothèse H1 levée).

## Correspondance spec → implémentation

| Spec | Implémentation | Statut |
|---|---|---|
| E1 Réception du vif (CCP 1) | `slaughter_receptions` + `RecordSlaughterReception` — web + mobile | ✅ P0 |
| E2 Lots & traçabilité | `SlaughterOrder` existant (+ `reception_id`) ; QR `/trace/*` existants | ✅ existant/étendu |
| E3 CCP 2/3/4 + blocage | `ccp_records` + `RecordCcp` — conformité **serveur**, blocage auto (RG-02) | ✅ P0 |
| E4 Registre températures + alertes | `temperature_logs` + `RecordTemperatureLog` + `alertHaccp` (WhatsApp/cloche/mail) | ✅ P0 |
| E5 Produits finis / rendement / stock | Déjà couvert (SlaughterResult, CuttingSession, FinishedProduct) | ✅ existant |
| E6 Étiquettes QR | Étiquettes imprimables web + pages `/trace/*` publiques (PR #5) | ✅ existant |
| E7 Registre nettoyage | `cleaning_logs` — web + mobile | ✅ P0 |
| E8 Abattage à façon | **Différé** (P1) — nécessite décision sur la facturation de prestation | ⏳ |
| E9 Sous-produits | **Différé** (P2) | ⏳ |
| E10 Dashboard + exports | Exports PDF registres (températures / CCP / nettoyage) ; dashboard abattoir existant | ✅ partiel |
| Impression Bluetooth ESC/POS (H6) | **Différé** — à tester physiquement sur le parc réel avant de figer | ⏳ |

## Décisions d'architecture (écarts assumés vs la spec)

- **Pas de tables `tr_*`** : les entités rejoignent le module abattoir
  (`slaughter_receptions`, `ccp_records`, `temperature_logs`, `cleaning_logs`)
  et l'ordre d'abattage existant reste l'unité de traçabilité (E2).
- **RBAC** : pas de spatie/laravel-permission — matrice Modules × L/C/M/S
  existante. Mapping : saisies CCP/registres → `abattoir.C`, blocage →
  `abattoir.M`, **libération → `abattoir.S`** (équivalent du rôle `qualite`).
- **Seuils dans le système `Setting`** (groupe « abattoir », clés
  `ccp3_core_temp_max`, `cold_positive_min/max`, `freezer_max`,
  `cutting_room_max`, `scalding_min/max`, `vehicle_max`,
  `ccp2_soiled_max_pct`, `temp_readings_per_day`) — modifiables à l'écran
  Réglages, audités (`setting_audits`). ⚠ Valeurs indicatives, à faire
  valider par le vétérinaire conseil.
- **Numérotation** : `DocumentNumberingService` (serveur) reste la source des
  numéros ; l'idempotence offline repose sur l'**uuid client** (comme le
  reste de la sync) — le schéma « préfixe par poste » de la spec n'est pas
  nécessaire car les ordres naissent au bureau.
- **Offline** : réutilise la couche existante (Dexie + outbox + `/sync/push`
  idempotent + bac « À corriger ») — §7 de la spec déjà en place.

## Règles de gestion implémentées

| Règle | Où |
|---|---|
| RG-02 blocage auto sur CCP non conforme | `RecordCcp` → `BlockSlaughterOrder` (statut `bloque`) |
| RG-03 lot bloqué hors circuit | `SlaughterService` (exécution exige `planifie`, découpe exige `termine`) |
| RG-04 réception refusée → pas d'ordre | garde `storeOrder` + validation |
| RG-06 registres immuables | **insert-only** : aucune route update/delete ; correction = écriture d'annulation (`corrects_record_id`) ; audit spatie sur `SlaughterOrder` |
| Conformité jamais confiée au client | `RecordCcp::evaluate()` + `TemperatureLog::isCompliant()` (seuils Settings) |
| Action corrective obligatoire si non conforme | refus `conflict` avant toute écriture |
| Double horodatage | `releve_at` (client) + `synced_at` (serveur) sur toutes les tables sanitaires, visibles dans registres et exports |

## Points restant à trancher (§16 de la spec)

1. Seuils CCP définitifs — vétérinaire conseil.
2. Imprimante thermique (modèle, ESC/POS) — test physique avant L4.
3. Passerelle SMS de secours (Orange/MTN) — le canal WhatsApp + cloche + mail
   existe déjà ; le SMS de secours est branché dans `NotificationHub` dès
   qu'une passerelle est retenue (réglages `sms.*` existants).
4. Abattage à façon (E8) : modèle de facturation de prestation.
5. Signature manuscrite sur écran — valeur probante à valider juridiquement.
