# Guide d'installation complet — AviSmart ERP

Ce guide couvre **toute la chaîne**, de zéro à une instance commercialisée :
installation (locale ou en ligne, serveur dédié ou mutualisé), puis mise en
place de la monétisation (génération de licence → activation → renouvellement →
révocation).

- Déploiement & exploitation détaillés : [`../DEPLOYMENT.md`](../DEPLOYMENT.md)
- Guide fonctionnel (modules, administration) : [`GUIDE.md`](GUIDE.md)
- Serveur de licence fournisseur : [`../license-server/README.md`](../license-server/README.md)

---

## 0. Architecture en deux blocs

| Bloc | Où ? | Rôle |
|------|------|------|
| **ERP client** | Chez le client (ou hébergé par vous) | L'application métier. Embarque la **clé publique** de licence. |
| **Serveur de licence** (`license-server/`) | **Chez vous (fournisseur) uniquement** | Détient la **clé privée**, émet/révoque les codes, expose `/check`. |

> Règle d'or : la **clé privée** ne quitte jamais votre infrastructure. Le
> client ne reçoit qu'un **code de validité** (jeton signé) et la **clé
> publique** dans son `.env`.

---

## 1. Prérequis

- **PHP 8.3+** avec : `pdo_mysql` (ou `pdo_sqlite`), `mbstring`, `gd`, `intl`,
  `zip`, `curl`, `xml`, `ctype`, `fileinfo`, `tokenizer`, `openssl`, **`sodium`**.
- **Composer 2**, **Node 18+** (build des assets).
- **MySQL 8 / MariaDB 10.6+** (ou SQLite pour une petite installation).
- En ligne : **HTTPS** (certificat valide) + reverse proxy correct.

Vérifier rapidement :
```bash
php -v
php -m | grep -iE 'sodium|gd|intl|zip|pdo'
composer --version && node -v
```

---

## 2. Installation EN LOCAL (développement / test) — from scratch

```bash
# 1. Récupérer le code
git clone <repo> erp-avicole && cd erp-avicole

# 2. Dépendances
composer install
npm ci && npm run build

# 3. Environnement
cp .env.example .env
php artisan key:generate

# 4. Base de données (SQLite, le plus simple en local)
#    Dans .env : DB_CONNECTION=sqlite et DB_DATABASE=<absolu>/database/database.sqlite
touch database/database.sqlite
php artisan migrate --seed

# 5. Lancer
php artisan serve
# → http://127.0.0.1:8000
```

Au premier accès, si aucun compte n'existe, l'**assistant `/install`** prend le
relais (voir §4). Sinon, connectez-vous avec le compte seedé puis changez le
mot de passe.

---

## 3. Installation EN LIGNE

### 3.A Serveur DÉDIÉ / VPS (recommandé — contrôle total)

```bash
# Sur le serveur (Ubuntu/Debian) : PHP 8.3, MySQL, Nginx, Composer, Node installés.
cd /var/www
git clone <repo> erp-avicole && cd erp-avicole

cp .env.production.example .env       # éditer APP_URL, DB_*, mail, WhatsApp…
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan storage:link
```

Pointer le **document root Nginx sur `public/`** :
```nginx
server {
    server_name erp.client.com;
    root /var/www/erp-avicole/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```
Activer HTTPS (Let's Encrypt : `certbot --nginx`). Puis ouvrir
`https://erp.client.com` → l'assistant `/install` (§4) finalise.

Droits d'écriture :
```bash
chown -R www-data:www-data storage bootstrap/cache
```

Tâches planifiées (cron) — **indispensable** (sauvegardes, alertes, `license:sync`) :
```cron
* * * * * cd /var/www/erp-avicole && php artisan schedule:run >> /dev/null 2>&1
```

### 3.B Hébergement MUTUALISÉ (cPanel — contraintes)

1. Uploader le projet (hors `node_modules`), ou cloner via le terminal cPanel.
2. Buildez les assets **en local** (`npm run build`) et uploadez `public/build/`
   (Node est rarement disponible en mutualisé).
3. `composer install --no-dev --optimize-autoloader` (terminal cPanel) ou
   uploadez `vendor/`.
4. **Document root** : faites pointer le domaine sur `public/`. Si impossible,
   déplacez le contenu de `public/` à la racine web et ajustez les chemins dans
   `index.php` (`require __DIR__.'/../...'`).
5. Base MySQL : créez-la depuis cPanel, renseignez `DB_*` dans `.env`.
6. Cron : ajoutez la tâche `schedule:run` ci-dessus via « Cron Jobs ».
7. Ouvrez le site → assistant `/install`.

> En mutualisé, un **encodeur** (ionCube) est souvent déjà disponible côté
> serveur ; l'assistant `/install` détecte le loader.

---

## 4. Assistant d'installation `/install`

Au premier accès sans compte en base, l'assistant guide :
1. **Prérequis** — vérifie PHP, extensions (dont `sodium`), dossiers en écriture.
2. **Base de données** — teste la connexion, écrit `DB_*` + `APP_KEY`, crée la
   base MySQL si absente.
3. **Migrations + seed** de référence (espèces, normes, modules…).
4. **Compte administrateur** — remplace `admin@admin.com`.
5. **Finalisation** — pose `storage/installed`, bascule `.env` en
   `APP_ENV=production` / `APP_DEBUG=false`, et `/install` devient inaccessible.

---

## 5. Monétisation : de la clé à l'activation

### 5.1 (Fournisseur, une fois) Générer la paire de clés

```bash
cd license-server
php bin/license keygen
# → écrit storage/private.key (SECRET, ne jamais livrer)
# → affiche : LICENSE_PUBLIC_KEY=xxxxx
```

### 5.2 (Client) Poser la clé publique

Dans le `.env` de **chaque** instance ERP livrée :
```dotenv
LICENSE_PUBLIC_KEY=xxxxx        # la clé publique affichée ci-dessus
LICENSE_ENFORCE=true            # active le blocage à l'expiration
LICENSE_GRACE_DAYS=7            # jours de grâce après échéance
```
Sans `LICENSE_PUBLIC_KEY`, l'ERP fonctionne **sans restriction** (mode ouvert) —
pratique pour une démo.

### 5.3 (Fournisseur) Émettre un code à la vente

```bash
cd license-server
php bin/license issue --id=BIOCREST --client="BioCrest" --plan=pro --days=366
#   --plan : basic | pro | entreprise
#   --sms=1000        (surcharge le quota du plan)
#   --domain=erp.client.com  (lie la licence à ce domaine — anti-copie)
# → affiche le CODE DE VALIDITÉ
```
Transmettez au client **deux choses** : son **identifiant** (`BIOCREST`) et le
**code de validité**.

> Dépannage sans serveur : l'ERP a aussi `php artisan license:issue
> --private-key=...` (nécessite la clé privée). Le serveur dédié reste préférable
> (persistance + révocation).

### 5.4 (Client) Activer la licence

Dans l'ERP : **Tableau de bord → Licence** (écran « Prolongez la date de
validité »). Saisir l'**identifiant** + coller le **code de validité** →
**Modifier**. La vérification est **hors-ligne** (signature, aucun réseau requis).
La carte « Durée de validité » du tableau de bord affiche jours restants,
échéance et SMS restant.

### 5.5 (Fournisseur) Renouveler

```bash
cd license-server
php bin/license renew --id=BIOCREST --days=366
# → nouveau code à transmettre ; le client le ré-applique (§5.4)
```
Avec la vérification en ligne activée (§5.7), le renouvellement se propage seul.

### 5.6 (Fournisseur) Révoquer (impayé, litige)

```bash
php bin/license revoke --id=BIOCREST
```
La révocation prend effet à la prochaine synchro en ligne du client (§5.7).
Sans serveur en ligne, le blocage interviendra naturellement à l'expiration du
jeton hors-ligne.

### 5.7 (Optionnel) Vérification en ligne hybride

Permet la **révocation / le renouvellement à distance**. Côté client `.env` :
```dotenv
LICENSE_SERVER_URL=https://licences.votre-domaine.com/check
LICENSE_CHECK_INTERVAL_HOURS=24
```
Côté fournisseur, lancer le service :
```bash
cd license-server
php -S 0.0.0.0:8989 -t public      # ou derrière Nginx + HTTPS en production
```
La commande `license:sync` (cron quotidien, déjà planifiée) interroge ce serveur.
En cas de coupure réseau, l'ERP reste fonctionnel (le jeton hors-ligne prime).

Tableau de bord du registre : ouvrir `http://<serveur>:8989/` → liste des
licences émises et leur état (active / expirée / révoquée).

---

## 6. Protection du code livré

- **Palier gratuit (par défaut)** : la licence signée suffit comme barrière
  commerciale. Durcissez la copie de release :
  ```bash
  scripts/package-release.sh /chemin/vers/release-client
  ```
  (copie propre + `composer --no-dev` + build + `php artisan release:strip` qui
  retire commentaires et mise en forme du PHP).
- **Palier encodeur (plus tard)** : ionCube (~199-399 $/an) ou SourceGuardian
  (~199 $ + MAJ). Loader client gratuit, détecté par `/install`.

Détails : [`../DEPLOYMENT.md`](../DEPLOYMENT.md) §8.3.

---

## 7. Mises à jour d'une instance existante

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force      # migrations idempotentes
php artisan optimize:clear && php artisan optimize
```

---

## 8. Sauvegarde & restauration

- Sauvegarde : `php artisan backup:run` (planifiée quotidiennement à 02:00).
- Restauration : décompresser l'archive, réimporter le dump SQL, restaurer
  `storage/app/public`. Voir `../DEPLOYMENT.md` §6.

---

## 9. Dépannage rapide

| Symptôme | Piste |
|----------|-------|
| « Abonnement expiré » à tort | Vérifier l'horloge serveur ; `LICENSE_PUBLIC_KEY` correct ; `php artisan license:sync --force`. |
| Activation refusée : « identifiant ne correspond pas » | L'`--id` de l'émission doit être saisi à l'identique. |
| « Signature invalide » | La clé publique du client ne correspond pas à la clé privée d'émission. |
| Module masqué/inaccessible | Le plan ne l'inclut pas → réémettre avec le bon `--plan`/`--modules`. |
| Quota SMS épuisé | Réémettre avec `--sms=` plus élevé (le compteur repart à l'activation). |
| Images/QR absents | Extension `gd` manquante ; `php artisan storage:link`. |
| 419 / sessions | `APP_KEY` absente (`php artisan key:generate`) ; HTTPS/cookies. |
