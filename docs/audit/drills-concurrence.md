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

## C2 — Double validation d'une dépense → double décaissement (2026-09-20)

| | |
|---|---|
| **Scénario** | 2 validations simultanées de la MÊME dépense espèces de 300 000 GNF (double-clic sur « Valider », ou re-POST après timeout 3G) |
| **Preuve (avant)** | Sur 5 essais : **2 × DEUX écritures** → 600 000 GNF sortis pour 300 000, solde 9 400 000 au lieu de 9 700 000. 2 essais sauvés par un **interblocage InnoDB** (verrou partagé de clé étrangère sur `treasury_accounts`, puis `UPDATE` exclusif du solde) — le perdant recevait une `QueryException` brute, pas un refus métier. 1 essai sauvé par `alreadyPosted()` arrivé à temps. **Trois issues pour le même geste.** |
| **Cause (deux étages)** | ① `ApproveExpense` contrôlait `status !== 'en_attente'` sur une dépense lue **sans verrou et hors transaction** — la garde en carton de la leçon n°1 ; ② `TreasuryPostingService::alreadyPosted()` est un `SELECT … EXISTS` joué **hors** de la transaction qui écrit, et la clé d'idempotence `(source_type, source_id)` ne portait qu'un index **SIMPLE** |
| **Correctif** | ① `DB::transaction` + `Expense::lockForUpdate()` + re-contrôle **sous** le verrou (idem `PayrollController::markPaid`, qui avait la même faille) ; ② **index UNIQUE** `treasury_tx_source_unique (source_type, source_id)` — migration `2026_09_20_000001`, qui **dédoublonne d'abord et recrédite le compte** (un doublon avait déplacé le solde une seconde fois) ; ③ la violation d'unicité est **ravalée** dans `TreasuryPostingService` : pour le perdant de la course, « déjà comptabilisé » est la bonne réponse, pas une erreur 500 |
| **Contre-preuve** | **10 essais sur 10** : `VALIDEE` + `REFUSEE « Seule une dépense en attente peut être validée. »` → **1 écriture, solde 9 700 000** à chaque fois ✅ (et le refus est désormais métier, plus un interblocage) |

> **Pourquoi B2 était déclaré ✅ alors que ce trou existait.** `DatabaseConstraintGuardTest`
> dérive sa liste du schéma — bonne idée — mais n'énumère que les tables portant une
> colonne `uuid` (`if (! in_array('uuid', …)) continue;`). La clé d'idempotence de
> `treasury_transactions` n'est pas un uuid : le garde-fou était **structurellement
> aveugle** au seul cas qui manquait. Son propre commentaire dit pourtant que c'est
> exactement le piège qu'il cherchait à supprimer.

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
