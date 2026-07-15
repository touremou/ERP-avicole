# Déploiement pilote — PlanetHoster « The World » (mutualisé)

> **But** : mettre la version pilote (web **+** PWA terrain) en ligne sur
> l'offre mutualisée *The World* de PlanetHoster pour un test réel, en
> ~1 h. Ce document est un **parcours linéaire** ; pour le détail de chaque
> point, voir `DEPLOYMENT.md §11.C` (mutualisé) et `§11.4` (checklist).

## 0. Topologie retenue pour le pilote

Sur du mutualisé on ne peut pas faire de reverse-proxy : on héberge donc
**deux (sous-)domaines** et on ouvre CORS.

| | Adresse | Racine web | SSL |
|---|---|---|---|
| **Web (ERP + API)** | `https://votre-domaine.tld` | `.../public` de l'app | Let's Encrypt |
| **PWA terrain** | `https://app.votre-domaine.tld` | dossier `dist/` | Let's Encrypt |

L'app mobile appelle `https://votre-domaine.tld/api/v1` → **CORS requis**
(étape 6). L'auth mobile est par **token Bearer** (pas de cookie) : pas de
CSRF cross-site à gérer.

> ⚠️ **HTTPS obligatoire** sur les deux : sans lui, ni service worker, ni
> installation PWA, ni caméra (scan QR / photo). Let's Encrypt est fourni
> dans le panneau N0C — l'activer AVANT le test.

---

## 1. Prérequis (panneau N0C de PlanetHoster)

1. **PHP 8.3** : *N0C → votre hébergement → PHP* → sélectionner 8.3, cocher
   les extensions `gd`, `intl`, `mbstring`, `sodium`, `bcmath`, `pdo_mysql`,
   `zip`, `curl`, `fileinfo`.
2. **Base MySQL** : *N0C → Bases de données MySQL* → créer une base + un
   utilisateur, lui donner tous les droits. Noter **hôte** (souvent
   `localhost`), **nom**, **utilisateur**, **mot de passe**.
   > ⚠️ **Créez la base AVANT de lancer `/install`.** Sur mutualisé,
   > l'utilisateur MySQL est limité à sa base et n'a pas le privilège global
   > `CREATE` : le `CREATE DATABASE IF NOT EXISTS` tenté par l'assistant peut
   > alors renvoyer *« Access denied »* même si la base existe déjà. En créant
   > la base au préalable, l'assistant se contente de s'y connecter. (Voir le
   > REX §11.4 pour la voie CLI de secours.)
3. **SSH** : *N0C → Accès SSH* → activer (fortement recommandé — sans SSH,
   `composer`/`artisan` deviennent pénibles via le gestionnaire de fichiers).
4. **Sur votre poste** : PHP 8.3, Composer, Node 20 (pour préparer les
   archives — on ne compile RIEN sur le serveur).

---

## 2. Préparer les deux archives sur votre poste

```bash
git clone <repo> && cd ERP-avicole
git checkout claude/funny-maxwell-0h3i5h      # la branche pilote

# ── (a) Application web : vendor + assets construits embarqués ──
composer install --no-dev --optimize-autoloader
npm ci && npm run build
tar -czf avismart-web.tar.gz \
  --exclude=node_modules --exclude=.git --exclude=mobile .

# ── (b) PWA terrain : build pointé sur l'API du domaine principal ──
cd mobile
VITE_API_BASE_URL=https://votre-domaine.tld/api/v1 ./scripts/build-staging.sh
#   → produit mobile/dist/ (+ aviterrain-pwa.tar.gz)
cd ..
```

> Le build PWA **échoue si le TypeScript ne compile pas** (tsc en amont) :
> un build vert = un bundle sain.

> ⚠️ **Windows / PowerShell** : la syntaxe `VITE_API_BASE_URL=... ./script.sh`
> (variable en préfixe + `\` de continuation de ligne) est du **bash** et
> échoue sous PowerShell. Faire à la place, en deux temps :
> ```powershell
> $env:VITE_API_BASE_URL = "https://votre-domaine.tld/api/v1"
> cd mobile ; npm ci ; npm run build
> ```
> Plus simple encore : **ne rien construire en local** et laisser la CI/CD
> (§10) faire tous les builds — c'est le mode recommandé après la première
> mise en ligne.

---

## 3. Déployer l'application web

**a.** Téléverser `avismart-web.tar.gz` **hors racine web** (SFTP ou SSH),
p. ex. dans `~/apps/avismart`, puis :

```bash
ssh utilisateur@votre-serveur.planethoster.net
mkdir -p ~/apps/avismart && cd ~/apps/avismart
tar -xzf ~/avismart-web.tar.gz && rm ~/avismart-web.tar.gz
cp .env.production.example .env
php artisan key:generate
php artisan storage:link
```

**b. Pointer le domaine sur `public/`** : *N0C → Domaines* → régler le
**dossier racine** de `votre-domaine.tld` sur `apps/avismart/public`. Le
code et le `.env` restent ainsi hors du web.

> Si l'offre ne laisse pas changer la racine : déplacer le **contenu** de
> `public/` dans `public_html/` et corriger les deux `require` de
> `public_html/index.php` vers `../apps/avismart/vendor/autoload.php` et
> `.../bootstrap/app.php` (détail dans `DEPLOYMENT.md §11.C`). Le fallback
> intégré `/media/{chemin}` sert les photos même sans lien `storage`.

**c. HTTPS** : *N0C → SSL* → Let's Encrypt sur `votre-domaine.tld`.

**d. Installer** : ouvrir `https://votre-domaine.tld` → l'assistant
**`/install`** (5 étapes) : prérequis → base MySQL (celle du §1.2) →
migrations + seed → **compte administrateur** → verrouillage. Puis :

```bash
cd ~/apps/avismart && php artisan optimize
```

**e. Cron** (*N0C → Tâches Cron*, **chaque minute**) :

```cron
* * * * * cd ~/apps/avismart && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/apps/avismart && flock -n storage/framework/queue.lock php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

La 2ᵉ ligne remplace le démon (impossible en mutualisé) : elle draine la
file puis s'arrête. Alternative minimale : `QUEUE_CONNECTION=sync` dans
`.env` (les envois partent pendant la requête — acceptable pour un pilote).

**f. Notifications** (optionnel pour tester les alertes) : renseigner dans
`.env` le SMTP du panneau E-mail, et éventuellement WhatsApp/SMS (cf. §9).

---

## 4. Déployer la PWA terrain

**a. Sous-domaine** : *N0C → Domaines/Sous-domaines* → créer
`app.votre-domaine.tld`, racine `apps/aviterrain` (dossier dédié) +
**SSL Let's Encrypt**.

**b. Téléverser** le contenu de `mobile/dist/` dans `apps/aviterrain`, puis
y créer ce **`.htaccess`** (le fichier est aussi décrit au §11.C) :

```apache
AddType application/manifest+json .webmanifest

# Assets fingerprintés (hash dans le nom) : cache long.
<FilesMatch "\.(js|css|png|svg|woff2)$">
  Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
# …SAUF le service worker, JAMAIS mis en cache (sinon les mises à jour de
# l'app n'atteignent plus les téléphones). Déclaré après pour primer.
<Files "sw.js">
  Header set Cache-Control "no-cache, no-store, must-revalidate"
</Files>

# Fallback SPA (l'app route en hash, mais on sert index.html par sécurité).
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /index.html [L]
</IfModule>
```

---

## 5. Ouvrir CORS (indispensable en cross-origine)

Dans le `.env` du **domaine principal** (`~/apps/avismart/.env`) :

```dotenv
CORS_ALLOWED_ORIGINS=https://app.votre-domaine.tld
```

Puis en SSH :

```bash
cd ~/apps/avismart && php artisan config:clear   # ou : php artisan optimize
```

> `config/cors.php` lit cette variable ; l'auth étant par token Bearer,
> `supports_credentials` reste `false`. En production, **lister
> explicitement** l'origine `app.*` (jamais `*`).

---

## 6. Vérifications (2 minutes)

```bash
# 1. Sonde de santé de l'API
curl -s https://votre-domaine.tld/api/v1/health          # {"status":"ok",...}

# 2. Préflight CORS : doit renvoyer l'origine autorisée
curl -sI -X OPTIONS https://votre-domaine.tld/api/v1/health \
  -H 'Origin: https://app.votre-domaine.tld' \
  -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin

# 3. App web en HTTPS
curl -sI https://votre-domaine.tld/up                    # 200
```

Puis, **sur un téléphone Android/Chrome** : ouvrir
`https://app.votre-domaine.tld` → menu **« Installer l'application »** →
vérifier l'icône d'accueil et le lancement plein écran. Dans
DevTools → Application : **Manifest** détecté, **Service Worker**
« activated », **IndexedDB `erp-mobile`** créée après login.

---

## 7. Créer le compte pilote

Depuis l'admin web (Annuaire → utilisateurs) : créer un utilisateur
affecté à la **ferme pilote** avec un rôle terrain (gardien/manager). Il se
connecte sur la PWA, l'app **bootstrap** ses lots/clients/produits au premier
passage réseau, puis fonctionne hors-ligne.

---

## 8. Test d'acceptation « balle traçante » (le seul qui compte)

1. Login PWA sur le téléphone → attendre la première synchro (les tâches du
   jour apparaissent sur l'accueil).
2. **Mode avion** → saisir un pointage (ou une collecte) → confirmation
   instantanée.
3. Rétablir le réseau → le badge passe à **« ✓ Synchronisé »**.
4. Sur le **web**, la saisie apparaît dans le module correspondant.
5. Bonus terrain : scanner l'étiquette QR d'un lot → l'écran d'action s'ouvre.

Si ces 5 points passent, le pilote est opérationnel. Reste à observer sur une
vraie journée : lisibilité au soleil, autonomie batterie, fréquence du repli
« saisie manuelle » du scan, qualité des photos remontées.

---

## 9. Mettre à jour l'instance pilote (itérations)

À chaque nouvelle version poussée sur la branche :

```bash
# Web (poste) : reconstruire l'archive comme au §2(a), la téléverser, puis SSH :
cd ~/apps/avismart
php artisan down
tar -xzf ~/avismart-web.tar.gz         # écrase le code (garde .env + storage)
php artisan migrate --force
php artisan optimize
php artisan up

# PWA (poste) : rebuild §2(b) + re-téléverser dist/ → le service worker se
# met à jour seul au prochain lancement de l'app.
```

> Sauvegardez la base et le `.env` avant chaque mise à jour. Le dossier
> `storage/` (photos, logs, `installed`) ne doit **jamais** être écrasé.

---

## 10. Déploiement continu : push sur GitHub → mise à jour automatique

Le workflow **`.github/workflows/deploy.yml`** met à jour l'instance à chaque
fois que `main` est mis à jour ET que la CI passe. Modèle :

```
Installation initiale = MANUELLE, une seule fois (§1 à §7 ci-dessus).
Ensuite : merge vers main  →  CI verte  →  déploiement automatique.
```

Le build (Composer sans dev + assets web + PWA) se fait **dans GitHub
Actions** — l'hébergement n'a donc besoin ni de Node ni de Composer. Les
fichiers sont poussés par `rsync`/SSH ; `.env`, `storage/` et la base ne
sont **jamais** touchés ; `migrate --force` puis `optimize` s'exécutent à
distance dans une courte fenêtre de maintenance (l'app est toujours remise
en ligne, même si une migration échoue).

### 10.1 — Générer une clé de déploiement (sur votre poste)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/avismart_deploy -N "" -C "github-deploy-avismart"
```

- **Clé publique** (`~/.ssh/avismart_deploy.pub`) → à ajouter sur le serveur :
  *N0C → SSH → clés autorisées*, OU en SSH :
  ```bash
  cat ~/.ssh/avismart_deploy.pub >> ~/.ssh/authorized_keys
  ```
- **Clé privée** (`~/.ssh/avismart_deploy`, tout le contenu, y compris les
  lignes `BEGIN/END`) → à coller dans le secret `PILOT_SSH_KEY` (ci-dessous).

Vérifier qu'elle ouvre bien une session :
```bash
ssh -p <PORT> -i ~/.ssh/avismart_deploy <USER>@<HOTE> "php -v"
```

### 10.2 — Définir les secrets GitHub

*GitHub → repo → Settings → Secrets and variables → Actions → New repository secret* :

| Secret | Valeur |
|---|---|
| `PILOT_SSH_HOST` | hôte SSH (ex. `node42.n0c.com`) |
| `PILOT_SSH_PORT` | port SSH (souvent **5022** chez PlanetHoster) |
| `PILOT_SSH_USER` | utilisateur SSH du compte |
| `PILOT_SSH_KEY` | **clé privée** générée en 10.1 (contenu intégral) |
| `PILOT_WEB_PATH` | chemin de l'app web, ex. `/home/USER/apps/avismart` |
| `PILOT_PWA_PATH` | chemin du sous-domaine app.*, ex. `/home/USER/apps/aviterrain` |
| `PILOT_API_BASE_URL` | `https://votre-domaine.tld/api/v1` |

> Le workflow se contente de **mettre à jour** une instance déjà installée :
> faites l'installation initiale (§1–§7) une fois à la main. Les dossiers
> `PILOT_WEB_PATH` (avec son `.env`) et `PILOT_PWA_PATH` doivent donc exister.

### 10.3 — Déclencher

- **Automatique** : mergez votre travail vers `main` (par ex. en **fusionnant
  la Pull Request** de la branche pilote). La CI se lance sur `main` ; si elle
  est verte, le déploiement part dans la foulée.
- **Manuel** : *GitHub → Actions → « Deploy pilot (PlanetHoster) » → Run
  workflow*. Utile pour re-déployer sans nouveau commit.

Suivi : *Actions* montre chaque étape (build, rsync web, rsync PWA, migrate).
En cas d'échec rsync, vérifier que **rsync est installé côté serveur**
(`ssh … "which rsync"`) — sinon demander son activation au support, ou
basculer sur le parcours archive manuel du §9.

### 10.4 — La branche `main` devient le miroir du serveur

À partir de là, `main` = ce qui tourne en pilote. Continuez à développer sur
des branches, ouvrez une PR, et **le merge vers `main` déploie**. Pour un
correctif urgent : commit sur `main` (ou PR + merge) → déploiement.

---

## 11. Retour d'expérience — pièges réels rencontrés (pilote `biocrest.fr`)

> Cette section consigne **les problèmes effectivement rencontrés** lors de la
> première mise en ligne du pilote, avec leur cause et le correctif appliqué.
> À lire **avant** un nouveau déploiement : chacun de ces points nous a coûté
> du temps.

### 11.1 — La racine du domaine ne pointe pas là où l'app est déployée

**Symptôme** : après avoir extrait l'app dans `~/files/erp`, le domaine
affichait un **« Index of / »** ne listant que `.well-known/`, pas l'app.

**Cause** : chez N0C, chaque (sous-)domaine a un **dossier racine** propre,
déjà créé (avec un `.well-known/` pour le SSL). Ici `erp.biocrest.fr` servait
`~/erp` alors que l'app avait été mise dans `~/files/erp` → deux dossiers
différents.

**Diagnostic** — lister les racines réelles (une par domaine) :
```bash
find ~ -maxdepth 5 -type d -name ".well-known"
#  ~/public_html/.well-known        → domaine principal
#  ~/erp/.well-known                → erp.biocrest.fr   (racine réelle !)
#  ~/dist/.well-known               → app.biocrest.fr   (PWA)
```

**Fix retenu** (le plus propre) : *N0C → Domaines → `erp.biocrest.fr` →
Éditer → Dossier racine* = **`files/erp/public`**. L'app reste hors du web,
seul `public/` est exposé. Puis aligner les secrets CI :
`PILOT_WEB_PATH=/home/USER/files/erp`, `PILOT_PWA_PATH=/home/USER/dist`.

> **Règle** : décidez la racine du domaine AVANT d'extraire l'app, et faites
> correspondre `PILOT_WEB_PATH` / `PILOT_PWA_PATH` à ces chemins exacts, sinon
> le déploiement continu poussera dans le vide.

### 11.2 — `/install` renvoie vers `/login` alors que la base est vide

**Symptôme** : base MySQL toute neuve, mais `https://…/install` redirige
aussitôt vers la page de connexion (impossible de lancer l'assistant).

**Cause** : le marqueur **`storage/installed`** avait été **embarqué dans
l'archive** (créé dans l'environnement de build). Le middleware
`EnsureAppIsInstalled` le voit et considère l'app déjà installée.

**Fix** :
```bash
cd <racine app>
rm -f storage/installed
php artisan optimize:clear
# puis rouvrir /install
```

> Prévention : le `storage/` ne devrait jamais être copié depuis un poste de
> build. La CI/CD (§10) l'exclut déjà du rsync ; en manuel, **exclure
> `storage/`** de l'archive ou supprimer `storage/installed` après extraction.

### 11.3 — Erreur SQL 1067 « Invalid default value for 'expires_at' » à la migration

**Symptôme** : l'étape *migration* de l'assistant s'arrête sur :
```
SQLSTATE[42000]: … 1067 Invalid default value for 'expires_at'
(SQL: create table `licenses` … `expires_at` timestamp not null …)
```

**Cause** : le MySQL/MariaDB de N0C tourne avec le mode strict `NO_ZERO_DATE`.
Une colonne `timestamp NOT NULL` **sans valeur par défaut** y reçoit le défaut
implicite `0000-00-00` (rejeté) **dès qu'elle n'est pas la première colonne
`timestamp` de la table** — cas de `licenses.expires_at`, précédée de
`issued_at`/`starts_at` nullable.

**Fix (à la source, déjà dans le dépôt)** : rendre ces colonnes `nullable()`.
Corrigé sur `licenses.expires_at` **et** — par prévention — sur les autres
timestamps d'événement (`releve_at`, `done_at`, `recorded_at`, `collected_at`,
`opened_at`), qui recevaient sinon un `ON UPDATE CURRENT_TIMESTAMP` implicite
écrasant l'heure réelle du relevé à chaque mise à jour de la ligne.

> Si vous ajoutez une migration : **jamais** de `timestamp('x')` nu. Utilisez
> `->nullable()`, `->useCurrent()`, ou un `->default(...)` explicite. La
> vérification tourne aussi en CI (SQLite strict), mais SQLite ne reproduit
> pas ce cas MySQL — testez sur MySQL avant un gros déploiement.

### 11.4 — Voie CLI de secours (si l'assistant coince sur la base)

Base **déjà créée dans le panneau** (cf. §1.2), puis en SSH :
```bash
cd <racine app>
nano .env        # DB_DATABASE / DB_USERNAME / DB_PASSWORD ; DB_HOST=localhost
php artisan config:clear
php artisan migrate:fresh --force --seed     # base pilote vide → repart propre
```
Créer l'admin sans l'assistant :
```bash
php artisan tinker --execute="
\$r = App\Models\Role::firstOrCreate(['name'=>'admin'],['display_name'=>'Administrateur','label'=>'Administrateur','icon'=>'👑','permissions'=>['L','C','M','S']]);
App\Models\User::updateOrCreate(['email'=>'ADMIN_EMAIL'],['name'=>'Admin','password'=>bcrypt('MDP_FORT'),'role_id'=>\$r->id]);
App\Models\User::where('email','user@users.com')->delete();
"
# Basculer en production + poser le marqueur d'installation
sed -i 's/^APP_ENV=.*/APP_ENV=production/'   .env
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/'    .env
touch storage/installed
php artisan optimize
```

### 11.5 — SSH : port 5022, et `scp -P` majuscule

**Symptôme** : `ssh user@hote` → *Connection refused* (port 22).

**Cause** : PlanetHoster N0C écoute le SSH sur le **port 5022**, pas 22.

**Fix** :
```bash
ssh -p 5022 user@node118-eu.n0c.com
scp -P 5022 fichier user@node118-eu.n0c.com:~/     # -P MAJUSCULE pour scp
```
(Le secret CI `PILOT_SSH_PORT` doit donc valoir `5022`.)

### 11.6 — Le déploiement PWA (`rsync --delete`) effaçait `.well-known/`

**Symptôme potentiel** : renouvellement du certificat SSL du sous-domaine
cassé après un déploiement.

**Cause** : `rsync --delete` de `mobile/dist/` vers `~/dist` supprime tout ce
qui n'est pas dans la source — dont le `.well-known/` de validation
Let's Encrypt, absent du build.

**Fix (déjà dans le workflow)** : `--exclude='.well-known/'` sur le rsync PWA.
Ne retirez jamais cette exclusion.

### 11.7 — Fausses alertes bénignes (ne pas paniquer)

- `ls: cannot access 'avismart-web.tar.gz'` **après** une commande
  `tar -xzf … && rm …` : c'est **normal**, l'archive a été supprimée par le
  `&& rm` une fois l'extraction réussie. Vérifiez plutôt le contenu extrait.
- « dossier vide sur le serveur » juste après extraction : vérifiez que vous
  regardez bien la **racine du domaine** (§11.1) et non le dossier d'extraction.

---

## Aide-mémoire des pièges mutualisé

| Symptôme | Cause probable | Fix |
|---|---|---|
| 500 au premier appel | assets non construits / mauvaise racine | racine = `.../public` ; `php artisan optimize` |
| Photos invisibles | pas de lien `storage` | `php artisan storage:link` ou fallback `/media` automatique |
| App mobile « erreur réseau » au login | CORS non ouvert / mauvaise origine | §5, vérifier le préflight du §6.2 |
| PWA non installable | pas de HTTPS ou SW en cache | activer SSL ; `.htaccess` no-cache sur `sw.js` (§4) |
| Tâches planifiées absentes | cron non configuré | §3.e ; vérifier `storage/logs/laravel.log` |
| Mise à jour app non prise sur les tél. | `sw.js` mis en cache | régler le `.htaccess` (§4) puis relancer l'app |
| « Index of / » avec seulement `.well-known/` | racine du domaine ≠ dossier de l'app | §11.1 : régler la racine sur `.../public`, aligner `PILOT_WEB_PATH` |
| `/install` renvoie à `/login`, base vide | marqueur `storage/installed` livré par erreur | §11.2 : `rm -f storage/installed && php artisan optimize:clear` |
| Erreur SQL **1067** *Invalid default value* à la migration | `timestamp NOT NULL` sans défaut + MySQL `NO_ZERO_DATE` | §11.3 : colonne `->nullable()` (corrigé à la source) |
| `/install` : *Access denied* à l'étape base | user MySQL sans privilège `CREATE` global | §1.2 : créer la base dans le panneau AVANT ; sinon voie CLI §11.4 |
| `ssh` *Connection refused* | port SSH 22 au lieu de **5022** | §11.5 : `ssh -p 5022` ; `scp -P 5022` |
| SSL du sous-domaine cassé après déploiement | `rsync --delete` a effacé `.well-known/` | §11.6 : garder `--exclude='.well-known/'` dans le workflow |
| `VITE_…=… ./script.sh` échoue sous Windows | syntaxe bash en PowerShell | §2 : `$env:VITE_API_BASE_URL="…"` puis `npm run build`, ou laisser la CI builder |
