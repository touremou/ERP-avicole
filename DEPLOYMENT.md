# Déploiement & checklist d'industrialisation — AviSmart ERP

> Guide complet (installation, administration, modules) : [`docs/GUIDE.md`](docs/GUIDE.md)
>
> **Pressé ?** Guides **pas-à-pas par environnement** — localhost, VPS,
> hébergement mutualisé (PlanetHoster) — web **et** mobile : voir **§11**.

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

---

## 10. Application mobile terrain (PWA « AviTerrain »)

La PWA compagnon (`mobile/`) se déploie sur un sous-domaine `app.*` comme un
**build statique** qui consomme l'API v1 déjà en place. Deux topologies :

- **Reverse-proxy** (recommandé pilote) : le vhost `app.*` sert les fichiers
  ET relaie `/api` vers Laravel → base API relative, **aucun CORS**.
- **Cross-origine** : PWA et API sur des hôtes distincts → build avec
  `VITE_API_BASE_URL` + `CORS_ALLOWED_ORIGINS` côté Laravel (`config/cors.php`).

Guide pas-à-pas (build, vhosts nginx, CORS, HTTPS, compte pilote, vérifs) :
**`docs/mobile/deploiement-staging.md`**.

Sonde de connectivité (publique) : `GET /api/v1/health` → `{"status":"ok",…}`.

> ⚠️ **HTTPS obligatoire** : sans lui, le service worker, l'installation PWA,
> la caméra (photo/scan) et la géolocalisation sont désactivés par le
> navigateur. Provisionner le certificat avant le test pilote.

---

## 11. Guides pas-à-pas par environnement (web + mobile)

Trois parcours complets, du clone au « 100 % fonctionnel » : **A. Localhost**
(développement/démo), **B. VPS** (production recommandée), **C. Hébergement
mutualisé** type PlanetHoster. Chaque parcours couvre l'application **web**
(Laravel) puis la **PWA mobile** (`mobile/`), et se termine par la checklist
§11.4 commune.

Rappel d'architecture : la PWA est un **build statique** qui consomme l'API
v1 ; elle se déploie soit en **même origine** (un vhost sert la PWA et relaie
`/api` → zéro CORS), soit en **cross-origine** (PWA sur `app.*`, API sur le
domaine principal → `VITE_API_BASE_URL` au build + `CORS_ALLOWED_ORIGINS`
côté Laravel). Détails : `docs/mobile/deploiement-staging.md`.

---

### 11.A — Localhost (développement / démonstration)

#### Prérequis
| Outil | Version | Vérifier |
|---|---|---|
| PHP + extensions §1 | **8.3+** | `php -v` puis `php -m \| grep -E 'gd\|sodium\|intl'` |
| Composer | 2.x | `composer -V` |
| Node.js | 18+ (20 recommandé) | `node -v` |
| Git | — | `git --version` |

Pas de MySQL requis : **SQLite** suffit en local (`DB_CONNECTION=sqlite` est
déjà le défaut de `.env.example`).

#### Application web

```bash
git clone <repo> && cd ERP-avicole
cp .env.example .env
composer install
npm ci && npm run build          # assets du back-office (Vite)
touch database/database.sqlite   # la base SQLite locale
php artisan key:generate
php artisan storage:link
php artisan serve                # → http://127.0.0.1:8000
```

Ouvrir `http://127.0.0.1:8000` : l'**assistant `/install`** se lance seul
(prérequis → base → migrations + seeds → compte admin). À la fin il pose le
marqueur `storage/installed` et verrouille `/install`.

Dans **deux autres terminaux** (l'équivalent local du cron et du worker) :

```bash
php artisan schedule:work        # tâches planifiées (alertes, résumés…)
php artisan queue:work           # file d'attente (e-mails de notification)
```

> Alternative démo sans worker : `QUEUE_CONNECTION=sync` dans `.env`
> (tout devient synchrone — suffisant pour tester).

#### PWA mobile

```bash
cd mobile
npm ci
npm run dev                      # → http://127.0.0.1:5173 (proxy /api → :8000)
```

Le serveur de dev proxifie `/api` vers Laravel : **aucune config**. Se
connecter avec le compte créé par l'assistant ; le premier `sync/pull`
rapatrie lots/clients/produits, puis l'app fonctionne hors-ligne (couper le
réseau dans DevTools → onglet Network → « Offline » pour tester).

> **Test sur un vrai téléphone en local** : servir avec
> `npm run dev -- --host` et ouvrir `http://IP-du-PC:5173` depuis le
> téléphone (même Wi-Fi). Limite : hors `localhost`, le navigateur exige
> HTTPS pour service worker, caméra et installation PWA — la saisie et la
> sync fonctionnent, mais photo/scan/installation seront inactifs. Pour un
> test complet sur téléphone : Android + `adb reverse tcp:5173 tcp:5173`
> (le téléphone voit alors l'app comme `localhost`), ou passer au parcours
> B/C avec un vrai certificat.

---

### 11.B — VPS (production recommandée)

Exemple : Ubuntu 22.04/24.04, domaine `ferme.example.com` (web) et
`app.ferme.example.com` (PWA). Adapter les chemins/domaines.

#### Prérequis (installation une fois)

```bash
sudo apt update
sudo apt install -y nginx mysql-server supervisor certbot python3-certbot-nginx git unzip
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-gd php8.3-intl \
                    php8.3-zip php8.3-curl php8.3-xml php8.3-bcmath
# sodium est inclus dans php8.3-common ; vérifier : php -m | grep sodium
# Composer 2 : https://getcomposer.org/download/
# Node 20 (build des assets uniquement) : https://github.com/nodesource/distributions
```

Base de données (l'assistant sait la créer si l'utilisateur MySQL a le droit
`CREATE`, mais la créer soi-même est plus propre) :

```sql
CREATE DATABASE avismart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'avismart'@'localhost' IDENTIFIED BY 'MOT-DE-PASSE-FORT';
GRANT ALL PRIVILEGES ON avismart.* TO 'avismart'@'localhost';
FLUSH PRIVILEGES;
```

#### Application web

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <repo> ERP-avicole && cd ERP-avicole
sudo chown -R $USER:www-data .
cp .env.production.example .env          # APP_URL=https://ferme.example.com
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate
php artisan storage:link
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx  storage bootstrap/cache
```

Vhost nginx (`/etc/nginx/sites-available/ferme.example.com`) :

```nginx
server {
    listen 80;
    server_name ferme.example.com;
    root /var/www/ERP-avicole/public;
    index index.php;
    client_max_body_size 20M;            # photos terrain (5 Mo) + marge

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/ferme.example.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ferme.example.com      # HTTPS (obligatoire, cf. §1)
```

Ouvrir `https://ferme.example.com` → **assistant `/install`** (base : hôte
`127.0.0.1`, base `avismart`, utilisateur `avismart`). Puis :

```bash
php artisan optimize            # config/route/view caches (§3)
```

**Cron** (§5) — `crontab -e` de l'utilisateur du projet :

```cron
* * * * * cd /var/www/ERP-avicole && php artisan schedule:run >> /dev/null 2>&1
```

**Worker de file** — `/etc/supervisor/conf.d/avismart-worker.conf` :

```ini
[program:avismart-worker]
command=php /var/www/ERP-avicole/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/var/www/ERP-avicole/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status avismart-worker      # → RUNNING
```

#### PWA mobile (même origine — recommandé)

```bash
cd /var/www/ERP-avicole/mobile
./scripts/build-staging.sh                 # VITE_API_BASE_URL vide → /api relatif
sudo mkdir -p /var/www/aviterrain
sudo tar -xzf aviterrain-pwa.tar.gz -C /var/www/aviterrain
```

Vhost `app.ferme.example.com` : la PWA en statique, `/api` et `/storage`
relayés **en loopback vers le vhost principal** (monde php-fpm — pas de
`:8000` ici) :

```nginx
server {
    listen 80;
    server_name app.ferme.example.com;
    root /var/www/aviterrain;
    index index.html;

    location = /sw.js  { add_header Cache-Control "no-cache, no-store, must-revalidate"; try_files $uri =404; }
    location /assets/  { add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }

    location /api/     { proxy_pass http://127.0.0.1; proxy_set_header Host ferme.example.com; proxy_set_header X-Forwarded-Proto https; }
    location /storage/ { proxy_pass http://127.0.0.1; proxy_set_header Host ferme.example.com; }

    location / { try_files $uri $uri/ /index.html; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/app.ferme.example.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.ferme.example.com
```

Même origine → **pas de CORS à configurer**. (Variante cross-origine : build
avec `VITE_API_BASE_URL=https://ferme.example.com/api/v1` + `CORS_ALLOWED_ORIGINS`
dans le `.env` Laravel — cf. `docs/mobile/deploiement-staging.md`.)

---

### 11.C — Hébergement mutualisé (PlanetHoster et similaires)

> 🚀 **Déploiement pilote pas-à-pas** (offre *The World*, web + PWA en ~1 h) :
> `docs/mobile/deploiement-planethoster.md` — parcours linéaire prêt à
> suivre le jour J, qui renvoie à cette section pour les détails.
>
> 🧭 **Retour d'expérience** (pièges réels rencontrés sur le premier pilote :
> racine de domaine, marqueur `installed` livré par erreur, erreur MySQL 1067
> `NO_ZERO_DATE`, port SSH 5022, `.well-known` effacé par `rsync --delete`,
> PowerShell) : voir la **§11 du même document**. À lire avant tout nouveau
> déploiement mutualisé.

Contraintes du mutualisé : pas de root ni de démon (supervisor/systemd),
Apache/LiteSpeed (`.htaccess` au lieu de nginx), Node souvent absent ou
limité → **les assets se construisent sur votre poste** et on téléverse le
résultat. Tout le reste (PHP 8.3, MySQL, SSH, cron, sous-domaines, SSL
Let's Encrypt) est disponible dans le panneau (N0C chez PlanetHoster).

#### Prérequis
- Un plan avec **PHP 8.3** (sélecteur de version du panneau) et les
  extensions §1 activées (chez PlanetHoster : `gd`, `intl`, `sodium`… se
  cochent dans « PHP » → extensions).
- **Accès SSH** activé (panneau → SSH) — fortement recommandé ; sans SSH,
  tout se fait par le gestionnaire de fichiers mais `composer`/`artisan`
  deviennent pénibles.
- Une **base MySQL + utilisateur** créés depuis le panneau (noter hôte —
  souvent `localhost` —, nom, utilisateur, mot de passe).
- Sur votre **poste local** : PHP 8.3, Composer, Node 20 (pour préparer
  l'archive).

#### Application web

**1. Préparer l'archive sur votre poste** (on embarque `vendor/` et les
assets construits pour ne rien compiler sur le mutualisé) :

```bash
git clone <repo> && cd ERP-avicole
composer install --no-dev --optimize-autoloader
npm ci && npm run build
tar -czf avismart.tar.gz --exclude=node_modules --exclude=.git --exclude=mobile/node_modules .
```

**2. Téléverser & décompresser** (SSH ou gestionnaire de fichiers) — **hors
racine web** de préférence :

```bash
ssh utilisateur@votre-serveur.planethoster.net
mkdir -p ~/apps/avismart && cd ~/apps/avismart
# téléverser avismart.tar.gz ici (scp/SFTP) puis :
tar -xzf avismart.tar.gz && rm avismart.tar.gz
cp .env.production.example .env
php artisan key:generate
php artisan storage:link
```

**3. Pointer le domaine sur `public/`** : dans le panneau (Domaines), régler
le **dossier racine** du domaine sur `apps/avismart/public`. C'est l'option
propre — le code et le `.env` restent inaccessibles du web.

> Si votre offre ne permet pas de changer le dossier racine : déplacer le
> CONTENU de `public/` dans `public_html/`, puis dans `public_html/index.php`
> corriger les deux `require` vers `../apps/avismart/vendor/autoload.php` et
> `../apps/avismart/bootstrap/app.php`. Le lien `storage` doit alors être
> recréé : `ln -s ~/apps/avismart/storage/app/public ~/public_html/storage`
> (à défaut, le fallback intégré `/media/{chemin}` sert les fichiers sans lien).

**4. Activer HTTPS** : panneau → SSL/Let's Encrypt sur le domaine (généralement
automatique chez PlanetHoster).

**5. Installer** : ouvrir `https://votre-domaine.tld` → assistant `/install`,
renseigner la base MySQL créée à l'étape prérequis. Puis en SSH :

```bash
php artisan optimize
```

**6. Cron** (panneau → Tâches cron) — deux entrées, **chaque minute** :

```cron
* * * * * cd ~/apps/avismart && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/apps/avismart && flock -n storage/framework/queue.lock php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

La 2ᵉ ligne remplace le démon supervisor impossible en mutualisé : elle
draine la file (`database`) puis s'arrête ; `flock` empêche deux exécutions
simultanées. Alternative minimaliste : `QUEUE_CONNECTION=sync` dans `.env`
(les e-mails partent pendant la requête — acceptable à petite échelle).

**7. Mail** : renseigner le SMTP de l'hébergeur dans `.env`
(`MAIL_MAILER=smtp`, hôte/port/identifiants du panneau E-mail) — cf. §9.3.

#### PWA mobile

Ici la PWA vit sur un **sous-domaine** et l'API sur le domaine principal →
**cross-origine**.

**1. Build sur votre poste** avec l'URL absolue de l'API :

```bash
cd mobile
VITE_API_BASE_URL=https://votre-domaine.tld/api/v1 ./scripts/build-staging.sh
```

**2. Sous-domaine** : panneau → créer `app.votre-domaine.tld` avec pour
racine un dossier dédié (ex. `apps/aviterrain`) + **SSL Let's Encrypt**.

**3. Téléverser** le contenu de `mobile/dist/` dans ce dossier, et y créer ce
`.htaccess` (Apache/LiteSpeed) :

```apache
AddType application/manifest+json .webmanifest

# Assets fingerprintés : cache long
<FilesMatch "\.(js|css|png|svg|woff2)$">
  Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

# …sauf le service worker, JAMAIS mis en cache (sinon les mises à jour
# de l'app n'atteignent plus les téléphones). Déclaré APRÈS pour primer.
<Files "sw.js">
  Header set Cache-Control "no-cache, no-store, must-revalidate"
</Files>

# SPA fallback
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /index.html [L]
</IfModule>
```

**4. Ouvrir CORS** côté Laravel — dans le `.env` du domaine principal :

```dotenv
CORS_ALLOWED_ORIGINS=https://app.votre-domaine.tld
```

puis `php artisan config:clear` (ou `optimize`) en SSH.

**5. Vérifier** :

```bash
curl -s https://votre-domaine.tld/api/v1/health          # {"status":"ok",...}
curl -sI -X OPTIONS https://votre-domaine.tld/api/v1/health \
  -H 'Origin: https://app.votre-domaine.tld' \
  -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin
```

Ouvrir `https://app.votre-domaine.tld` sur un téléphone Android/Chrome →
menu **« Installer l'application »**.

---

### 11.4 Checklist « 100 % fonctionnel » (tous environnements)

Cocher dans l'ordre — chaque ligne indique aussi comment vérifier :

| ✔ | Point | Vérification |
|---|---|---|
| ☐ | App web répond en HTTPS | `curl -sI https://…/up` → `200` |
| ☐ | Assistant verrouillé après installation | `/install` redirige vers login ; `storage/installed` présent |
| ☐ | `APP_ENV=production`, `APP_DEBUG=false` | fait automatiquement par l'assistant — contrôler `.env` |
| ☐ | Connexion admin + tuiles modules OK | login → lanceur de modules |
| ☐ | Fichiers/photos servis | téléverser une photo (incident) puis l'ouvrir ; sinon vérifier `storage:link` (fallback `/media` sinon) |
| ☐ | Cron actif | `Réglages → journal` ou `storage/logs/laravel.log` : traces `schedule:run` ; après 15 min les tâches planifiées apparaissent |
| ☐ | File d'attente drainée | envoyer un **test e-mail** (Notifications → Préférences) → reçu ; table `jobs` vide |
| ☐ | Canaux WhatsApp/SMS/e-mail | boutons de test §9 ; historique des notifications |
| ☐ | Licence activée (si monétisation) | §8 — sinon module verrouillé = 402 |
| ☐ | Sauvegardes | §6 — lancer une sauvegarde manuelle depuis l'IHM |
| ☐ | **API mobile** vivante | `curl https://…/api/v1/health` → `{"status":"ok"}` |
| ☐ | PWA installable | Android/Chrome : bannière ou menu « Installer l'application » ; DevTools → Application : SW « activated » |
| ☐ | CORS (si cross-origine) | préflight `OPTIONS` ci-dessus renvoie `Access-Control-Allow-Origin` |
| ☐ | **Balle traçante terrain** | login PWA → mode avion → saisir un pointage → réseau → badge « Synchronisé » → le pointage apparaît dans le web |
| ☐ | Révocation d'appareil | « Mon espace » → appareils → révoquer un token de test |

> Mise à jour d'une instance (tous environnements) : §2 « Mise à jour » —
> `git pull` (ou téléverser la nouvelle archive), `composer install --no-dev`,
> rebuild des assets, `php artisan migrate --force`, `php artisan optimize`.
> Pour la PWA : re-builder `mobile/` et re-téléverser `dist/` — le service
> worker se met à jour seul au prochain lancement.
