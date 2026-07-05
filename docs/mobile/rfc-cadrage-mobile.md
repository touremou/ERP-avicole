# RFC — Cadrage de l'application mobile compagnon ERP-avicole

> Statut : **proposition à discuter** (Request For Comments).
> Rôle : Product Manager Mobile / Tech Lead.
> Documents liés : `docs/mobile/phase-0-spec.md` (spec technique Phase 0),
> `docs/audit/plan-audit-360.md`.
> Principe fondateur déjà acté : **le mobile n'est pas un clone responsive du
> web** — c'est une application « compagnon » organisée par **tâche du moment**,
> hors-ligne par défaut, qui consomme l'API v1 et réutilise les **Actions métier
> partagées** (source unique de vérité web = mobile).

---

## 1. Stratégie produit et périmètre (Scope)

### 1.1 Définir les « activités essentielles » : la méthode

On ne part **pas** de la liste des 14 modules du web. On part du **terrain** :
qui tient le téléphone, debout, où, avec quelle fréquence ? Trois filtres,
appliqués dans cet ordre :

**Filtre A — Le test « debout, gants aux mains »**
Une fonctionnalité est candidate au mobile si et seulement si elle se pratique
*sur le lieu de l'opération* (poulailler, magasin, parcelle, quai d'abattage),
*au moment* de l'opération. Un état financier consolidé, une configuration de
paie, un paramétrage de licence échouent à ce test → ils restent web.

**Filtre B — Le test de fréquence × douleur**
Parmi les candidates, on priorise ce qui est *quotidien et douloureux
aujourd'hui* : le pointage saisi sur papier puis recopié le soir, la vente
encaissée de tête, la mortalité constatée à 7h et déclarée à 18h. La douleur se
mesure : délai moyen entre l'événement réel et sa saisie dans l'ERP
(aujourd'hui : des heures ; cible mobile : < 1 minute).

**Filtre C — Le test de valeur ajoutée capteur**
À égalité, on privilégie ce que le web **ne peut pas** faire : photo attachée
(mortalité, incident santé, reçu de dépense), scan QR de traçabilité
(`QrCodeService` existe déjà), géolocalisation d'une opération, push
(alertes météo — `forecastAlerts` existe —, pic de mortalité, stock bas,
tâche due), note vocale, login biométrique.

### 1.2 Résultat : le périmètre par persona (pas par module)

| Persona | Ouvre l'app et voit… | Opérations 2-3 taps |
|---|---|---|
| **Gardien / éleveur** | Ses bâtiments + « pointage du jour à faire » | Mortalité, aliment consommé, collecte d'œufs, pesée, photo incident |
| **Caissier / vendeur** | Vente rapide + encaissements du jour | Vente brouillon, règlement, scan produit |
| **Magasinier** | Stocks sous seuil + mouvements en attente | Entrée/sortie stock, ajustement, réception |
| **Vétérinaire / technicien** | Incidents santé ouverts + protocoles dus | Déclaration incident (+ photo), diagnostic, acte sanitaire |
| **Tous** | « Mon espace » : mes tâches, mes notifications, mon profil | Pointage présence, note vocale, consultation |

Le **manager**, lui, consulte (KPIs, alertes) mais **pilote sur le web**. On lui
donne un tableau de bord mobile en lecture, pas la configuration.

### 1.3 Priorisation V1 (MVP) : MoSCoW contraint par le backend

La méthode : **MoSCoW croisé avec l'état réel de l'API** (on ne promet pas au
produit ce que le backend ne sait pas encore synchroniser). État des lieux :

| Domaine terrain | Backend prêt ? |
|---|---|
| Élevage (pointage, collecte œufs, lots) | ✅ API v1 + moteur de réconciliation existants |
| Logistique (mouvements stock) | ✅ Sync gérée (UUID idempotent) |
| Commerce (ventes, dépenses en brouillon) | ✅ Sync gérée |
| Cultures / Abattoir / Provenderie | ⚠️ À exposer (endpoints + colonnes `uuid`/`is_synced`) |
| Espace perso / profil / notifications | 🟡 Partiel (web ok, API à compléter) |

D'où la feuille de route phasée (détail technique : `phase-0-spec.md`) :

- **Phase 0 — Fondations** : auth par device + révocation, moteur offline
  (miroir local + outbox), protocole `sync/pull?since=` + `sync/push`,
  coquille « Mon espace » + notifications. Inclut la **consolidation backend** :
  fusionner `SyncController` (non routé, doublon partiel de
  `FieldOperationController`) derrière un `SyncService` unique, et construire
  le **pull delta** qui manque (aujourd'hui le offline-first n'a que sa moitié
  « push »).
- **Phase 1 — Élevage terrain** (*Must*) : la preuve par l'usage — pointage,
  collecte, photo, scan QR. API déjà prête, valeur quotidienne maximale.
- **Phase 2 — Commerce + Logistique** (*Should*) : vente rapide, mouvements
  stock. Sync déjà prête côté serveur.
- **Phase 3 — Cultures, Abattoir, Provenderie** (*Could*) : extension de l'API
  et du schéma de sync à ces modules.
- ***Won't* (V1)** : configuration, paie, licences, rapports consolidés,
  administration des rôles — web uniquement.

**Critère de succès V1** (à valider en atelier) : ≥ 80 % des pointages
journaliers d'une ferme pilote saisis *sur mobile, sur site, le jour même*
pendant 4 semaines consécutives.

---

## 2. Approche technologique : le grand débat

### 2.1 Comparatif objectif

| Critère | Natif (Kotlin + Swift) | Flutter | React Native | **PWA + Capacitor** |
|---|---|---|---|---|
| Performance UI | ★★★★★ | ★★★★☆ | ★★★★☆ | ★★★☆☆ (suffisant pour du formulaire/liste) |
| Accès capteurs (caméra, GPS, biométrie) | Total | Excellent (plugins) | Excellent (plugins) | Bon via Capacitor ; partiel en pur web |
| Push notifications | Total | Total | Total | Android ok en PWA ; **iOS limité → Capacitor requis** |
| Coût / délai (2 OS) | ×2 équipes, le plus lent | 1 équipe Dart | 1 équipe JS/TS | **1 équipe JS/TS, réutilise la stack du projet** |
| Compétences déjà en interne | Aucune (Laravel/Blade/JS) | À acquérir (Dart) | Proche (React) | **Identiques (TS/Vite déjà dans le repo)** |
| Poids app / vieux Android d'entrée de gamme | Léger | ~15-40 Mo, très fluide | ~20-40 Mo | **Quelques Mo, pas d'install obligatoire** |
| Distribution / mise à jour | Stores (délais, comptes, 20 % d'échecs de MAJ terrain) | Stores | Stores (+ OTA) | **URL = déploiement instantané** ; stores possibles via Capacitor |
| Offline-first | À construire (Room/CoreData) | À construire (Drift/Isar) | À construire (WatermelonDB) | À construire (Dexie/IndexedDB) — effort équivalent partout |
| Risque de divergence métier avec le web | Élevé (logique retapée) | Moyen | Moyen | **Faible : mêmes Actions Laravel via l'API** |

Trois lucidités à garder :

1. **L'offline-first est le vrai coût, pas le framework.** Le moteur local
   (miroir + outbox + résolution de conflits) représente ~40 % de l'effort V1
   et se réécrit intégralement quelle que soit la techno. Aucune option ne
   l'offre « gratuitement ».
2. **Le natif double le coût pour un bénéfice marginal ici.** Nos écrans sont
   des formulaires, des listes et un scanner — pas de la 3D ni du temps réel
   audio/vidéo. Le gain natif ne se verrait pas ; la facture, si.
3. **La vraie faiblesse de la PWA est ciblée et connue : iOS** (push et tâches
   d'arrière-plan bridés par Apple). D'où la rampe Capacitor : la **même base
   de code** s'emballe en app installable (Play Store / App Store, push
   fiable, Keystore/Keychain) **sans réécriture** — `capacitor.config.ts` est
   déjà présent dans le scaffolding, inactif.

### 2.2 Recommandation argumentée

**PWA d'abord, Capacitor en rampe** — et si un jour l'exigence dépasse ce que
Capacitor offre, la passerelle naturelle est **React Native** (réutilisation
des compétences React), pas le natif double-pile.

Parce que, dans notre contexte précis :
- **Parc hétérogène, Android dominant, entrée de gamme fréquente** : une PWA de
  quelques Mo accessible par URL bat une app de 40 Mo à télécharger sur un
  réseau instable et à mettre à jour via un store.
- **Déploiement rapide** : corriger un bug terrain le matin, tous les
  téléphones l'ont à midi. Aucun store dans la boucle.
- **Synchronisation avec l'existant** : l'API v1 Sanctum et les Actions
  partagées sont en place ; une équipe TS unique couvre le web ET le mobile.
- **Réversibilité** : les adaptateurs `src/platform/` (caméra, GPS, push,
  secureStorage) ont une signature stable web ↔ Capacitor. Le choix n'est pas
  un aller simple.

**Déclencheurs objectifs de la bascule Capacitor** (à surveiller, pas à
débattre à l'infini) : (a) > 10 % du parc en iPhone avec besoin de push ;
(b) besoin de scan/photo intensif où la caméra web s'avère trop lente sur le
parc réel ; (c) exigence de distribution par store d'un client/partenaire.

---

## 3. Le défi de la connectivité : offline-first

> Détail des contrats API, du schéma local et des statuts : `phase-0-spec.md`
> §4-6. Ici, les décisions d'architecture et leur justification.

### 3.1 Principes

1. **L'app fonctionne sans réseau par défaut** ; le réseau est une
   opportunité de synchroniser, jamais une condition d'usage. Toute saisie est
   **optimiste** : elle s'affiche immédiatement, part dans une **outbox**
   locale, et sera poussée plus tard.
2. **Le serveur fait foi** — pour l'heure (`server_time` dans chaque réponse,
   jamais l'horloge du téléphone pour le `since`) et pour les règles métier
   (les Gates sont revérifiées au push même si l'UI les a déjà filtrées
   hors-ligne).
3. **Idempotence partout** : chaque opération porte un `uuid` généré au
   terrain ; un rejeu (réseau qui coupe pendant la réponse) renvoie
   `already_synced` sans effet de bord. Le schéma serveur est déjà équipé
   (`uuid`, `is_synced`, `softDeletes` sur les tables terrain).

### 3.2 Le cycle de synchronisation

```
saisie → my_records (affichage) + outbox (pending)
retour réseau (ou bouton) :
  1. PUSH  : vider l'outbox par lot → statut par opération
  2. PULL  : /sync/pull?since=last_pull_at → upserts + tombstones
  3. last_pull_at = server_time
```

Le **pull delta est la moitié manquante** de l'existant (le moteur actuel ne
fait que du push) : sans lui, le gardien n'a pas ses lots/clients/produits à
jour pour travailler hors-ligne. C'est le chantier n°1 de la Phase 0, avec la
fusion `SyncController`/`FieldOperationController` en une seule porte d'entrée
(`SyncService` + registre `type → Action + Gate`).

### 3.3 Résolution des conflits : par classe d'opération, pas une règle unique

| Classe | Exemples | Stratégie |
|---|---|---|
| **Journal append-only** | mortalité, collecte, pesée, mouvement | Pas de conflit possible : ce sont des faits horodatés. Seule garde : unicité métier (ex. un pointage par lot et par jour) → refus explicite `conflict`, jamais de fusion silencieuse |
| **Upsert versionné** | fiche lot | **Last-Write-Wins** sur `updated_at` serveur ; la version perdante est présentée à l'utilisateur, pas écrasée en silence |
| **Opérations sensibles** | vente, dépense, déstockage, tri d'œufs | Créées en **brouillon/en attente** au push ; la finalisation (qui touche stock et trésorerie) exige d'être **en ligne** → zéro conflit de stock possible depuis le terrain |
| **Refus définitifs** | jour déjà trié, doublon, stock insuffisant | Sortie de la file vers un bac **« À corriger »** visible, avec la version serveur et le motif — l'utilisateur arbitre, l'outbox ne bloque jamais |

Cette gradation est le cœur de la garantie d'intégrité : **on ne laisse
jamais le terrain finaliser hors-ligne une opération qui engage le stock ou
l'argent**, et tout le reste est soit un fait append-only, soit du LWW visible.

### 3.4 Ce qu'on refuse d'emblée

- Pas de fusion automatique champ par champ (CRDT) : complexité injustifiée
  pour des formulaires métier ; le bac « À corriger » est plus honnête.
- Pas de sync temps réel (websocket) en V1 : le pull périodique + au retour
  réseau suffit au rythme des opérations d'élevage.
- Pas de logique métier dans le client : le mobile appelle l'API, l'API
  dispatche vers les Actions. Aucune duplication.

---

## 4. Ergonomie et UX terrain : les règles d'or

Contexte réel : plein soleil, poussière, gants, une seule main, interruptions
constantes, utilisateurs peu ou pas formés, téléphones d'entrée de gamme.

**Lisibilité et manipulation**
1. **Cibles tactiles ≥ 48 px**, espacées ; les actions principales en bas
   d'écran (zone du pouce), navigation par **bottom-nav** 4-5 entrées max.
2. **Contraste élevé** (thème clair par défaut — le sombre est illisible au
   soleil), corps de texte ≥ 16 px, icônes toujours doublées d'un libellé.
3. **Une action = un écran.** Pas de formulaires-fleuves : le pointage se
   déroule en étapes (mortalité → aliment → observations), chacune validable.

**Saisie sans clavier**
4. **Clavier numérique par défaut** partout où c'est un nombre ; **steppers
   +/-** pour les petites quantités (mortalité), **presets** pour les valeurs
   fréquentes (types d'aliment, causes de mortalité) — le clavier texte est
   l'exception.
5. **Capteurs plutôt que doigts** : scanner le QR du lot plutôt que le
   chercher dans une liste ; photo plutôt que description ; note vocale
   plutôt que paragraphe.
6. **Valeurs pré-remplies intelligentes** : date du jour, dernier bâtiment
   visité, quantité de la veille comme point de départ.

**Confiance et état système**
7. **Statut de sync toujours visible** (badge : synchronisé / en attente / à
   corriger) — l'utilisateur terrain doit savoir d'un coup d'œil si son
   travail est « au chaud », sinon il ressaisit ou doute de l'outil.
8. **Jamais de spinner bloquant** : tout est optimiste, la confirmation est
   instantanée, la sync se fait en arrière-plan.
9. **Messages d'erreur en français simple et actionnable** (« Ce lot a déjà
   son pointage aujourd'hui — modifier celui-ci ? »), jamais de jargon
   technique.

**Sobriété matérielle**
10. **Budget performance strict** : app-shell < 200 Ko gzippé, interactive
    < 3 s sur un Android à 60 €, économie de batterie (pas de GPS continu,
    sync par lots), consommation data minimale (deltas uniquement, photos
    compressées et poussées en différé sur wifi si configuré).

---

## 5. Les 3 ateliers à tenir avant de valider l'architecture

### Atelier 1 — Personas & parcours terrain (avec les utilisateurs réels)
Une demi-journée par ferme pilote, avec un gardien, un caissier, un
magasinier, un vétérinaire : observer les opérations réelles, chronométrer la
saisie actuelle, faire maquetter les 3 écrans « home par rôle ».
**Décisions de sortie** : la liste fermée des opérations V1 par persona, le
critère de succès mesurable (cf. §1.3), et le choix de la ferme pilote.

### Atelier 2 — Matrice offline & règles de conflit (avec la direction métier)
Passer en revue la table du §3.3 opération par opération et faire **signer**
ce qui est autorisé hors-ligne, ce qui reste en brouillon, ce qui exige le
réseau. C'est une décision de **gouvernance des données**, pas de technique :
qui a le droit d'engager du stock ou de l'argent sans connexion ?
**Décisions de sortie** : la matrice opération × (offline / brouillon / en
ligne) validée, la politique de durée de vie des tokens device (téléphone
perdu), et le périmètre de données embarquées par rôle (un gardien
télécharge-t-il les prix de vente ?).

### Atelier 3 — Trajectoire plateforme & distribution (avec la DSI / les décideurs)
Valider la recommandation PWA → Capacitor **avec ses déclencheurs de bascule
chiffrés** (§2.2), sur la base d'un inventaire réel du parc (OS, versions,
RAM, taille écran) et de la couverture réseau des sites.
**Décisions de sortie** : la techno actée avec ses critères de sortie, le
sous-domaine d'hébergement (`app.*`), la stratégie de mise à jour et de
support (combien de versions d'Android minimum ?), et le budget des phases
0-1.

---

## 6. Synthèse en une phrase

Une **PWA compagnon organisée par tâche et par rôle**, offline-first avec
outbox idempotente et conflits gradués par classe d'opération, construite sur
l'API v1 et les Actions partagées existantes, livrée par phases en commençant
par l'élevage (backend déjà prêt), avec Capacitor en rampe de secours pour
iOS/stores — et trois ateliers de validation avant la première ligne de code.
