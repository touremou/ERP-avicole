# App mobile ERP-avicole — Spec technique Phase 0 (Fondations)

> Statut : **proposition** (à valider avant tout code).
> Objet : poser les fondations offline-first communes (auth/devices, moteur de
> synchro, app-shell) et **consolider le backend de synchro** sur une seule API.
> Principe non négociable : **toute règle métier reste dans les Actions
> partagées** (`app/Actions/**`). Le mobile n'appelle que l'API ; l'API dispatche
> vers les Actions. Aucune logique dupliquée.

---

## 0. Definition of Done (Phase 0)

La Phase 0 est terminée quand la **balle traçante** passe de bout en bout :

> Un gardien se connecte **hors-ligne** (session déjà ouverte une fois), saisit un
> **pointage journalier** sans réseau, retrouve le réseau, l'app **pousse**
> l'opération, et le pointage apparaît **dans le web** — sans double comptage, avec
> décrément d'effectif correct (via l'observer `DailyCheck`).

Checklist :
- [x] `POST /api/v1/auth/login` + `GET /api/v1/auth/me` (enrichi) + `POST /api/v1/auth/logout` + gestion devices.
- [x] `GET /api/v1/sync/pull?since=` et `POST /api/v1/sync/push` opérationnels et **idempotents**.
- [x] `SyncController` et `Api\FieldOperationController` **fusionnés** derrière un `SyncService` unique.
- [ ] PWA installable (manifest + service worker), Capacitor pré-câblé **inactif**.
- [ ] Moteur offline : miroir Dexie + outbox + sync (push/pull/conflit) + gate de permissions hors-ligne.
- [ ] App-shell : bottom-nav, home par rôle, « Mon espace », centre de notifications.
- [ ] Tests : Feature (API push/pull, idempotence, conflit, permissions) + le scénario tracer-bullet.

---

## 1. État de l'existant (ne pas réinventer)

- **Auth API** : `routes/api.php` v1, Sanctum, `POST /api/v1/auth/login` (email + password + `device_name`, `throttle:10,1`), `auth/me`, `auth/logout`.
- **Endpoints terrain** : `GET /api/v1/batches`, `GET /api/v1/batches/{batch}`, `POST /api/v1/daily-checks`, `POST /api/v1/egg-productions` (via `Api\FieldOperationController`).
- **Moteur de réconciliation** : `app/Http/Controllers/SyncController.php` couvre déjà batch, pointage, collecte d'œufs, mouvement stock, vente, dépense — avec **UUID idempotent** + **Last-Write-Wins** + Gates + statuts brouillon. ⚠️ **Non routé** et **double partiellement** `FieldOperationController`.
- **Schéma** : `uuid`, `is_synced`, `last_sync_at`, `softDeletes` sur `batches`, `incubations`, `health_checks`, `daily_checks`, `egg_productions` ; `uuid` sur `stock_movements` ; `synced_uuids` (JSON) sur `egg_productions` ; `uuid` sur `sales`, `expenses`.
- **Actions partagées** : `RecordEggCollection`, `MoveStockAction`, `CreateSale`, `CreateExpense`, etc.
- ⚠️ **Incohérence à corriger** : `SyncController::reconcile()` (batch) garde sur `admin.C/admin.M` alors qu'un lot relève d'**élevage** → aligner les Gates sur le module réel lors de la fusion.

---

## 2. Conventions API transverses

- Base : `/api/v1`, JSON, auth `Bearer` (Sanctum) sauf `auth/login`.
- **Temps** : le **serveur fait foi**. Toute réponse renvoie `server_time` (ISO-8601 UTC). Le client ne se fie jamais à l'horloge du téléphone pour le `since`.
- **Enveloppe d'erreur** standard :
```json
{ "message": "Description lisible.", "errors": { "champ": ["..."] } }
```
- Codes : `200` OK, `401` token invalide/expiré, `403` permission, `409` conflit métier non rejouable, `422` validation, `429` throttle.
- **Idempotence** : toute écriture porte un `uuid` (ou `op_uuid`) généré côté terrain ; un rejeu renvoie le même résultat sans effet de bord.

---

## 3. Contrats d'authentification

### 3.1 `POST /api/v1/auth/login`
```json
// Requête
{ "email": "x@y.z", "password": "•••", "device_name": "Tecno-Spark-gardien-amadou" }
// Réponse 200
{ "token": "12|abc...", "server_time": "2026-06-28T10:00:00Z" }
```

### 3.2 `GET /api/v1/auth/me`  *(à enrichir)*
Fournit tout ce qu'il faut pour la **home par rôle** et le **gate hors-ligne** :
```json
{
  "user":   { "id": 7, "name": "Amadou B.", "email": "..." },
  "role":   { "slug": "gardien", "label": "Gardien" },
  "permissions": {                       // matrice Modules × L/C/M/S, mise en cache offline
    "elevage":    ["L","C","M"],
    "commerce":   ["L"],
    "production": ["L","C"]
  },
  "scope":  { "farm_id": 2, "building_ids": [11,12,13] },   // périmètre assigné
  "server_time": "2026-06-28T10:00:00Z"
}
```

### 3.3 `POST /api/v1/auth/logout`
Révoque le token courant. Réponse `200 { "status": "ok" }`.

### 3.4 Gestion des devices
- `GET /api/v1/devices` → `[{ "id": 12, "name": "...", "last_used_at": "...", "current": true }]`
- `DELETE /api/v1/devices/{id}` → révoque (téléphone perdu). `current=true` ne peut pas s'auto-supprimer sans confirmation.

---

## 4. Synchronisation

### 4.1 `GET /api/v1/sync/pull?since=<ISO|null>&cursor=<opaque?>`
Rapatrie les **données de référence** (pour bosser hors-ligne) et les **enregistrements de l'utilisateur**, modifiés depuis `since`. `since=null` = bootstrap complet (paginé via `cursor`).

```json
{
  "server_time": "2026-06-28T10:05:00Z",
  "has_more": false,
  "cursor": null,
  "entities": {
    "batches":   { "upserts": [ { "id":1,"uuid":"...","code":"P-001","building_id":11,"current_quantity":480,"status":"Actif","updated_at":"..." } ],
                   "deletes": [ 42 ] },          // tombstones (softDeletes)
    "buildings": { "upserts": [ ... ], "deletes": [] },
    "clients":   { "upserts": [ ... ], "deletes": [] },
    "products":  { "upserts": [ ... ], "deletes": [] },
    "stocks":    { "upserts": [ ... ], "deletes": [] }
  }
}
```
- **Périmètre** : filtré par `scope` (un gardien ne télécharge que ses bâtiments/lots).
- **Entités Phase 1** : `batches`, `buildings`, `clients`, `products`, `stocks` (+ `daily_checks`/`egg_productions`/`sales` récents de l'utilisateur pour affichage).
- **Tombstones** : `deletes` = ids softDeleted depuis `since`, pour purger le miroir local.

### 4.2 `POST /api/v1/sync/push`
Envoie la **file d'outbox** en lot. Chaque opération est typée et idempotente.
```json
// Requête
{ "operations": [
  { "op_uuid":"f3a...", "type":"daily_check.create", "client_updated_at":"2026-06-28T09:40:00Z",
    "payload": { "uuid":"f3a...", "batch_id":1, "check_date":"2026-06-28", "mortality":3, "feed_consumed":25, "feed_type":"Démarrage", "avg_weight":1.2 } }
]}
// Réponse 200 (résultat par opération, même ordre non garanti → clé = op_uuid)
{ "server_time":"...", "results": [
  { "op_uuid":"f3a...", "status":"success", "server_id":501, "reference":null }
]}
```
**Statuts par opération** (repris/unifiés de `SyncController`) :
| statut | sens | action client |
|---|---|---|
| `success` | appliqué | retirer de l'outbox, marquer synced |
| `already_synced` | rejeu (uuid déjà vu) | retirer de l'outbox (succès) |
| `conflict` | refusé non rejouable (jour déjà trié, doublon date, stock insuffisant, version serveur plus récente) | sortir de la file → bac « à revoir », afficher la version serveur si fournie |
| `permission_denied` | Gate refusé | bloquer, message |
| `validation_failed` | payload invalide | bac « à revoir » + `errors` |

### 4.3 Registre des opérations (type → Action + Gate)
| `type` | Action / logique | Gate (module réel) | Note |
|---|---|---|---|
| `daily_check.create` | `DailyCheck::create` (+ observer décrément) | `elevage.C` | unicité (batch_id, check_date) ; stock aliment via `StockIntegrationService` |
| `egg_collection.create` | `RecordEggCollection` | `production.C` | cumul de passages, `synced_uuids`, refus si `is_graded` |
| `batch.upsert` | `Batch::updateOrCreate(uuid)` | `elevage.C` / `elevage.M` | **corriger** l'ancien `admin.*` |
| `stock_movement.create` | `MoveStockAction` | `logistique.M` | revérif disponibilité (sortie) au push |
| `sale.create` | `CreateSale` (brouillon) | `commerce.C` | validation/déstockage = **en ligne** |
| `expense.create` | `CreateExpense` (en attente) | `depenses.C` | validation = **en ligne** |

### 4.4 Règles de conflit
- **Idempotence d'abord** : `uuid` déjà présent → `already_synced`.
- **Last-Write-Wins** sur les upserts versionnés (batch) via `updated_at`.
- **Opérations sensibles validées en ligne** (vente, dépense, tri d'œufs, déstockage) : créées en **brouillon/en attente** côté push, jamais auto-finalisées → zéro conflit de stock au terrain.

---

## 5. Backend — plan de consolidation (option 3, étape suivante)

1. Créer `app/Services/Sync/SyncService.php` + un **registre** `type → handler` ; chaque handler **réutilise l'Action** correspondante (déplacer la logique de `SyncController` telle quelle, sans la réécrire).
2. `app/Http/Controllers/Api/SyncController.php` : `pull()` + `push()` (boucle sur `operations`, try/catch par op, jamais d'échec global).
3. Router dans `routes/api.php` v1 sous `auth:sanctum` (+ `throttle`).
4. **Déprécier** l'ancien `SyncController` (web) et les routes éparses de `FieldOperationController` une fois la parité atteinte (garder les Actions).
5. `auth/me` enrichi (permissions + scope) ; endpoints devices.
6. Aligner les Gates sur les modules réels (corriger `admin.*` → `elevage.*` pour les lots).
7. Tests Feature : idempotence (double push), conflit (jour trié / doublon date / stock insuffisant), permission, pull delta + tombstones, scope.

---

## 6. Mobile — moteur offline (Dexie)

```
DB "erp-mobile" (versionnée)
├─ ref_batches, ref_buildings, ref_clients, ref_products, ref_stocks   // miroir (clé = id serveur, index uuid)
├─ outbox            // { op_uuid (pk), type, payload, client_updated_at, status, attempts, last_error }
├─ my_records        // pointages/collectes/ventes locales pour affichage immédiat (optimistic)
└─ meta              // { key, value } : last_pull_at, token (sécurisé), me (user/role/perms/scope)
```
- **Écriture optimiste** : la saisie crée la ligne dans `my_records` + une entrée `outbox` (status `pending`), l'UI réagit immédiatement (offline).
- **Sync** : au retour réseau (ou bouton manuel) → `push` (vider outbox, traiter statuts) puis `pull(since=last_pull_at)` → appliquer upserts/deletes → `last_pull_at = server_time`.
- **Retries** : backoff sur `pending` (attempts++), `conflict`/`validation_failed` → status `review` (sortie de la file, visible dans un bac « À corriger »).
- **Gate hors-ligne** : l'UI lit `meta.me.permissions` pour masquer/désactiver les actions interdites ; le serveur revérifie au push (défense en profondeur).

---

## 7. Mobile — scaffolding & plateforme

- **Stack** : React 18 + TypeScript + Vite ; PWA via `vite-plugin-pwa` (Workbox) ; data : TanStack Query (cache) + Dexie (persistance) ; routing client (React Router).
- **Capacitor** : `capacitor.config.ts` présent mais **non build** ; aucun plugin natif requis en P0.
- **Adaptateurs `src/platform/`** : `notifications`, `camera`, `geo`, `secureStorage` — implémentation web en P0, signature stable pour bascule Capacitor.
- **Design system `src/ui/`** : tokens dérivés de la charte web (slate + accent par module, FR), mais **touch-first** (cibles ≥44px, bottom-nav, sheets, badge sync). Helper devise JS aligné sur `currency()`.

Arborescence : voir `mobile/src/{app,features,offline,api,ui,platform}` (cf. discussion).

---

## 8. Sécurité

- Token Sanctum **par device**, abilities = permissions du rôle ; révocation via `DELETE /devices/{id}`.
- Stockage token : `secureStorage` (web : IndexedDB chiffré/`CryptoKey` ; Capacitor : Keystore/Keychain plus tard).
- Push : **toujours** re-valider les Gates serveur (le client peut être hors-ligne avec des perms périmées).
- Jamais de `$request->all()` dans les handlers (whitelist explicite, déjà la règle dans `SyncController`).

---

## 9. Hypothèses & questions ouvertes
- Durée de vie du token (long-lived + révocation serveur, recommandé terrain) à confirmer.
- Volume du bootstrap `pull` (pagination `cursor` prévue) — dimensionner par ferme.
- Langue : FR uniquement en P0 (i18n prête mais non priorisée).
- Hébergement de la PWA : sous-domaine `app.*` (statique) à provisionner.
