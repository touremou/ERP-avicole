# Mise en production — liste de contrôle

> **À imprimer ou dupliquer, puis cocher.** Une case non cochée dans une section
> marquée ⛔ **bloque** la mise en production. Conserver l'exemplaire signé.

| | |
|---|---|
| **Version déployée** (tag git) | ________________ |
| **Ferme pilote** | ________________ |
| **Serveur** (hôte, mode A/B/C — cf. [`deploy-runbook.md`](deploy-runbook.md)) | ________________ |
| **Responsable technique** | ________________ |
| **Date prévue de démarrage** | ________________ |

---

## Ce que couvre ce document — et ce qu'il ne couvre pas

**Il couvre** un **démarrage contrôlé sur votre propre exploitation** : une ferme
pilote d'abord, la paie et la comptabilité menées en parallèle de votre méthode
actuelle le premier mois.

**Il ne couvre pas la commercialisation** de l'ERP à d'autres clients. Celle-ci
demande en plus, au minimum :

- un **test d'intrusion par un tiers indépendant** — l'audit interne a trouvé des
  défauts jusque dans ses propres correctifs ; une revue extérieure est nécessaire ;
- l'**activation du système de licence** (inactif par défaut — cf. [`INSTALLATION.md` §5](../INSTALLATION.md)) ;
- des **tests automatisés pour l'application mobile**, qui n'en a presque aucun
  aujourd'hui (le contrôle de types du build, et quelques fonctions pures de la
  file d'envoi) — ni ses écrans ni sa synchronisation hors-ligne de bout en bout ;
- la conformité hors code : protection des données personnelles (loi guinéenne
  L/2016/037), contrats et conditions de vente, support, facturation.

---

## 1. ⛔ Avant le déploiement

- [ ] La version à déployer est un **tag git** posé sur `main`, jamais un dossier modifié à la main.
- [ ] La CI est **verte sur ce tag** (tests SQLite **et** « Tests + migrations MySQL 8 »).
- [ ] Si une installation tourne déjà : **sauvegarde complète** prise juste avant
      (`php artisan backup:run`), et son emplacement noté : ________________
- [ ] La procédure de **retour arrière** du mode de déploiement retenu a été lue
      ([`deploy-runbook.md`](deploy-runbook.md)).

## 2. ⛔ Configuration du serveur

Dans le fichier `.env` du serveur (partir de `.env.production.example`, jamais
d'un `.env` de développement) :

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` — en débogage, toute erreur affiche au visiteur les
      variables d'environnement, **identifiants de base de données compris**.
- [ ] `LOG_LEVEL=warning`
- [ ] `APP_KEY` **généré sur ce serveur** (`php artisan key:generate`), jamais recopié
      d'un poste de développement.
- [ ] `SESSION_SECURE_COOKIE=true`, et **HTTPS** obligatoire avec un certificat valide.
- [ ] **Secrets propres à la production**, aucun repris d'un environnement de test :
      `DB_PASSWORD`, `MAIL_PASSWORD`, `WHATSAPP_API_KEY`, `BACKUP_ARCHIVE_PASSWORD`,
      et `TELEMETRY_API_KEY` si des capteurs sont utilisés. Ils sont rangés dans un
      coffre, pas dans un document partagé.
- [ ] Le fichier `.env` n'est **pas** lisible depuis le web, et n'est **pas** versionné.

> **Note sur `APP_KEY`** : aucune donnée n'est chiffrée avec cette clé dans cette
> application ; la changer plus tard ne perd donc pas de données, mais
> **déconnecte tous les utilisateurs** et invalide les liens signés en cours.

## 3. ⛔ Base de données

- [ ] **Toutes les tables sont en InnoDB.** Sur MyISAM, les transactions et les
      verrous ne protègent rien et les clés étrangères sont ignorées — c'est la
      « trouvaille majeure » des drills de concurrence. Cette requête doit
      renvoyer **zéro ligne** :

      ```sql
      SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB';
      ```

- [ ] Migrations à jour : `php artisan migrate --force` termine sans erreur.

## 4. ⛔ Installation

**Installation neuve** — par l'assistant `/install` :

- [ ] Les cinq étapes sont allées jusqu'au bout (écran « Terminé » affiché).
- [ ] L'adresse du compte administrateur est **la vôtre**, pas une adresse de démonstration.
- [ ] `/install` renvoie désormais vers la page de connexion.

> L'assistant **supprime tous les comptes de démonstration** à l'étape 4. Ils ont
> tous le mot de passe public `password`. Une installation faite **avant** ce
> correctif peut encore les porter : c'est le point suivant qui le dira.

**Installation existante** — mise à jour :

- [ ] `php artisan migrate --force` passé ; les caches régénérés
      (`config:cache`, `route:cache`, `view:cache`, `event:cache`).

**Si cette installation a servi aux essais ou à la formation** — à faire **avant**
le diagnostic, et **uniquement avant toute vraie facture** :

- [ ] **Données de démonstration** — ce que le système a semé (comptes de
      démonstration, « Bâtiment A », culture de démonstration) :

      ```bash
      php artisan avismart:remove-demo-data          # liste ce qui partirait
      php artisan avismart:remove-demo-data --force  # retire
      ```

      Un élément qui porte un historique réel — un lot dans « Bâtiment A », un
      vrai cycle sur une parcelle — est **gardé**, et la commande dit pourquoi.
- [ ] **Données de test** — les saisies d'essai (lots, pointages, ventes,
      dépenses, trésorerie, paie…). Les bâtiments, employés, clients,
      fournisseurs, articles de stock, la configuration et les comptes sont
      **gardés** :

      ```bash
      php artisan avismart:reset-test-data                                  # simulation : LIRE le rapport
      php artisan avismart:reset-test-data --force --confirmer="Nom exact de l'entreprise"
      ```

      La commande prend une **sauvegarde de la base juste avant** et s'arrête si
      elle échoue. ⚠️ **La numérotation des factures repart de 1** : c'est voulu
      avant la mise en service, et c'est pourquoi on ne la lance **jamais**
      après avoir remis de vraies factures.
- [ ] Après la remise à zéro : **inventaire d'ouverture** des stocks et des
      matières premières, **niveaux réels** des cuves et citernes, **compteurs**
      des groupes et machines, **soldes d'ouverture** des comptes de trésorerie.

## 5. ⛔ Diagnostic

```bash
php artisan avismart:diagnostic
```

- [ ] Le résumé final indique **0 bloquant**, et la commande sort avec le code `0`.

Il contrôle notamment : le mode débogage, les **comptes de démonstration encore au
mot de passe public**, les canaux d'alerte (WhatsApp, e-mail, push), le rattachement
des comptes aux sites, l'identité de l'émetteur des documents (NIF, RCCM), les
sauvegardes et le planificateur. Chaque point bloquant est accompagné de son remède.

Points d'attention (non bloquants) relevés : ________________________________

## 6. ⛔ Remise en ordre des données (installations existantes)

Les correctifs de l'audit ont corrigé des calculs ; les données saisies **avant**
peuvent porter les anciens résultats. Chaque commande est **en simulation par
défaut** : la lancer d'abord **sans** `--force`, **lire le rapport**, puis la
relancer **avec** `--force` si les écarts sont compris.

| Commande | Ce qu'elle remet d'aplomb | Simulation lue | Appliquée |
|---|---|:---:|:---:|
| `php artisan batches:rebuild-quantities` | Effectifs vivants des lots, depuis les pointages | ☐ | ☐ |
| `php artisan stocks:sync` | Effectifs des lots et écarts du stock d'œufs | ☐ | ☐ |
| `php artisan eggs:repair-stock` | Tris d'œufs jamais entrés au magasin | ☐ | ☐ |
| `php artisan clients:repair-balances` | Soldes clients faussés par les acomptes sur brouillon | ☐ | ☐ |
| `php artisan treasury:repair-balances` | Soldes de trésorerie contre le grand-livre — **en dernier** | ☐ | ☐ |

> `batches:rebuild-quantities --force` tourne ensuite **chaque nuit** d'elle-même.
> `treasury:repair-balances` tourne **chaque lundi à 6 h en rapport seul** : elle
> signale, elle ne corrige pas. Toute correction de trésorerie reste un geste humain.

## 7. ⛔ Sauvegardes — la seule preuve est une restauration

- [ ] Le **planificateur** tourne : la ligne cron est en place
      (`* * * * * cd <racine> && php artisan schedule:run >> /dev/null 2>&1`) et
      `php artisan schedule:list` affiche les tâches.
- [ ] `BACKUP_ARCHIVE_PASSWORD` est renseigné (archives chiffrées).
- [ ] `BACKUP_DISKS=backups,backups_offsite`, et `BACKUP_OFFSITE_PATH` pointe **hors
      de la machine** (NAS, disque externe, autre serveur). Une copie sur le même
      disque meurt avec lui.
- [ ] `php artisan backup:run` a produit une archive, visible par `php artisan backup:list`.
- [ ] **Une restauration a été réussie sur la machine de production**, en suivant
      [`backup-restore-runbook.md` §5](backup-restore-runbook.md), et son
      procès-verbal consigné au §6 de ce même runbook. Date : ________________

> Le plan d'audit classe ce point **bloquant n°1** : une sauvegarde jamais restaurée
> n'est pas une sauvegarde.

## 8. Concurrence

- [ ] Les drills deux-processus ont été rejoués **sur la machine de pré-production**
      ([`../audit/drills-concurrence.md`](../audit/drills-concurrence.md)) : deux
      ventes simultanées du dernier stock, deux validations de la même dépense,
      deux encaissements dépassant le dû, deux créations de lots dépassant la
      capacité, deux rejeux de la même file hors-ligne. Résultat attendu à chaque
      fois : **une seule opération passe, l'autre est refusée proprement.**

## 9. Notifications

- [ ] `whatsapp.admin_phone` est renseigné (Réglages › WhatsApp) : c'est le destinataire
      des alertes critiques **et des alertes d'erreur serveur**.
- [ ] L'e-mail fonctionne : cocher « E-mail » dans *Notifications › Préférences*, puis
      lancer l'envoi de test depuis ce même écran ; le message est reçu.

## 10. Recette sur la ferme pilote

À dérouler **par un utilisateur réel**, sur la machine de production, avant d'ouvrir à tous :

- [ ] Connexion web, puis bascule sur la ferme pilote.
- [ ] Création d'un lot, puis un pointage journalier.
- [ ] Vente au point de vente, encaissement, impression du ticket.
- [ ] Saisie d'une dépense, puis sa validation par un responsable.
- [ ] Mouvement de stock (entrée puis sortie).
- [ ] **Application terrain** : connexion, synchronisation, un pointage saisi **hors
      réseau** puis envoyé au retour du réseau — et vérifié présent sur le web.

## 11. Comptabilité et paie

- [ ] Le **premier mois**, la paie est **aussi** calculée par la méthode actuelle, et
      les deux résultats comparés bulletin par bulletin.
- [ ] Les **exports comptables** ont été validés par le comptable **sur des données réelles**.
- [ ] **Décision à prendre avec le comptable avant la première clôture** : aujourd'hui,
      annuler un paiement **supprime** l'écriture de trésorerie au lieu de passer une
      contre-écriture. Pour une piste d'audit conforme (référentiel SYSCOHADA), il
      faudra probablement la contre-passation. Décision : ________________

## 12. Démarrage et première semaine

- [ ] Ouverture à **la seule ferme pilote**.
- [ ] Chaque jour de la première semaine : relancer `php artisan avismart:diagnostic`,
      et lire les alertes d'erreur reçues sur WhatsApp.
- [ ] Critère de retour arrière décidé à l'avance : ________________
      (procédure : [`deploy-runbook.md`](deploy-runbook.md)).
- [ ] Bilan à J+7 avant d'ouvrir aux autres fermes.

---

## Signatures

| Rôle | Nom | Date | Signature |
|---|---|---|---|
| Responsable technique | | | |
| Responsable d'exploitation | | | |
| Comptable (§11) | | | |

*Document maintenu avec l'application. Toute commande citée ici doit exister dans
la version déployée : vérifier avec `php artisan help <commande>` en cas de doute.*
