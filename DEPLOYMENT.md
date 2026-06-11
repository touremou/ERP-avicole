# Déploiement & checklist d'industrialisation — AviSmart ERP

## 1. Pré-requis serveur

- PHP 8.2+ avec extensions : `pdo_mysql`, `mbstring`, `gd`, `bcmath`, `intl`, `zip`
- MySQL 8 / MariaDB 10.6+ (ou PostgreSQL)
- Composer 2, Node 18+ (build des assets)
- HTTPS (certificat valide) + reverse proxy correctement configuré

## 2. Installation

```bash
git clone <repo> && cd ERP-avicole
cp .env.production.example .env        # puis renseigner les valeurs
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan key:generate               # si APP_KEY vide
php artisan migrate --force
php artisan storage:link               # lien public/storage (le service /media reste un filet de sécurité)
php artisan db:seed --class=SettingsSeeder   # si paramètres non encore créés
```

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
- [ ] Comptes par défaut / de démonstration supprimés

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
