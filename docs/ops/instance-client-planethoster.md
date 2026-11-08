# Nouvelle instance client — PlanetHoster (mutualisé N0C)

Mettre en service une instance **propre et isolée** de l'ERP pour un client, sur
un hébergement mutualisé PlanetHoster, **sans toucher au site actuel**, puis la
mettre à jour sans jamais perdre ses données ni écraser sa configuration.

Outils livrés avec cette procédure :

| Fichier | Rôle |
|---|---|
| `scripts/deploy/deploy-instance.sh` | Déploie une instance (première installation ou mise à jour), avec garde-fous. Même script en CI et depuis un poste. |
| `.github/workflows/deploy-client.yml` | Bouton « Deploy client instance » : déploiement manuel, un environnement GitHub par client. |
| `.env.client.example` | Modèle de configuration d'une instance client (copié une seule fois). |
| `php artisan license:status` | État de la licence, en lecture seule (certificat + token), avant / après déploiement. |

Le site actuel continue d'être déployé par `deploy.yml` (« Deploy pilot »), **qui
n'est pas modifié**. Le nouveau workflow refuse d'écrire dans ses dossiers.

---

## 0. Ce qui rend l'instance isolée

| Élément | Instance client | Pourquoi c'est étanche |
|---|---|---|
| Code | `~/clients/<client>/erp` | dossier propre, hors racine web |
| PWA terrain | `~/clients/<client>/app` | dossier propre, `--delete` limité à lui |
| Domaines | `erp.<domaine-client>` + `app.<domaine-client>` | racines N0C dédiées, SSL propre |
| Base | base + utilisateur MySQL dédiés | l'utilisateur ne voit que sa base |
| Configuration | `.env` du client (chmod 600) | jamais transféré ni modifié par un déploiement |
| Fichiers, photos, sauvegardes | `storage/` de l'instance | jamais transféré ni modifié |
| Sessions, cache | `SESSION_COOKIE` et `CACHE_PREFIX` propres | aucun mélange entre instances |
| Licence | clé publique (`.env`) + code activé (base) **propres au client** | aucune autre instance n'est concernée |
| Tâches planifiées | 2 lignes cron **ajoutées**, pointant sur l'instance | les lignes du site actuel ne changent pas |
| Déploiement | environnement GitHub `client-<client>` + clé SSH dédiée | secrets séparés, déploiement à la demande |

> **Compte PlanetHoster dédié ou partagé ?** Les deux fonctionnent. Un compte N0C
> séparé donne l'isolation maximale (PHP, quotas, accès SSH distincts) : c'est le
> choix conseillé si le client paie son propre hébergement. Sur le même compte,
> l'isolation repose sur les points ci-dessus.

---

## 1. Côté fournisseur : le certificat et le token du client

Vocabulaire de l'application :

- **Certificat** = la **clé publique** Ed25519 qui vérifie les codes de licence
  (`LICENSE_PUBLIC_KEY`, dans le `.env` du client). Sa clé **privée** reste chez
  vous : jamais sur le serveur, jamais dans GitHub, jamais dans un e-mail.
- **Token de monétisation** = le **code de licence signé** (plan, modules,
  quotas, échéance). Il s'active dans l'application et vit en base (table
  `licenses`) : aucun déploiement n'y touche.

Sur votre poste (dépôt cloné, `composer install` fait) :

```bash
# 1. Paire de clés PROPRE à ce client (une fuite n'engage que lui)
php artisan license:keygen
#   LICENSE_PUBLIC_KEY=…   → ira dans le .env du client (§4.2)
#   <clé privée>           → coffre de mots de passe, fiche « Licence <client> »

# 2. Code de licence, LIÉ AU DOMAINE du client (anti-copie)
php artisan license:issue \
  --id=KINDIA-01 --client="Ferme de Kindia" \
  --plan=pro --days=366 \
  --domain=erp.kindia-ferme.com \
  --private-key='<clé privée du client>'
#   → code à remettre au client (ou à activer vous-même, §4.5)
```

`--domain` doit être **exactement** l'hôte de `APP_URL` du client
(`https://erp.kindia-ferme.com` → `erp.kindia-ferme.com`), sinon l'activation est
refusée (« lié à un autre domaine »).

---

## 2. Côté PlanetHoster (panneau N0C)

Dans l'ordre, sans toucher à ce qui existe :

1. **Domaines** : ajouter le domaine du client (ou des sous-domaines du vôtre) :
   - `erp.<domaine-client>` → **dossier racine** `clients/<client>/erp/public` ;
   - `app.<domaine-client>` → **dossier racine** `clients/<client>/app`.
   Décidez ces racines **avant** de déployer (piège n°1 du pilote : un domaine
   dont la racine pointe ailleurs affiche « Index of / »).
2. **SSL** : Let's Encrypt sur les deux. Obligatoire : sans HTTPS, ni PWA, ni
   caméra (scan QR), ni service worker.
3. **PHP 8.3** pour le compte, avec `gd intl mbstring sodium bcmath pdo_mysql zip curl fileinfo`.
4. **Base MySQL** : créer **une base et un utilisateur dédiés** (tous droits sur
   cette base uniquement). La créer **avant** l'installation : l'utilisateur
   mutualisé n'a pas le droit `CREATE DATABASE`.
5. **SSH** (port **5022**) : ajouter la clé **publique** de déploiement propre à
   ce client dans *Accès SSH › Clés*. Générer la paire sur votre poste :
   `ssh-keygen -t ed25519 -N '' -C deploy-<client> -f ~/.ssh/deploy-<client>`.

---

## 3. Côté GitHub : l'environnement du client

*Settings › Environments › New environment* → nom **`client-<client>`**
(ex. `client-kindia`). Le workflow refuse tout autre nom.

Secrets de l'environnement :

| Secret | Exemple |
|---|---|
| `CLIENT_SSH_HOST` | `node42.n0c.com` |
| `CLIENT_SSH_PORT` | `5022` |
| `CLIENT_SSH_USER` | utilisateur SSH du compte |
| `CLIENT_SSH_KEY` | contenu de `~/.ssh/deploy-<client>` (clé **privée**) |
| `CLIENT_WEB_PATH` | `/home/USER/clients/kindia/erp` |
| `CLIENT_PWA_PATH` | `/home/USER/clients/kindia/app` |
| `CLIENT_PUBLIC_URL` | `https://erp.kindia-ferme.com` |
| `CLIENT_API_BASE_URL` | `https://erp.kindia-ferme.com/api/v1` |

Conseillé : *Required reviewers* sur l'environnement, pour qu'un déploiement
client soit validé par une seconde personne.

---

## 4. Première installation

### 4.1 Déposer le code

*Actions › Deploy client instance › Run workflow* :
`environment = client-kindia`, `ref` = le **tag de release**, `mode = first-install`.

(Sans GitHub, depuis un poste : construire comme au §5.3, puis
`MODE=first-install INSTANCE=client-kindia SSH_HOST=… SSH_PORT=5022 SSH_USER=… SSH_KEY=~/.ssh/deploy-<client> WEB_PATH=… PWA_PATH=… scripts/deploy/deploy-instance.sh`.)

Le script refuse s'il trouve déjà une instance installée dans ce dossier. Il
dépose le code et la PWA, crée `.env` depuis `.env.client.example` (droits 600)
et génère `APP_KEY`.

### 4.2 Compléter le `.env` du client (SSH)

```bash
ssh -p 5022 USER@node42.n0c.com
nano ~/clients/kindia/erp/.env
```

À renseigner au minimum :

| Clé | Valeur |
|---|---|
| `APP_URL` | `https://erp.kindia-ferme.com` (= le `--domain` de la licence) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | la base dédiée du §2.4 (mot de passe entre guillemets) |
| `SESSION_COOKIE`, `CACHE_PREFIX` | propres au client, ex. `avismart_kindia_session`, `avismart-kindia-` |
| `CORS_ALLOWED_ORIGINS` | `https://app.kindia-ferme.com` |
| `LICENSE_PUBLIC_KEY` | le **certificat** du §1 |
| `BACKUP_ARCHIVE_PASSWORD` | un mot de passe propre au client (à garder hors du serveur) |
| `MAIL_*` | la boîte du domaine du client (`MAIL_FROM_ADDRESS` = `MAIL_USERNAME`) |
| `WHATSAPP_DRIVER` (+ clés) | `log` tant qu'aucun fournisseur n'est choisi ; `ultramsg` ou `twilio` ensuite |

Les variables d'environnement du panneau N0C ne s'appliquent pas à une
application PHP : le `.env`, hors racine web et en droits 600, **est** le
mécanisme. Gardez-en une copie dans votre coffre (fiche du client).

Puis : `cd ~/clients/kindia/erp && php artisan config:clear`.

### 4.3 Assistant d'installation

Ouvrir `https://erp.kindia-ferme.com/install` : prérequis → base → migrations →
**administrateur du client** → fin. L'assistant **supprime d'office tous les
comptes de démonstration** et se verrouille. Puis : `php artisan optimize`.

### 4.4 Tâches planifiées (N0C › Tâches cron) — AJOUTER, ne rien modifier

```cron
* * * * * cd ~/clients/kindia/erp && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/clients/kindia/erp && flock -n storage/framework/queue.lock php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

Les lignes du site actuel restent telles quelles.

### 4.5 Activer le token du client

Se connecter en administrateur → **Administration › Licence** → saisir
l'**identifiant** (`KINDIA-01`) et le **code** du §1. Vérifier en SSH :

```bash
php artisan license:status
# Certificat : présent · empreinte …   Token : KINDIA-01   Statut : active
```

Notez l'empreinte du certificat dans la fiche du client : c'est elle qui prouve,
après chaque mise à jour, que rien n'a changé.

### 4.6 Finitions et contrôle

```bash
php artisan push:generate-keys          # notifications sur les téléphones
php artisan avismart:diagnostic         # liste ce qui reste à régler
```

Le diagnostic d'une instance neuve signale typiquement : WhatsApp en mode
journal, clés de notification (corrigé ci-dessus), comptes sans site. Rattacher
chaque compte (administrateur compris) au site du client dans **Utilisateurs**.

Test de bout en bout : ouvrir `https://app.kindia-ferme.com` sur un téléphone,
se connecter, saisir un pointage, le retrouver au bureau.

---

## 5. Mettre à jour l'instance (procédure à suivre à chaque version)

### 5.1 Avant

1. Choisir un **tag de release** vert en CI (le workflow refuse un commit sans CI verte).
2. Prévenir le client si la version contient une migration lourde (heures creuses).
3. Recommandé : un **essai à blanc** (`dry_run = true`) liste les fichiers qui
   seraient transférés, sans rien écrire.

### 5.2 Déployer

*Actions › Deploy client instance* → `environment = client-kindia`,
`ref = vX.Y.Z`, `mode = update` → *Run*.

Le script enchaîne, et **s'arrête au premier problème, avant d'avoir rien écrit** :

| Étape | Ce qui se passe | Arrêt si… |
|---|---|---|
| 1. Contrôle | `.env` présent, `APP_KEY` posée, base joignable, licence lue, **sauvegarde de la base** | un de ces points manque, ou la sauvegarde échoue |
| 2. Code web | `rsync` **sans suppression** ; jamais `.env`, `storage/`, `public/storage`, `.well-known/`, `.user.ini` | erreur de transfert |
| 3. PWA | `rsync --delete` limité au dossier de la PWA, `.well-known/` préservé | erreur de transfert |
| 4. Finalisation | maintenance → `migrate --force` → `optimize` → remise en ligne (même en cas d'échec) ; **preuve que le `.env` n'a pas changé** (empreinte avant/après) ; licence relue | migration en erreur, `.env` modifié |
| 5. Vérifications | `/up` et `/api/v1/health` en 200 ; `avismart:diagnostic` | un des deux ne répond pas 200 |

Les **migrations** ne font qu'ajouter ou faire évoluer le schéma : les données du
client, son administrateur et son **token** (table `licenses`) restent en place.
Le **certificat** est dans le `.env`, qui n'est ni lu ni écrit par le transfert.

### 5.3 Sans GitHub (depuis un poste)

```bash
git checkout vX.Y.Z
composer install --no-dev --optimize-autoloader
npm ci && npm run build
(cd mobile && npm ci && VITE_API_BASE_URL=https://erp.kindia-ferme.com/api/v1 npm run build)

INSTANCE=client-kindia MODE=update \
SSH_HOST=node42.n0c.com SSH_PORT=5022 SSH_USER=USER SSH_KEY=~/.ssh/deploy-kindia \
WEB_PATH=/home/USER/clients/kindia/erp PWA_PATH=/home/USER/clients/kindia/app \
PUBLIC_URL=https://erp.kindia-ferme.com \
PROTECTED_PATHS=/home/USER/files/erp,/home/USER/dist \
scripts/deploy/deploy-instance.sh
```

Toujours renseigner `PROTECTED_PATHS` avec les dossiers du site actuel : le
script refuse alors de les viser, même par erreur de copier-coller.

### 5.4 Après (5 minutes)

- [ ] Le journal du workflow affiche `✓ .env intact` et la **même empreinte de
      certificat** que dans la fiche du client, avec `Statut : active`.
- [ ] Connexion administrateur, un écran de chaque module utilisé.
- [ ] Sur un téléphone : l'app propose la mise à jour, une synchronisation passe.
- [ ] `storage/logs/laravel.log` sans nouvelle erreur.
- [ ] Une ligne au journal des déploiements (`deploy-runbook.md` §6).

### 5.5 Retour arrière

```bash
# Code : relancer le workflow avec le tag PRÉCÉDENT (mode update).
# Base, si une migration a abîmé des données : restaurer la sauvegarde prise
# à l'étape 1 (module Sauvegardes, ou storage/app/backups/…/<date>.zip) —
# cf. backup-restore-runbook.md §5.
```

---

## 6. Renouveler la licence ou changer de certificat

- **Renouvellement / changement de plan** : émettre un nouveau code
  (`license:issue`, même `--id`, même `--domain`), l'activer dans
  **Administration › Licence**. Aucun déploiement.
- **Changement de domaine** : mettre à jour `APP_URL`, puis émettre un code lié
  au nouveau domaine.
- **Rotation du certificat** (clé privée compromise) : nouvelle paire
  (`license:keygen`), remplacer `LICENSE_PUBLIC_KEY` dans le `.env`
  (`php artisan config:clear`), émettre et activer un nouveau code. Les codes
  signés par l'ancienne clé deviennent invalides — c'est le but.

---

## 7. Pièges mutualisé constatés

| Symptôme | Cause | Remède |
|---|---|---|
| « Index of / » ou 404 | racine du domaine ≠ `…/erp/public` | N0C › Domaines : corriger la racine |
| `/install` : « Access denied » sur la base | base non créée avant | créer base + utilisateur dans N0C, relancer |
| Sauvegarde en échec « ZipArchive::close(): Invalid argument » | `BACKUP_ARCHIVE_PASSWORD=` vide sur une version antérieure | renseigner un mot de passe (corrigé dans le code : vide = pas de chiffrement) |
| Sauvegarde en échec « mysqldump not found » | binaire hors PATH | `DB_DUMP_BINARY_PATH=/usr/bin` (chemin donné par `which mysqldump`) |
| SSL non renouvelé sur `app.*` | `.well-known/` effacé | préservé par le script ; ne jamais vider le dossier à la main |
| « Ce code est lié à un autre domaine » | `--domain` ≠ hôte de `APP_URL` | réémettre le code avec le bon domaine |
| Saisies mobiles refusées « bâtiment invalide » | compte sans site | Utilisateurs : rattacher le compte au site |
| `scp`/`ssh` refusés | port 22 au lieu de 5022 | `ssh -p 5022`, `scp -P 5022` |

---

## 8. Fiche de l'instance (à tenir dans le coffre)

| Champ | Valeur |
|---|---|
| Client / identifiant licence | |
| Domaines (ERP / PWA) | |
| Compte N0C, hôte SSH, utilisateur | |
| Dossiers (`WEB_PATH`, `PWA_PATH`) | |
| Base / utilisateur MySQL | |
| Environnement GitHub | `client-…` |
| Empreinte du certificat (`license:status`) | |
| Échéance de la licence | |
| Copie chiffrée du `.env` | (lien coffre) |
| Clé privée de licence | (lien coffre — jamais ailleurs) |
