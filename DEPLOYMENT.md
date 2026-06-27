# Déploiement & checklist d'industrialisation — AviSmart ERP

> Guide complet (installation, administration, modules) : [`docs/GUIDE.md`](docs/GUIDE.md)

## 1. Pré-requis serveur

- PHP 8.3+ avec extensions : `pdo_mysql` (ou `pdo_sqlite`), `mbstring`, `gd`, `intl`, `zip`, `curl`, `xml`, `ctype`, `fileinfo`, `tokenizer`, `openssl`
  - `gd` est **obligatoire** : il sert au traitement d'images **et** à la génération des QR de traçabilité (`endroid/qr-code`).
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

- Base : `mysqldump` quotidien, rétention 30 jours.
- Fichiers : `storage/app/public` (logos, photos, documents) inclus dans la sauvegarde.

## 7. Suivi post-déploiement

- Surveiller `storage/logs/laravel.log` (niveau `warning`+).
- Vérifier l'absence d'erreurs 5xx et de requêtes lentes (envisager un APM).
