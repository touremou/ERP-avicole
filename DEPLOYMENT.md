# Déploiement & checklist d'industrialisation — AviSmart ERP

> Guide complet (installation, administration, modules) : [`docs/GUIDE.md`](docs/GUIDE.md)

## 1. Pré-requis serveur

- PHP 8.3+ avec extensions : `pdo_mysql` (ou `pdo_sqlite`), `mbstring`, `gd`, `intl`, `zip`, `curl`, `xml`, `ctype`, `fileinfo`, `tokenizer`, `openssl`, `sodium`
  - `gd` est **obligatoire** : il sert au traitement d'images **et** à la génération des QR de traçabilité (`endroid/qr-code`).
  - `sodium` est **obligatoire** : il vérifie la signature des licences d'abonnement (activation hors-ligne).
  - Recommandées (non bloquantes, vérifiées par l'assistant) : `opcache`, `bcmath`, `pcntl`, `exif`.
- MySQL 8 / MariaDB 10.6+ (ou SQLite pour une petite installation)
- Composer 2, Node 18+ (build des assets)
- HTTPS (certificat valide) + reverse proxy correctement configuré — requis
  aussi pour l'installation en application mobile (PWA, voir guide §1.6)

## 2. Installation

```bash
git clone <repo> && cd ERP-avicole
cp .env.production.example .env        # APP_NAME, APP_URL, mail, WhatsApp…
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan storage:link                # lien public/storage (le service /media reste un filet de sécurité)
```

Démarrer ensuite le serveur web et ouvrir l'application dans un navigateur :
l'**assistant d'installation** (`/install`) prend le relais automatiquement
au premier accès tant qu'aucun compte n'existe en base. Il vérifie les
prérequis serveur, configure la connexion base de données (écrit `DB_*` et
`APP_KEY` dans `.env`, crée la base MySQL si elle n'existe pas), exécute
`migrate` + le seed de référence (espèces, normes, modules…), puis crée le
compte administrateur (remplace `admin@admin.com`) et propose de supprimer le
compte de démonstration `user@users.com`.

Une fois l'assistant terminé, le marqueur `storage/installed` est posé,
l'assistant **bascule automatiquement `.env` en `APP_ENV=production` /
`APP_DEBUG=false`** (plus de fuite de stack-traces) et `/install` redevient
inaccessible.

> **Traçabilité publique** : les pages scannées via les QR codes
> (`/trace/lot/{code}`, `/trace/op/…`, `/trace/recolte/…`, `/trace/expedition/…`,
> `/trace/transformation/…`) sont **volontairement accessibles sans
> authentification** (vérification d'origine par un client/inspecteur) et
> n'exposent aucune donnée financière. Ne pas les bloquer au niveau du
> reverse-proxy/pare-feu. L'étiquette d'un article de stock pointe en revanche
> vers la fiche **interne** (authentifiée).

### Mise à jour d'une instance déjà installée

À chaque déploiement d'une nouvelle version sur une instance existante :

```bash
git pull
composer install --no-dev --optimize-autoloader   # récupère les nouvelles dépendances (ex. endroid/qr-code)
npm ci && npm run build
php artisan migrate --force                         # applique les nouvelles migrations (idempotentes)
php artisan optimize:clear                          # purge les caches avant de les régénérer (§3)
```

Les migrations sont conçues pour être rejouables sans risque (gardes
`Schema::hasTable`, `if (! exists)` sur les seeds de paramètres). Aucune
intervention manuelle n'est requise pour les nouvelles tables
(`notification_templates`, `dashboard_configurations`, `activity_log`) ni pour
le groupe de paramètres « Numérotation ».

> **Dépendances ajoutées** : `endroid/qr-code` (traçabilité QR) et
> `spatie/laravel-activitylog` (journal d'audit). Le `composer install` les
> récupère ; le `php artisan migrate` crée la table `activity_log`. La rétention
> du journal est purgée chaque semaine (`activitylog:clean`, défaut 365 j —
> ajustable dans `config/activitylog.php`).

> **Installation 100% en ligne de commande** (sans passer par `/install`) :
> renseigner `DB_*` dans `.env`, puis `php artisan key:generate`,
> `php artisan migrate --force` et `php artisan db:seed --force` (les
> paramètres `settings` sont créés par la migration
> `2026_06_05_000001_create_settings_table.php`, pas par un seeder dédié).
> Créer ensuite `storage/installed` manuellement pour empêcher l'accès à
> `/install`, et changer le mot de passe du compte `admin@admin.com`.

## 3. Optimisations production (à relancer à chaque déploiement)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> Après toute modification de paramètres en base hors interface (`settings`),
> vider le cache applicatif : `php artisan cache:clear` (le cache des
> paramètres a un TTL d'1 h — voir `App\Models\Setting`).

## 4. Checklist de sécurité avant mise en ligne

- [ ] `APP_DEBUG=false` et `APP_ENV=production`
- [ ] `LOG_LEVEL=warning` (pas `debug`)
- [ ] `APP_KEY` généré, `.env` hors du dépôt et non lisible publiquement
- [ ] HTTPS forcé ; `SESSION_SECURE_COOKIE=true`
- [ ] Permissions disque : `storage/` et `bootstrap/cache/` accessibles en écriture par le serveur web uniquement
- [ ] Rate-limiting actif sur les routes d'authentification (`throttle`)
- [ ] Route `/register` désactivée ou réservée aux administrateurs si l'auto-inscription n'est pas souhaitée
- [ ] Sauvegardes base de données automatisées (quotidiennes minimum)
- [ ] Comptes par défaut / de démonstration supprimés (géré par l'assistant `/install` : remplace `admin@admin.com` et propose de supprimer `user@users.com`)

## 5. Tâches planifiées (cron)

```cron
* * * * * cd /chemin/ERP-avicole && php artisan schedule:run >> /dev/null 2>&1
```

## 6. Sauvegarde & restauration

Sauvegarde **automatisée** via `spatie/laravel-backup` (déclenchée par le cron
de l'étape 5) :

- `backup:run` chaque nuit à 02h00 → archive **base de données + fichiers
  utilisateurs** (`storage/app/public` : logos, photos, justificatifs) dans le
  disque privé `backups` (`storage/app/backups`, hors web, non versionné).
- `backup:clean` à 01h30 applique la stratégie de rétention (cf.
  `config/backup.php`).
- IHM admin : **Notifications → Sauvegardes** (`/backups`) — lister, télécharger,
  ou lancer une sauvegarde à la demande. Réservé à l'administrateur.
- **Important** : conserver une copie HORS serveur (télécharger régulièrement
  ou configurer un disque distant S3 dans `config/backup.php` → `destination.disks`).
- Restauration : décompresser l'archive `.zip`, réimporter le dump SQL
  (`mysql < db-dumps/…sql`) et restaurer `storage/app/public`.

Les alertes e-mail intégrées de spatie sont **désactivées** par défaut (pas de
dépendance mail) ; les échecs sont journalisés et l'âge du dernier backup est
visible dans l'IHM.

## 7. Suivi post-déploiement

- Surveiller `storage/logs/laravel.log` (niveau `warning`+).
- Vérifier l'absence d'erreurs 5xx et de requêtes lentes (envisager un APM).

## 8. Monétisation : licence d'abonnement (fournisseur)

Le système de licence est **OPT-IN** : tant que `LICENSE_PUBLIC_KEY` est absent,
l'application n'impose aucune restriction. Pour commercialiser une instance :

> **Serveur de licence fournisseur** : un mini-serveur autonome (émission,
> révocation, renouvellement, registre, endpoint `/check`) est fourni dans
> [`license-server/`](license-server/README.md). Il vit **chez le fournisseur**
> (il détient la clé privée) et ne doit jamais être livré au client. Les
> commandes `license:keygen` / `license:issue` de l'ERP ci-dessous dépannent
> sans serveur ; le serveur dédié ajoute la persistance et la révocation à
> distance.

### 8.1 Préparation (une seule fois, chez le fournisseur)

```bash
php artisan license:keygen
```

- Conserver la **clé privée** dans un coffre (gestionnaire de secrets). Elle ne
  doit JAMAIS être livrée ni commitée — elle sert à signer tous les codes.
- Poser la **clé publique** dans le `.env` de l'instance cliente :
  `LICENSE_PUBLIC_KEY=<clé publique base64>`. Optionnel : `LICENSE_ENFORCE=true`
  (défaut), `LICENSE_GRACE_DAYS=7`.

### 8.2 Émettre / renouveler un code (à chaque vente)

```bash
php artisan license:issue --id=BIOCREST --client="BioCrest" \
    --plan=pro --days=366 --sms=1000 --private-key=<clé privée>
# Lier au domaine (anti-copie) : --domain=erp.client.com
```

Communiquer au client l'**identifiant** (`--id`) et le **code de validité**
généré. Le client l'active dans *Tableau de bord → Licence* (écran « Prolongez
la date de validité »). La vérification est **hors-ligne** (signature Ed25519,
extension `sodium`) : aucune connexion Internet requise — adapté à l'Afrique.

Plans (`config/license.php`) : `basic`, `pro`, `entreprise` — déverrouillent un
ensemble de modules et fixent les limites (utilisateurs, fermes, quota SMS). Le
quota SMS est décompté à chaque envoi WhatsApp/SMS réel ; à zéro, l'envoi est
bloqué. À l'expiration (+ grâce), l'application redirige vers l'écran d'activation
(un bandeau d'alerte s'affiche pendant la période de grâce).

### 8.2.1 Vérification en ligne hybride (optionnelle)

Par défaut l'ERP est **100 % hors-ligne**. Pour pouvoir **révoquer ou renouveler
à distance** (client ne payant plus, par exemple), renseigner côté client :

```
LICENSE_SERVER_URL=https://licences.votre-domaine.com/check
LICENSE_CHECK_INTERVAL_HOURS=24
```

La commande planifiée `license:sync` (cron quotidien, déjà programmée) interroge
ce serveur. Contrat HTTP (POST JSON) que **votre** serveur doit implémenter :

- requête : `{ "identifiant": "...", "token": "<code>", "expires_at": "..." }`
- réponse : `{ "status": "ok" | "revoked" | "renewed", "token"?: "<nouveau code signé>" }`

`revoked` bloque l'instance immédiatement (même échéance future) ; `renewed`
applique le nouveau code (re-vérifié par signature) ; `ok` lève une révocation
antérieure. En cas de panne réseau, la synchro échoue silencieusement : le jeton
signé hors-ligne reste la référence (aucune interruption en zone rurale).

### 8.3 Protection du code livré

Le PHP est interprété : aucune protection n'est absolue sans **encodeur
bytecode**. Deux paliers, selon le budget.

**Palier 1 — GRATUIT (par défaut pour démarrer)**

La barrière commerciale est la **licence signée** : même lisible, le code est
inutilisable sans un code d'activation valide (signé par votre clé privée). On
ajoute un durcissement « light » sans dépendance :

```bash
# Sur la machine du FOURNISSEUR : produit une copie de distribution durcie
scripts/package-release.sh /chemin/vers/release-client
```

Ce script copie proprement le projet (exclut `.git`, `tests`, `.env`, logs…),
installe les dépendances de production, build les assets, génère les caches,
puis exécute :

```bash
php artisan release:strip /chemin/vers/release-client
```

`release:strip` réécrit tout le PHP de `app/ config/ routes/ database/
bootstrap/` via `php_strip_whitespace()` : **suppression de tous les
commentaires et de la mise en forme**, sans changer la sémantique (le code
reste exécutable). Protection raisonnable, coût nul, risque de casse quasi nul
(ni renommage de symboles, ni dépendance externe). Les vues Blade ne sont pas
touchées (déjà compilées en cache).

> Limite : `release:strip` ne renomme pas les variables/fonctions — c'est une
> gêne, pas un coffre-fort. Suffisant tant que le projet n'est pas largement
> déployé.

**Palier 2 — ENCODEUR (quand le chiffre d'affaires le justifie)**

Encodage bytecode fort, à appliquer au packaging (pas dans ce dépôt) :

- **ionCube PHP Encoder** : ~199 $/an (édition de base) à ~399 $/an (toutes
  versions PHP). *Loader* client **gratuit**.
- **SourceGuardian** : ~199 $ la licence + ~99 $/an de mises à jour. Loader
  client gratuit.

Encoder `app/`, `config/`, `routes/`, `database/` ; laisser `public/`,
`vendor/` et les vues Blade en clair. L'assistant `/install` détecte la
présence du loader côté serveur et l'indique dans les prérequis.

> La « paternité » du code (humain/IA) n'est pas une mesure de sécurité et n'est
> pas détectable de façon fiable : ce qui protège la propriété intellectuelle,
> c'est l'encodage + la licence, pas la dissimulation de l'auteur.

## 9. Notifications : configuration des canaux (WhatsApp / SMS / e-mail)

Par défaut tous les canaux sont **inactifs** (WhatsApp/SMS en mode `log`, mail
non configuré) : aucun message réel n'est envoyé. Configurez chaque canal puis
vérifiez via *Notifications › Préférences* (boutons **WhatsApp / SMS / E-mail**).
Le détail de chaque tentative est consultable dans *Notifications › Historique*.

> Les réglages d'interface (Réglages › WhatsApp / SMS) **priment** sur les
> variables d'environnement ci-dessous. Le quota SMS de la licence (le cas
> échéant) est décompté à chaque envoi WhatsApp/SMS réel abouti.

### 9.1 WhatsApp (Réglages › WhatsApp)

Choisir un *driver* et renseigner la clé API. Drivers supportés :

| Driver | Usage | Réglages requis |
|--------|-------|-----------------|
| `log` | Dév : journalise, n'envoie rien | — |
| `callmebot` | Test gratuit (1 numéro) | `api_key` (obtenue via CallMeBot) |
| `ultramsg` | ~10 $/mois, simple | `api_key` + `instance_id` (+ `api_url` si auto-hébergé) |
| `wati` | WhatsApp Business officiel | `api_key` + `api_url` |
| `twilio` | Entreprise | `api_key` (SID:Token) + `api_url` |

`.env` (repli si non défini en Réglages) :
```dotenv
WHATSAPP_DRIVER=ultramsg
WHATSAPP_API_KEY=xxxxx
WHATSAPP_INSTANCE_ID=instanceXXXX   # ultramsg
```
Renseigner aussi le **Téléphone admin** (filet de secours) et, pour les alertes
critiques, l'**E-mail admin**. `verify_ssl` peut être désactivé pour une
instance auto-hébergée à certificat interne (à éviter en production publique).

### 9.2 SMS (Réglages › SMS)

Passerelle locale (opérateur GSM / agrégateur). Driver `http` = POST
`application/x-www-form-urlencoded` vers l'URL fournie avec les champs
`api_key`, `to`, `text`, `sender`.

| Driver | Effet |
|--------|-------|
| `log` | N'envoie rien, journalise (défaut) |
| `http` | Poste vers la passerelle configurée |

`.env` (repli) :
```dotenv
SMS_DRIVER=http
SMS_API_URL=https://passerelle-operateur.gn/v1/sms/send
SMS_API_KEY=xxxxx
SMS_SENDER=AVISMART
```
> Adapter au format exact de votre passerelle si besoin (champs/headers) dans
> `App\Services\SmsService`.

### 9.3 E-mail (SMTP)

Configuration Laravel standard. `.env` :
```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.votre-fournisseur.com
MAIL_PORT=587
MAIL_USERNAME=xxxxx
MAIL_PASSWORD=xxxxx
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="erp@votre-domaine.com"
MAIL_FROM_NAME="${APP_NAME}"
```
- `MAIL_MAILER=log` envoie vers `storage/logs/laravel.log` (utile en dév).
- Les alertes e-mail sont **mises en file** (`QUEUE_CONNECTION`) : en production,
  faire tourner un worker (`php artisan queue:work`) ou la planif `schedule:run`.
  Le **bouton de test e-mail** envoie en synchrone et remonte directement les
  erreurs SMTP.
- Les alertes **critiques** sont aussi poussées à l'**E-mail admin** (Réglages ›
  WhatsApp › E-mail admin), même sans abonné explicite.

### 9.4 Dépannage

| Symptôme | Piste |
|----------|-------|
| Aucun envoi | Driver en `log`, ou clé API/URL manquante → le bouton de test le précise |
| « Quota SMS épuisé » | Licence : quota atteint → renouveler / augmenter (cf. §8) |
| E-mail sans erreur mais non reçu | `MAIL_MAILER=log` (voir logs), ou file non drainée (`queue:work`) |
| Échec WhatsApp | Vérifier numéro (format +224…), driver, clé API, instance — détail dans l'Historique |
