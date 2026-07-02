# Audit 360° — ERP-avicole (pré-production)

> **Statut** : plan d'audit + grille d'inspection. Objectif : GO/NO-GO production.
> **Particularité** : audit ANCRÉ sur le code réel (session de travail dans le repo),
> pas une checklist générique. Chaque point porte un état constaté quand il est connu.
>
> **Légende état** : ✅ déjà conforme (constaté) · ⚠️ écart CONNU (constaté) · ❓ à vérifier (méthode fournie)
>
> **Stack constatée** : Laravel 13 / PHP 8.3 · MySQL (dev WAMP Windows) · tests Pest 897 ✅ sur sqlite :memory:
> · Blade + Tailwind + Alpine + Vite · Sanctum (API v1 mobile) · spatie/activitylog · endroid/qr-code
> · RBAC = matrice `module_permissions` (Modules × Rôles, droits L/C/M/S) · licensing par paliers de modules
> · logique métier centralisée dans `app/Actions/**` (pattern volontaire, à préserver).

---

## 0. Méthodologie

1. **Passe outillée** (statique) : Larastan, grep ciblés, `route:list`, schéma DB — 2 j.
2. **Passe dynamique** (staging MySQL, dump réaliste) : perf, concurrence, erreurs — 3 j.
3. **Passe métier** (scénarios de bout en bout + réconciliations SQL) — 3 j.
4. **Passe restitution** : matrice MoSCoW consolidée, plan de remédiation chiffré — 1 j.

Chaque ligne des grilles = **Point de contrôle | Méthode de vérification | Critère d'acceptation (GO prod) | État**.

---

## 1. AXE 1 — AUDIT TECHNIQUE

### 1.1 Architecture & Code

| # | Point de contrôle | Méthode de vérification | Critère GO prod | État |
|---|---|---|---|---|
| A1 | **Logique métier UNIQUEMENT dans les Actions** (`CreateSale`, `RecordEggCollection`, `MoveStockAction`, `CreateExpense`, `CreateBatch`…) — jamais dupliquée dans contrôleurs/vues | `grep -r "DB::transaction\|->save(\|::create(" app/Http/Controllers` : chaque écriture doit déléguer à une Action ; revue des `@php` des Blades | 0 règle métier écrite en double ; contrôleurs « minces » (validation FormRequest → Action → redirect) | ✅ pattern en place ; ❓ balayage exhaustif |
| A2 | **Doublon de moteur de synchro** : `SyncController` (web, NON routé) vs `Api\FieldOperationController` (API v1) | Lecture croisée + `route:list` | UNE seule porte d'entrée sync (`/api/v1/sync/push` + `pull`) ; l'autre supprimée ou fusionnée (cf. `docs/mobile/phase-0-spec.md` §5) | ⚠️ constaté — **bloquant** (code mort avec surface de sécu) |
| A3 | **Code mort — routes archivées** : `routes/old_version/{web.v9,v10,V11,web.à conserver…}.php` | `Glob routes/old_version/*` ; vérifier qu'aucun `require` ne les charge | Dossier supprimé du déploiement (ou hors repo) ; `route:list` = source unique | ⚠️ constaté |
| A4 | **Code mort — classes/vues orphelines** | Rapport de couverture Pest (classes 0 %) + `composer unused` + grep des vues jamais `view()`-ées | Liste triée : supprimer ou tester ; 0 classe métier non couverte ET non appelée | ❓ |
| A5 | **Couplage / sens des dépendances** : Http → Actions → Services/Models ; jamais Models → Http | `deptrac` (3 couches) ou revue ciblée ; inventaire des side-effects d'observers (`BatchObserver`, `DailyCheck::booted` décrémente l'effectif, trésorerie postée par observers) | Graphe sans cycle ; **catalogue écrit des observers** (qui écrit quoi, déclenché par quoi) — c'est la carte des effets de bord de l'ERP | ❓ (observers puissants = à documenter) |
| A6 | **Analyse statique** | Larastan (niveau 5-6) sur `app/` en CI | 0 erreur au niveau retenu ; baseline datée pour l'existant toléré | ❓ |
| A7 | **Validation systématique par FormRequest** (avec `authorize()` = Gate du bon module) | Grep `Request $request` + `->validate(` inline dans contrôleurs | Toute écriture passe par un FormRequest dédié OU validation inline justifiée ; `authorize()` teste le slug du module réel (cf. incident Gates `admin.*` → `elevage.*` déjà corrigé sur le sync batch) | ✅ pattern ; ⚠️ 1 incident connu corrigé → balayer les autres handlers |
| A8 | **Invariants comptables uniques** (1 écriture = 1 source) : carburant compté UNE fois via dépense `carburant` ; achat validé poste UNE dépense ; démarque valorisée CMP SANS écriture P&L ; règlements fournisseurs ne ré-imputent rien | Tests de scénario + requêtes de réconciliation (Σ ledger vs rapport) | Chaque invariant a un test Pest nommé qui le verrouille | ✅ invariants définis ; ❓ tous testés ? |

### 1.2 Base de données

| # | Point de contrôle | Méthode de vérification | Critère GO prod | État |
|---|---|---|---|---|
| B1 | **Intégrité référentielle** : toute colonne `*_id` a une FK (`restrictOnDelete` sur les parents des registres — pattern déjà appliqué à `batches` en 2026-06-11) | Requête `information_schema` listant les `*_id` sans contrainte ; comptage d'orphelins sur sales/expenses/stock_movements/payments | 0 orphelin sur les registres financiers ; toute absence de FK documentée | ✅ pattern ; ❓ balayage complet |
| B2 | **Contraintes d'unicité EN BASE (pas seulement applicatives)** : `daily_checks(batch_id, check_date)` (le contrôle existe côté app/sync), `uuid` uniques (sales, expenses, stock_movements), références documents (numérotation) | `SHOW INDEX` ; tentative d'INSERT doublon en staging | Chaque idempotence applicative est doublée d'un index UNIQUE (dernière ligne de défense) | ⚠️ à confirmer — **bloquant** si absent (le double-clic ou le replay sync ne doit JAMAIS passer) |
| B3 | **Index sur les requêtes chaudes** : hubs/KPIs, listes filtrées (`farm_id`, dates, statuts), `uuid` (déjà indexés à la création) | `EXPLAIN` des 15 requêtes les plus fréquentes en staging + slow_query_log (>200 ms) | 0 full scan sur table >10k lignes dans le top 15 | ❓ |
| B4 | **N+1** | Debugbar/Telescope en staging sur : hubs de modules, `batches/index` (relation employee — garde null-safe déjà en place), fiches show avec relations (déjà eager-load sur `batches/show`) | Budget < 30 requêtes/page sur le top 20 des écrans | ❓ |
| B5 | **Migrations : parité sqlite (tests) / MySQL (prod)** — plusieurs migrations ont des branches par driver | `migrate:fresh` sur MySQL 8 staging **depuis zéro** ET `migrate` **depuis un dump de pré-prod** | Les deux chemins passent ; aucun `Schema::hasColumn` masquant une dérive ; schéma final identique (diff `mysqldump --no-data`) | ❓ — **bloquant** (les 897 tests valident sqlite, pas MySQL) |
| B6 | **Verrous & transactions** : `lockForUpdate` déjà utilisé (stock, collecte œufs, capacité bâtiment) ; toutes les écritures multi-tables sous `DB::transaction` | Grep des écritures multi-tables hors transaction ; test de charge k6 (2 requêtes simultanées) | Aucune écriture composite hors transaction ; pas de deadlock sur les scénarios types | ✅ pattern ; ❓ exhaustivité |
| B7 | **Volumétrie & rétention** : croissance `activity_log`, `daily_checks`, `stock_movements` | Projection 3 ans × nb fermes ; plan d'archivage/partition si >5 M lignes | Stratégie écrite (même si « rien avant 2 ans ») | ❓ |

### 1.3 Sécurité & Fiabilité

| # | Point de contrôle | Méthode de vérification | Critère GO prod | État |
|---|---|---|---|---|
| S1 | **Injection SQL** | `grep -rn "DB::raw\|whereRaw\|selectRaw" app/` → chaque occurrence : paramètres liés ? | 0 interpolation d'entrée utilisateur dans du raw | ❓ (Eloquent partout a priori) |
| S2 | **XSS** | `grep -rn "{!!" resources/views` → justifier chaque sortie non échappée ; vérifier `Js::from` pour le JS (pattern déjà vu) | Chaque `{!! !!}` = contenu de confiance documenté ; 0 donnée utilisateur non échappée | ❓ |
| S3 | **CSRF** | Grep `<form` sans `@csrf` ; page 419 personnalisée (session expirée = quotidien sur réseau instable) | 0 form sans @csrf ; 419 gracieux avec re-login | ❓ (403/404/500 existent ✅ ; 419 ❓) |
| S4 | **RBAC — balayage exhaustif des routes** : la matrice Modules×Rôles est LA source des Gates | `route:list --json` → script : chaque route web/API a `auth` + `can:` (ou policy), hors login/install ; croiser avec `Module::routePrefixMap()` | 0 route métier non gardée ; **routePrefixMap exhaustif = contrôle de sécurité** (un préfixe non mappé retombe sur un fallback permissif — incident `products.` déjà corrigé) | ⚠️ incident connu corrigé → automatiser le contrôle en CI |
| S5 | **Licensing / paliers** : un module exclu du palier est-il réellement inaccessible (pas juste masqué) ? Révocation de licence effective ? | Tests HTTP directs sur routes d'un module exclu ; test après révocation (`add_revocation_to_licenses`) | Accès direct → 403/redirect licence ; révocation prend effet ≤ 1 requête | ❓ |
| S6 | **Mass assignment** | Revue `$fillable` des modèles financiers ; pattern « whitelist explicite, jamais `$request->all()` » (déjà la règle du SyncController) | Statuts/soldes/montants dérivés JAMAIS fillable depuis une requête (pattern `UpdateBatch::ALLOWED_FIELDS` à généraliser) | ✅ pattern ; ❓ Sale/Expense/Purchase |
| S7 | **Données sensibles** : paie, salaires, données perso employés/clients ; secrets (.env, clés passerelle SMS/WhatsApp) ; masquage `***` déjà en place sur l'audit des settings | `.env` hors repo + rotation des clés avant prod ; accès paie restreint (annuaire.S) testé ; export des données possible (droit d'accès) | Conforme **Loi guinéenne L/2016/037** (données perso) + RGPD si utilisateurs UE ; secrets jamais loggués | ❓ |
| S8 | **Uploads** (photos lots, logos, reçus) | Validation mime/taille ; stockage hors webroot exécutable ; noms aléatoires | Un `.php` renommé `.jpg` ne s'exécute jamais ; taille max serveur ET app | ❓ |
| S9 | **Auth durcie** | Throttle login (✅ API `throttle:10,1` ; ❓ web) ; politique mots de passe ; session timeout adapté terrain ; en-têtes prod (HSTS, X-Frame-Options, CSP raisonnable) ; HTTPS obligatoire | Scan (Mozilla Observatory ou équivalent) ≥ B ; brute-force bloqué | ❓ |
| S10 | **Débogage en prod** | `APP_DEBUG=false`, Debugbar/Telescope désactivés, `/install/*` verrouillé après installation | Aucune stack trace exposée ; installeur inaccessible une fois installé | ❓ — **bloquant trivial mais fatal si oublié** |

### 1.4 Industrialisation (DevOps)

| # | Point de contrôle | Méthode de vérification | Critère GO prod | État |
|---|---|---|---|---|
| D1 | **CI sur chaque push** : `composer install` → Larastan → `view:cache` (compile TOUT le Blade) → Pest complet | Pipeline (GitHub Actions/GitLab CI) ; `composer.lock` committé | Vert = artefact déployable. **Motif** : l'app a cassé DEUX fois en dev par dérive vendor (`endroid/qr-code`, `spatie/activitylog` déclarés mais non installés) — une CI l'aurait attrapé | ⚠️ dérives constatées — **bloquant** |
| D2 | **Pyramide de tests** : 897 unit/feature ✅ ; ajouter E2E navigateur (Dusk/Playwright) sur 5 parcours : login→hub, création lot (palier basic SANS annuaire — test existant côté feature ✅), vente POS complète, validation dépense, mouvement stock | Exécution E2E en CI nightly | 5 parcours verts sur staging MySQL | ✅ base ; ❓ E2E |
| D3 | **Parité dev (Windows/WAMP) ↔ prod (Linux probable)** | Casse des noms de fichiers (Linux = sensible !), chemins, `storage:link`, extensions PHP alignées | Déploiement complet sur staging Linux identique prod : 0 erreur de casse/chemin | ⚠️ risque structurel dev-Windows — **bloquant** (staging obligatoire) |
| D4 | **Procédure de déploiement écrite** | `down` → backup DB → `git pull`/artefact → `composer install --no-dev` → `migrate --force` → `config:cache`+`route:cache`+`view:cache` → `up` ; rollback = artefact précédent + restore | Déploiement + rollback REJOUÉS sur staging, chronométrés (< 10 min) | ❓ |
| D5 | **Scheduler & queues** | `schedule:list` (weather:fetch, backups nightly, relances…) ; cron actif en prod ; driver queue défini (sync acceptable au départ si assumé) | Chaque tâche planifiée a une preuve d'exécution (log/notification d'échec) | ❓ (le module Sauvegardes annonce « chaque nuit » → prouver que le cron tourne) |
| D6 | **Sauvegardes + EXERCICE DE RESTAURATION** | Backup nightly (module existant ✅) ; copie **hors site** (coupures électriques/incidents locaux = risque réel en Guinée) ; drill de restore mensuel documenté | RPO ≤ 24 h ; RTO ≤ 4 h ; **1 restore réussi AVANT le go-live** | ⚠️ restore jamais prouvé = pas de backup — **bloquant** |
| D7 | **Supervision** | Traqueur d'erreurs (Sentry/Flare/self-hosted) ; uptime externe ; alertes disque (les backups remplissent !) ; slow-query log ; **réutiliser la passerelle WhatsApp/SMS existante pour les alertes ops** | Erreur 500 en prod → alerte < 5 min ; tableau de bord santé | ❓ |
| D8 | **Gestion de versions** | Repo git avec remote + branches protégées + tags de release | Prod = tag, jamais un dossier modifié à la main | ❓ (à confirmer — voir Question 3) |

---

## 2. AXE 2 — LOGIQUE MÉTIER

### 2.1 Machines à états — inventaire et verrouillage

**Inventaire constaté des workflows** (à figer en tableau de transitions autorisées) :

| Entité | États (constatés) | Points sensibles |
|---|---|---|
| Vente (`Sale`) | brouillon → validé (déstocke) → livré ; annulé | La VALIDATION déstocke : jamais deux fois ; retour client (avoir) seulement sur validé/livré |
| Dépense (`Expense`) | en_attente → validé (entre au P&L) ; annulé (sort du P&L) | Créées `en_attente` par la sync offline (✅ bon choix) |
| Achat fournisseur (`Purchase/SupplierInvoice`) | brouillon → validé (poste UNE dépense) ; annulé (retire du P&L) ; règlements | Règlements ne ré-imputent rien (invariant AP) |
| Lot (`Batch`) | Actif → Terminé (close = bilan) ; réouverture (`ReopenBatch`) | `EDITABLE_STATUSES` + liste blanche `UpdateBatch` interdisent la réécriture d'effectifs ✅ |
| Expédition (`Dispatch`) | expédié/en_route → réceptionné | Anti-fraude expéditeur ≠ récepteur appliquée à la VALIDATION ✅ |
| Collecte œufs | passages cumulés → tri (`is_graded`) verrouille le jour | Refus re-collecte après tri déjà géré côté sync ✅ |
| Caisse (`CashSession`) | ouverte → comptage → clôturée (écart) | Vente POS impossible sans session ouverte ? à tester |
| Paie (`PayrollPeriod`) | brouillon → calculé → validé | Génération (M) vs validation (S) — séparation des pouvoirs ✅ |
| Planning | planifié → commandé → en_cours → terminé/annulé | Activation crée le lot réel |

| # | Point de contrôle | Méthode de vérification | Critère GO prod |
|---|---|---|---|
| W1 | **Toute transition illégale rejetée CÔTÉ SERVEUR** (pas seulement bouton masqué) | Pour chaque entité : test Pest HTTP tentant CHAQUE transition interdite (ex. livrer un brouillon, valider deux fois, rouvrir une caisse clôturée, modifier une paie validée) | 100 % des transitions illégales → 403/422 + état inchangé ; suite de tests nommée `*WorkflowGuardTest` |
| W2 | **Statut jamais mass-assignable** | Revue : `status` absent des `$fillable` exposés ou filtré par liste blanche (pattern `UpdateBatch` ✅) | Poster `status=validé` sur un update standard n'a AUCUN effet |
| W3 | **Effets de bord uniques** (déstockage, écriture P&L, décrément effectif) | Rejouer 2× la même validation (double-clic, replay réseau) | Idempotent : 2ᵉ tentative refusée proprement, pas de double écriture |
| W4 | **Réconciliation financière** | Requêtes SQL : Σ ventes validées = Σ mouvements caisse+créances ; Σ dépenses validées = P&L charges ; stock théorique = Σ mouvements | Écart = 0 sur jeu de données de test complet |

### 2.2 Concurrence

| # | Point de contrôle | Méthode de vérification | Critère GO prod | État |
|---|---|---|---|---|
| C1 | **Vente simultanée du dernier article** (2 caissiers) | Test parallèle (2 process artisan / k6) sur staging | 1 vente passe, l'autre échoue proprement ; stock jamais négatif (lock ✅ + contrainte CHECK/logique) | ✅ `lockForUpdate` ; ❓ test parallèle réel |
| C2 | **Double soumission web** (double-clic, timeout réseau 3G puis re-POST) | Soumettre 2× le même form (ventes, dépenses, pointage) | Doublon bloqué par contrainte unique (B2) ou jeton d'idempotence ; message clair, pas de 500 | ⚠️ dépend de B2 — **bloquant** |
| C3 | **Paiement concurrent sur la même facture** (dépassement du dû) | 2 paiements parallèles > solde | Verrou sur la facture ; total encaissé ≤ dû | ❓ |
| C4 | **Replay de la file offline** (sync) | Rejouer le même lot d'opérations `uuid` | `already_synced`, zéro double comptage (`synced_uuids` ✅) | ✅ conception ; ❓ test de charge |
| C5 | **Capacité bâtiment** (2 lots créés en même temps) | Test parallèle sur `CreateBatch` (lock bâtiment ✅) | Capacité jamais dépassée | ✅ lock ; ❓ test |

### 2.3 Traçabilité (Audit Trail)

| # | Point de contrôle | Méthode de vérification | Critère GO prod | État |
|---|---|---|---|---|
| T1 | **Couverture** : QUI/QUOI/QUAND sur toute action critique — ventes, validations (vente/dépense/achat/paie), mouvements stock, clôtures/réouvertures lot, changements rôles/permissions, licence, settings | Inventaire des modèles utilisant `AuditsChanges` (spatie, causer auto, `dontSubmitEmptyLogs` ✅) vs liste des actions critiques ; 1 test par action = 1 ligne d'audit | 100 % des actions critiques tracées (le « OÙ » : ajouter IP/user-agent dans les properties) | ✅ socle ; ❓ couverture exhaustive |
| T2 | **Inaltérabilité** | Aucune route UPDATE/DELETE sur `activity_log` ; idéalement droits SQL de l'app sans DELETE sur cette table ; export périodique append-only (fichier dans le backup offsite) | Impossible de modifier/supprimer une entrée via l'app ; export quotidien conservé | ❓ |
| T3 | **Rétention conforme OHADA** (Guinée = espace OHADA : pièces comptables ≥ 10 ans, AUDCIF) | Politique écrite : audit financier + numérotation documents (`DocumentNumberingService` ✅ séquences) jamais purgés < 10 ans | Politique validée par le comptable ; numérotation continue sans trous testée | ❓ |
| T4 | **Consultation** | UI audit existante (`audit/index`, `settings/logs` avec secrets masqués `***` ✅) filtrable par user/module/période | Un manager retrouve « qui a annulé la vente X » en < 1 min | ✅ base ; ❓ complétude filtres |

---

## 3. AXE 3 — AUDIT FONCTIONNEL

### 3.1 Résilience utilisateur

| # | Point de contrôle | Méthode | Critère GO prod | État |
|---|---|---|---|---|
| F1 | **Aucune stack trace exposée** | Forcer des exceptions en staging (`APP_DEBUG=false`) sur 10 écrans | Pages 403/404/500 stylées ✅ (existent) + **419** et **503** gracieuses ; l'erreur part vers le traqueur (D7) | ✅ partiel ; ❓ 419/503 |
| F2 | **États vides partout** | Base fraîche post-installeur : parcourir chaque hub/liste | 0 écran cassé sur données vides (pattern `@forelse @empty` répandu ✅ ; test « lot sans pointage » ✅) | ✅ pattern ; ❓ balayage |
| F3 | **Messages d'erreur métier en français, actionnables** | Provoquer les refus courants (stock insuffisant, capacité dépassée, jour déjà trié…) | Message dit QUOI et QUE FAIRE (les messages constatés sont bons, ex. capacité : « X demandés, Y disponibles » ✅) | ✅ échantillon ; ❓ balayage |
| F4 | **Réseau instable** (réalité terrain guinéenne) | Couper le réseau pendant soumission ; soumettre après expiration session | Pas de double écriture (C2) ; 419 → re-login sans perte ; roadmap offline P0/P1 déjà cadrée (`docs/mobile/phase-0-spec.md`) | ⚠️ lié C2/B2 |
| F5 | **Installeur** | Dérouler `install/*` sur serveur nu | Install complète sans intervention manuelle ; verrouillé ensuite (S10) ; **1er lot créable même en palier basic sans annuaire** (corrigé + testé ✅) | ✅ correctif palier ; ❓ drill complet |

### 3.2 Cohérence UI/UX

| # | Point de contrôle | Méthode | Critère GO prod | État |
|---|---|---|---|---|
| U1 | **En-tête standardisé `<x-page-header>`** (titre, sous-titre, icône, accent module, retour intégré, slot actions) | Scan : header slot sans page-header | ✅ **FAIT — 129 pages migrées** ; 2 exceptions assumées (dashboard « hero », batches/show « vitrine ») ; convention documentée | ✅ |
| U2 | **Navigation retour hiérarchique sans doublon** : feuilles → `:back`/`<x-back>` ; sections → `<x-hub-back>` du layout (niveau 1 → hub, niveau 2+ → section parente) | Tests `ModuleHubsTest`/`BackComponentTest` ✅ + clic-through manuel | ✅ **FAIT** ; 0 double flèche | ✅ |
| U3 | **Accent couleur par module** (teal commerce, orange logistique, blue RH, rose finance/abattoir, emerald trésorerie, indigo élevage-pilotage, green cultures, purple santé, amber provenderie…) | Grille des accents vs pages | ✅ appliqué au rollout ; carte documentée en mémoire projet | ✅ |
| U4 | **Devise JAMAIS en dur** | `grep -rn "GNF" resources/ app/` (helper `currency()`/`money()` obligatoire ; `formatGNF()` legacy utilise déjà currency() ✅) | 0 littéral hors fallback `setting(..., 'GNF')` | ✅ règle ; ❓ balayage final |
| U5 | **i18n & formats** | Grep chaînes FR hors `__()` ; dates via `setting('general.date_format')` (des `format('d/m/Y')` en dur constatés) | Top 30 écrans : 100 % `__()` ; format date centralisé | ⚠️ dates en dur ponctuelles |
| U6 | **Responsive terrain** (Android entrée de gamme, 360 px) | Lighthouse mobile + test réel sur les écrans opérateurs : POS, pointage journalier, collecte œufs, réception expédition | Ces 4 écrans utilisables à une main sur 360 px, cibles tactiles ≥ 40 px | ❓ (en attendant la PWA) |
| U7 | **Kit de composants documenté** (`page-header`, `back`, `stat-tile`, `flash`, `toast`, `modal`) | Page/README de référence | Nouveau dev : créer une page conforme sans lire 10 exemples | ❓ (1 page à écrire) |

### 3.3 Performance perçue

| # | Point de contrôle | Méthode | Budget GO prod | État |
|---|---|---|---|---|
| P1 | **Hubs & dashboards** | Debugbar/Telescope staging, données réalistes (2-3 ans simulés) | Serveur < 500 ms ; N+1 = 0 (B4) ; KPIs lourds → `Cache::remember` 60 s/ferme si besoin | ❓ |
| P2 | **POS** (écran le plus critique) | Chronométrage interactions (recherche produit, ajout panier, encaissement) | Interaction perçue < 200 ms (Alpine local ✅ à confirmer) ; encaissement bout-en-bout < 2 s | ❓ |
| P3 | **Listes** | Vérifier pagination sur TOUS les index (`->links()` répandu ✅) | Aucune liste non paginée > 100 lignes | ❓ balayage |
| P4 | **Bas débit** (3G réel) | Lighthouse throttling ; poids page | Page critique < 1 Mo transféré ; **Font Awesome & assets SERVIS EN LOCAL, pas de CDN** (sinon l'UI casse hors ligne) ; images uploadées redimensionnées | ❓ — piège classique |
| P5 | **PDF/exports** | Générer les gros PDF (relevés, rapports) sur volumes réels | < 10 s ou passage en file d'attente avec retour visuel | ❓ |

---

## 4. Spécificités contexte Guinée (transversal)

| Risque terrain | Contrôle d'audit | Réponse attendue |
|---|---|---|
| Coupures électriques fréquentes | MySQL InnoDB (crash-safe) ✅ à confirmer ; onduleur serveur ; D6 (restore prouvé) | Reprise sans corruption après coupure brutale (test : kill -9 MySQL sous écriture en staging) |
| Connectivité intermittente / data chère | C2 (double soumission), F4, P4 (poids pages, assets locaux) ; roadmap PWA offline (P0 spécifiée) | L'ERP reste utilisable en 3G dégradé ; aucune dépendance CDN bloquante |
| Mobile Money omniprésent | Comptes trésorerie typés (Caisse/MM/Banque ✅) ; audit du rapprochement MM (frais opérateur, références) | Chaque encaissement MM porte une référence opérateur ; rapprochement possible |
| Passerelle SMS/WhatsApp (templates ✅) | Sécurité des clés (S7) ; coût par message ; comportement si passerelle down | Échec d'envoi loggué + re-tentative ; jamais bloquant pour l'opération métier |
| Cadre comptable OHADA/SYSCOHADA | T3 (rétention 10 ans, numérotation continue) ; mapping plan comptable en export (post-lancement) | Numérotation sans trous testée ; export états compatible expert-comptable (COULD) |
| Réalités agronomiques (saison des pluies juin-nov, races locales, normes par espèce/souche ✅ référentiel existant) | Validation des seuils par un zootechnicien local (mortalité, GMQ, ponte) ; calendriers culturaux guinéens du catalogue ✅ | Référentiels revus/signés par le métier avant go-live |
| Multi-site / fermes distantes | Scope `farm_id` (`BelongsToFarm`) : test d'étanchéité inter-fermes (un user ferme A ne voit RIEN de la ferme B) | Test automatisé d'isolation par ferme — **à traiter comme un contrôle de sécurité** |

---

## 5. Matrice de priorisation (MoSCoW)

### 🔴 MUST — bloquants pour la production
| Réf | Action | Pourquoi bloquant |
|---|---|---|
| D1 | **CI complète** (composer install + Larastan + view:cache + 897 Pest) sur repo distant | La dérive vendor a déjà cassé l'app 2× en dev ; sans CI, elle cassera la prod |
| B5+D3 | **Staging Linux/MySQL** + `migrate:fresh` ET migration depuis dump réels verts | Les tests tournent sur sqlite/Windows ; la prod sera MySQL/Linux — parité jamais prouvée |
| B2+C2 | **Contraintes UNIQUE en base** (uuid, daily_checks jour/lot, références) + test double-soumission | Double-clic sur 3G = doublons financiers sans ce filet |
| S4 | **Balayage RBAC automatisé** : 0 route non gardée ; routePrefixMap exhaustif vérifié en CI | Un préfixe oublié = fuite de permissions (incident `products.` l'a prouvé) |
| S10 | APP_DEBUG=false, Telescope/Debugbar off, installeur verrouillé, secrets rotationnés | Trivial, fatal si oublié |
| D6 | **Un restore de sauvegarde RÉUSSI et documenté** + copie hors site | Un backup jamais restauré n'existe pas ; contexte coupures/incidents |
| W1-W3 | Tests de transitions illégales + idempotence sur Sale/Expense/Purchase/Batch/Payroll/Caisse | Cœur financier de l'ERP ; c'est LA définition de « fiabiliser » |
| A2+A3 | Trancher le doublon sync (fusion API v1 ou suppression) + purger `routes/old_version/*` | Code mort avec surface d'attaque et double vérité métier |
| C1 | Test concurrence stock (2 caissiers, dernier article) sur staging | Le verrou existe ; il n'a jamais été prouvé sous vraie concurrence |
| Farm | Test d'étanchéité multi-fermes (`farm_id`) | Fuite inter-sites = incident de confiance irrécupérable |

### 🟠 SHOULD — avant ou ≤ 30 jours après lancement
T1/T2 (couverture audit trail 100 % + IP + export inaltérable) · D7 (monitoring + alertes branchées WhatsApp) · D4/D5 (procédure déploiement rejouée ; preuve cron backups/scheduler) · B3/B4/P1 (index + N+1 + budgets hubs) · D2 (5 parcours E2E) · S8/S9 (uploads, en-têtes, throttle web) · F1 (419/503) · U6 (responsive 4 écrans opérateurs) · P4 (assets locaux, poids pages).

### 🟡 COULD — optimisations post-lancement
A4/A6 (couverture → gate progressif, Larastan niveau ↑, deptrac) · P1 cache KPIs · T3 export SYSCOHADA · U5 centralisation format dates · U7 page kit UI · B7 archivage/partitions · mutation testing.

### ⚪ WON'T (ce cycle) — assumé
Microservices, multi-tenant BDD séparées, Kubernetes, i18n multilingue, refonte SPA du back-office (la PWA terrain est le bon investissement mobile, déjà cadrée).

---

## 6. Déroulé opérationnel proposé (≈ 9-10 j·h)

| Jour | Passe | Livrables |
|---|---|---|
| J1-J2 | Statique : A1-A8, S1-S6, U4-U5, route:list/RBAC script | Liste d'écarts + quick wins mergés (dead code, grep) |
| J3 | Montage **staging Linux+MySQL** + dump réaliste | B5/D3 tranchés ; environnement de toutes les passes suivantes |
| J4-J5 | Dynamique : B2-B4, C1-C5, P1-P5, F1-F4 | Rapport perf/concurrence + contraintes DB ajoutées |
| J6-J7 | Métier : W1-W4, T1-T4, réconciliations SQL, scénarios Guinée (§4) | Suite `WorkflowGuardTest` + réconciliations vertes |
| J8 | DevOps : D1 pipeline, D4 procédure, D6 **drill restore** | CI verte ; runbook déploiement/rollback ; PV de restore |
| J9 | Consolidation : matrice finale, chiffrage remédiation, décision GO/NO-GO | Ce document mis à jour avec verdicts ✅/⚠️ par ligne |

---

## 7. Réponses aux questions critiques (calibrage — 2026-07-02)

| # | Question | Réponse client | Conséquence d'audit |
|---|---|---|---|
| 1 | Hébergement cible | **Tri-mode selon client** : VPS cloud (OVH/Contabo/AWS), hébergement mutualisé, on-premise à la ferme | Le déploiement devient une **matrice** (cf. §8.1) ; dénominateur commun technique imposé : cache/queue `database|file` (pas de dépendance Redis/Supervisor), 1 seule entrée cron, artefact ZIP pour le mutualisé |
| 2 | MySQL & backups | MySQL 8.x auto-géré ; **backup nightly PAS ENCORE actif, aucune restauration jamais faite** | D6 confirmé **bloquant n°1 ops** : activer scheduler + destination hors site + drill de restore avant tout go-live |
| 3 | Versioning/déploiement | Git + remote (GitHub/GitLab) ; déploiement par `git pull` | La CI (D1) se branche en ~1 jour ; `git pull` acceptable en VPS/on-prem avec runbook durci ; mutualisé → déploiement par artefact |
| 4 | Volumétrie | Nb de fermes = f(nb clients) ; **multi-site actif dès J1** | Modèle = **instances self-hosted par client** (pas un SaaS central) → gestion de flotte (mises à jour, licences, backups PAR client) ; test d'étanchéité `farm_id` = MUST confirmé |
| 5 | Données sensibles & fiscal | **Paie réelle dès J1** ; exigence explicite expert-comptable/fisc : **numérotation factures + états SYSCOHADA** | Numérotation continue sans trous → **MUST** (tests concurrence + annulations sur `DocumentNumberingService`) ; exports SYSCOHADA → SHOULD pré-lancement commercial avec visa comptable ; accès paie = contrôle renforcé |

### Ajustements MoSCoW induits
- **➕ MUST** : numérotation fiscale continue testée (concurrence + annulation conserve le numéro, zéro trou) ; matrice de déploiement tri-mode documentée avec dénominateur commun.
- **D6 (backups+restore)** : reste MUST, désormais **écart confirmé** (rien ne tourne).
- **⬆ SHOULD pré-lancement** : exports journaux SYSCOHADA (ventes/achats/trésorerie/paie) validés par l'expert-comptable ; confidentialité paie (accès + audit).

---

## 8. Plan de remédiation séquencé (chiffré)

### 8.1 Matrice de déploiement tri-mode (contrainte produit)

| Aspect | VPS Cloud (défaut recommandé) | Mutualisé | On-premise ferme |
|---|---|---|---|
| Déploiement | `git pull` + runbook (down→backup→pull→composer no-dev→migrate→caches→up) | **Artefact ZIP** (vendor inclus, no-dev) via cPanel/SFTP — pas de git garanti | `git pull` ou artefact ; accès distant via VPN léger (Tailscale/WireGuard) pour le support |
| Cron/scheduler | crontab (1 entrée `schedule:run`) | Cron cPanel (souvent limité) — vérifier granularité 1 min ; sinon cron externe sur URL signée | crontab + **onduleur obligatoire** |
| Queue | `database` (worker via cron `queue:work --stop-when-empty`) | idem (jamais Supervisor) | idem |
| Cache/session | `database`/`file` (pas de Redis requis) | idem | idem |
| HTTPS | Let's Encrypt auto | Fourni hébergeur | Certificat interne ou LE via DNS |
| Backups | Dump nightly → **objet S3/B2 hors site** | Dump nightly → stockage externe (pas le même compte !) | Dump nightly local + **upload hors site dès connectivité** (rclone) — coupures = risque n°1 |
| Licence/revocation | Contrôle en ligne | En ligne | **Période de grâce hors-ligne** définie (ex. 7 j) puis blocage doux |
| Monitoring | Traqueur erreurs + uptime externe | Traqueur erreurs (uptime externe) | Heartbeat sortant quotidien → alerte si silence |

**Règle d'or** : toute nouvelle dépendance technique doit passer le test « fonctionne sur mutualisé » — sinon elle est optionnelle par config.

### 8.2 Phases (estimations en jours·homme ; ~18 j·h total → 4 sem à 1 dev, ~2,5 sem à 2)

| Phase | Contenu | Réf. grille | Est. | Gate |
|---|---|---|---|---|
| **P0 — Filets & CI** (S1) | ① Pipeline CI (composer install + Larastan baseline + view:cache + Pest 897) **+ job MySQL 8** `migrate:fresh` · ② Purge `routes/old_version/*` + tranche du doublon sync (router `push/pull` API v1, déprécier le contrôleur web — cf. phase-0 §5) · ③ **Contraintes UNIQUE en base** (uuid ventes/dépenses/mouvements, `daily_checks(batch_id,check_date)`, n° documents) + tests double-clic · ④ Checklist prod S10 (debug off, installeur verrouillé, rotation secrets) | D1, A2-A3, B2/C2, S10 | **4 j** | CI verte obligatoire pour merger |
| **P1 — Preuves d'intégrité** (S2) | ⑤ Suite `WorkflowGuardTest` (transitions illégales Sale/Expense/Purchase/Batch/Payroll/Caisse/Dispatch) · ⑥ Tests concurrence staging (2 caissiers dernier article ; paiement > dû ; capacité bâtiment) · ⑦ **Étanchéité `farm_id`** automatisée · ⑧ **Numérotation fiscale** : sans trous sous concurrence, annulation conserve le n° | W1-W3, C1/C3/C5, Farm, T3-fiscal | **5 j** | 100 % rouge→vert documenté |
| **P2 — Ops tri-mode** (S3) | ⑨ **Backups réels** : scheduler actif + destination hors site + **drill de restore avec PV** · ⑩ Runbooks déploiement ×3 modes + script artefact ZIP · ⑪ Monitoring (traqueur erreurs + uptime + disque) → alertes via passerelle WhatsApp existante · ⑫ Staging Linux durable + 5 parcours E2E | D6, D4, D7, D3/D2 | **5 j** | **GO/NO-GO technique ici** |
| **P3 — Conformité pré-commercial** (S4) | ⑬ Audit trail : couverture 100 % actions critiques + IP + export append-only · ⑭ **Exports SYSCOHADA** (journaux ventes/achats/trésorerie/paie) + visa expert-comptable · ⑮ Confidentialité paie (accès, masquage, tests) · ⑯ Passe perf (N+1 top 20, budgets hubs, assets 100 % locaux) | T1-T2, T3, S7, B4/P1/P4 | **4 j** | Visa comptable = GO commercial |

Ordonnancement : P0 débloque tout (les tests P1 s'écrivent sur une CI qui les protège) ; P2 ⑨ peut démarrer en parallèle de P1 si 2 devs.

---

## 9. Journal de remédiation

**2026-07-02 — P0 (partiel) exécuté :**
- **A3 ✅** `routes/old_version/*` : déjà purgé (constaté absent ; plus aucune référence dans le code).
- **B2 ✅** Contraintes UNIQUE : couverture existante meilleure que prévu (daily_checks jour/lot, sales/expenses/stock_movements/batches/cultures uuid, références documents, œufs jour/lot via la passe 2026-06-11). Trou résiduel comblé : migration `2026_07_02_000001_add_unique_uuid_constraints_for_sync` (uuid UNIQUE sur `daily_checks`, `health_checks`, `incubations`, `egg_productions`, garde anti-doublons + indexExists driver-aware). Appliquée sur MySQL dev ✓.
- **B2-tests ✅** `tests/Feature/DatabaseConstraintGuardTest.php` (5 tests) : structure des index d'idempotence (PRAGMA unique=1) + rejets physiques (pointage jour dupliqué, uuid pointage, uuid dépense, référence dépense).
- **D1 ✅ (code)** `.github/workflows/ci.yml` enrichi : déclenchement toutes branches (plus seulement `main`), étape `view:cache` (compilation Blade totale), **job MySQL 8** (`migrate:fresh` + rejouabilité `migrate`). Actif au prochain push.
- **S10 (constats)** : `.env`/`.env.backup` gitignorés ✓ ; installeur derrière `redirect.if.installed` ✓ (`install/finish` hors garde — à vérifier inoffensif) ; ⚠️ 141 fichiers non commités sur branche `claude/funny-maxwell-0h3i5h` → commit/push requis pour activer la CI.
- **Constats P1 anticipés** : `payments` sans unicité naturelle → protection = verrou vente + contrôle du dû (P1-⑥/C3) ; `DocumentNumberingService` = MAX-based incluant soft-deleted → **zéro trou, zéro réémission** (propriété fiscale ✓, `DocumentNumberingTest` existe) ; sous concurrence, collision → échec dur sur l'index unique : ajouter retry-on-collision (P1-⑧).

**2026-07-02 — P0 terminé : A2 (fusion sync) exécuté :**
- **A2 ✅** UNE porte d'entrée sync : `POST /api/v1/sync/push` + `GET /api/v1/sync/pull` (`Api\SyncController` + `App\Services\Sync\SyncService`, registre type→handler). Logique déplacée du contrôleur web mort ; `daily_check.create` réutilise désormais **`RecordDailyCheck`** (l'ancien doublon ne compensait ni aliment ni fumier/eau) ; Gates lots **corrigés** `admin.*` → `elevage.*` ; statuts normalisés (success / already_synced / conflict / permission_denied / validation_failed / error) ; une op en échec ne fait jamais échouer le lot. **Supprimés** : `app/Http/Controllers/SyncController.php` (web, non routé) et `Api/FieldOperationController` (+ ses 2 routes, remplacées par push).
- **Étanchéité multi-fermes API ✅ (trouvaille majeure)** : `FarmScope` ne lisait que la session web → l'API Sanctum n'était PAS bornée par ferme (fuite inter-sites via `/api/v1/batches`, écritures rattachées à la ferme par défaut). Nouveau middleware **`farm.api`** (`SetApiFarmContext`, attaché après `auth:sanctum`) : résolution `X-Farm-Id` validé contre `farm_user` → ferme par défaut → première ferme → repli mono-ferme ; 403 si ferme non affectée.
- **Divergences du code mort confirmées** (valeur de l'audit A2) : le vieux contrôleur écrivait `daily_checks.user_id` — colonne INEXISTANTE (ignorée silencieusement) — et son uuid n'était jamais persisté (non-fillable) → son idempotence applicative était fictive ; seule la contrainte (batch, date) sauvait.
- **Tests ✅** `tests/Feature/ApiSyncTest.php` (10) : 401 sans token ; étanchéité fermes (lecture bornée + X-Farm-Id étranger → 403) ; daily_check succès/rejeu/conflit-jour/permission ; lot d'opérations indépendantes (success + validation_failed + conflict stock) ; vente brouillon idempotente ; batch.upsert gate module réel + conflit LWW ; pull delta + tombstones + périmètre ferme.

**2026-07-02 — correctif A2 (porte legacy) + P1-⑤ entamé :**
- **A2 complété — leçon d'audit** : le « contrôleur mort » était en réalité routé (`/api/sync/*` en session web, `routes/web.php` ~504) et consommé par `resources/js/sync-engine.js` — le grep de sécurité pré-suppression avait un glob défaillant (6 tests rouges l'ont attrapé : la valeur du filet de tests). Résolution : **un moteur, deux portes** — `SyncGatewayController` (@deprecated) traduit l'ancien contrat HTTP (422 validation / 403 permission / 200+status) vers `SyncService` ; les URLs et le JS existants restent intacts jusqu'à la bascule PWA ; `ApiV1Test` migré vers `POST /api/v1/sync/push`, prérequis documenté : un utilisateur API DOIT être affecté via `farm_user`.
- **P1-⑤ WorkflowGuardTest ✅ (8 tests, cœur financier)** : double validation de vente refusée sans re-déstockage (validated_at intact) ; livraison d'un brouillon refusée ; annulation refusée si paiement (rembourser d'abord) ; annulation d'une validée restocke EXACTEMENT puis re-annulation refusée ; double approbation de dépense refusée (une seule entrée P&L, approved_at intact) ; approbation d'une annulée refusée ; **statut non mass-assignable** via le formulaire d'édition (injection ignorée) ; opérateur (C) bloqué sur validate/cancel. Pièges de test consignés : propriétés protected du trait → closures liées ; tables ayant gagné `farm_id` (clients, payments) → inserts bruts invisibles au scope.
- **P1-⑧ (numérotation) recalibré** : la propriété fiscale est DÉJÀ garantie par conception (séquence = MAX incluant soft-deleted → zéro trou, zéro réémission ; collision concurrente → échec dur sur l'index unique, JAMAIS un doublon). Le retry-on-collision devient un confort UX (SHOULD), pas un bloquant.

**Reste avant prod** : rotation des secrets ; P1 suite (WorkflowGuard vague 2 : paie/caisse/achats/lot-clôture ; tests concurrence staging ; étanchéité farm_id dédiée) ; P2 (backups+restore, runbooks tri-mode, monitoring).
