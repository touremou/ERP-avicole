# PV — Drills de concurrence (audit 360° §2.2, C1/C3/C5)

> **Protocole commun** (2026-07-02, MySQL 8.4.7, machine dev) : deux processus PHP
> indépendants bootstrappent Laravel, guettent un fichier-drapeau commun, puis
> exécutent la MÊME opération métier au même instant sur la même ressource.
> Un drill « rouge » prouve la faille ; le correctif n'est accepté que si le
> MÊME drill repasse « vert » (contre-preuve). Scripts jetables (scratchpad),
> données préfixées `C*DRILL` puis purgées.

## Prérequis découvert par C1 — moteur InnoDB (trouvaille majeure)

Le premier correctif C1 ne mordait pas : investigation → **les 118 tables dev
étaient en MyISAM** (WAMP règle `default_storage_engine=MyISAM` et
`config/database.php` avait `engine => null`). Sur MyISAM : `DB::transaction`
no-op, `lockForUpdate` décoratif (2 process « acquièrent » le même verrou),
FK des migrations ignorées à la création, tables non crash-safe.
**Corrections** : `engine => 'InnoDB'` forcé dans la config (toutes les installs) ;
base dev convertie 118/118 (`ALTER … ENGINE=InnoDB` — ne recrée PAS les FK
historiquement avalées) ; contrôle du moteur ajouté au runbook de déploiement.

## C1 — Vente simultanée du dernier stock

| | |
|---|---|
| **Scénario** | 2 validations simultanées de ventes de 10 kg, stock = 10 kg |
| **Preuve (avant)** | `SALE A: VALIDATED` + `SALE B: VALIDATED` → stock 0, **2 ventes servies** (sur-vente silencieuse — jamais de négatif grâce au `max(0,…)`, mais écart d'inventaire garanti) |
| **Cause** | `ValidateSale::destockItem` contrôlait la disponibilité sur un stock lu **sans verrou** |
| **Correctif** | Résolution `Stock::lockForUpdate()` (et `Batch::lockForUpdate()` dans `destockBatch`) — contrôle sérialisé sous la transaction |
| **Contre-preuve** | `SALE A: VALIDATED (0,18 s)` + `SALE B: REFUSED « Stock insuffisant … disponible 0 »` → **1 vente validée** ✅ |

## C3 — Double encaissement dépassant le dû

| | |
|---|---|
| **Scénario** | 2 paiements simultanés de 60 000 sur une vente due 100 000 |
| **Preuve (avant)** | 2 × `ACCEPTED` → **120 000 encaissés / 100 000 dus** (solde client faux) |
| **Cause** | `RecordPayment` contrôlait « soldée / statut / reste dû » **avant** la transaction, sur une vente lue sans verrou (la garde du FormRequest, elle aussi, est hors verrou par nature) |
| **Correctif** | Toute la vérification re-jouée **sous** `Sale::lockForUpdate()` dans la transaction — le verrou de la ligne vente sert de mutex d'encaissement |
| **Contre-preuve** | `ACCEPTED` + `REFUSED « 60 000 dépasse le reste dû (40 000) »` → **60 000 / 100 000** ✅ |

## C5 — Capacité bâtiment dépassée par créations simultanées

| | |
|---|---|
| **Scénario** | 2 créations simultanées de lots de 60 sujets, bâtiment de capacité 100 |
| **Preuve (avant)** | 2 × `CREATED` → **120 sujets / 100 places**, malgré le `Building::lockForUpdate()` déjà présent |
| **Cause (subtile — trace SQL à l'appui)** | La sérialisation par verrou bâtiment FONCTIONNAIT (le 2ᵉ process a attendu 104 ms) mais son `SUM(current_quantity)` était un **consistent read** (snapshot REPEATABLE READ) **aveugle au lot committé** par le concurrent. Diagnostic : sur la même transaction, lecture snapshot = 0, lecture verrouillante = 60 |
| **Correctif** | Lecture d'occupation rendue **verrouillante** (`->lockForUpdate()->sum(...)`) dans `CreateBatch` ET `UpdateBatch::checkBuildingCapacity` (+ verrou du bâtiment cible manquant dans ce dernier) — les locking reads lisent toujours la dernière version committée |
| **Contre-preuve** | `CREATED` + `REFUSED « Capacité insuffisante : 60 demandés, 40 disponibles »` → **60 / 100** ✅ |

## Enseignements transverses

1. **Un verrou ne protège que ce qu'on lit SOUS lui** : tout contrôle
   (disponibilité, reste dû, capacité) fait avant `lockForUpdate` — ou via un
   consistent read après — est une garde en carton. Motif à appliquer à toute
   future écriture concurrente : *verrou → relecture verrouillante → contrôle → écriture*, le tout dans la transaction.
2. **Prouver, pas relire** : C5 avait « le bon code » (verrou présent) et
   échouait quand même ; C1 avait le bon correctif et échouait sur le moteur.
   Seul le drill deux-processus fait foi — à rejouer sur la machine de
   pré-production avant le go-live (mêmes scripts, cf. protocole).
3. Les gardes séquentielles équivalentes sont verrouillées par les suites
   `WorkflowGuard*` ; ces drills couvrent la dimension **parallèle** que les
   tests Pest (mono-processus) ne peuvent pas exercer.
