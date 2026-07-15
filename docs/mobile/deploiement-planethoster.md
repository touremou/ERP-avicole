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

## Aide-mémoire des pièges mutualisé

| Symptôme | Cause probable | Fix |
|---|---|---|
| 500 au premier appel | assets non construits / mauvaise racine | racine = `.../public` ; `php artisan optimize` |
| Photos invisibles | pas de lien `storage` | `php artisan storage:link` ou fallback `/media` automatique |
| App mobile « erreur réseau » au login | CORS non ouvert / mauvaise origine | §5, vérifier le préflight du §6.2 |
| PWA non installable | pas de HTTPS ou SW en cache | activer SSL ; `.htaccess` no-cache sur `sw.js` (§4) |
| Tâches planifiées absentes | cron non configuré | §3.e ; vérifier `storage/logs/laravel.log` |
| Mise à jour app non prise sur les tél. | `sw.js` mis en cache | régler le `.htaccess` (§4) puis relancer l'app |
