# Runbook — Déploiement & Rollback (ERP-avicole, tri-mode)

> Audit 360° P2-⑩. Modèle produit : **une instance par client**, déployée selon
> ses moyens — VPS cloud, hébergement mutualisé, ou machine on-premise à la ferme.
> **Règle absolue : on ne déploie qu'un commit VERT en CI** (jobs Pest + MySQL 8).

## 0. Invariants (tous modes)

- Source de vérité : GitHub `touremou/ERP-avicole`, prod = **tag de release** (jamais un dossier modifié à la main).
- Dénominateur technique commun (déjà respecté par la config) : cache/queue/session
  `database|file` (aucun Redis/Supervisor requis), **1 seule entrée cron** (`schedule:run`).
- Secrets : `.env` jamais versionné ; `APP_DEBUG=false`, `APP_ENV=production` ;
  `BACKUP_ARCHIVE_PASSWORD` défini ; installeur verrouillé après installation.
- Extensions PHP 8.3 requises : mbstring, xml, curl, zip, intl, gd, bcmath, sodium, pdo_mysql.

## 1. Checklist AVANT tout déploiement

1. CI **verte** sur le commit/tag visé (Actions GitHub).
2. **Backup frais** : `php artisan backup:run` sur la prod (cf. `backup-restore-runbook.md`).
3. Fenêtre annoncée aux utilisateurs si migration lourde (heures creuses : 13h-14h ou après 20h).

## 2. Mode A — VPS cloud / on-premise Linux (déploiement par git)

```bash
cd /var/www/erp-avicole
php artisan down --render="errors::503"        # maintenance (page propre)
php artisan backup:run                          # filet
git fetch --tags && git checkout vX.Y.Z         # le TAG de release
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link                        # idempotent
php artisan up
```

**Vérifications post-déploiement (5 min)** : `/up` renvoie 200 · login OK ·
1 hub s'affiche · `php artisan schedule:list` intact · pas d'erreur nouvelle
dans `storage/logs/laravel.log`.

**Rollback (< 10 min)** :
```bash
php artisan down
git checkout vX.Y.(Z-1)
composer install --no-dev --prefer-dist --no-interaction
# Si la migration incriminée est réversible :
php artisan migrate:rollback --step=N
# Sinon : restaurer le backup pris à l'étape 1 (cf. backup-restore-runbook.md §5)
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## 3. Mode B — Hébergement mutualisé (déploiement par ARTEFACT)

Pas de git/SSH garanti → on téléverse un ZIP autosuffisant (vendor inclus).

**Construire l'artefact (sur le poste dev / la CI)** :
```bash
git checkout vX.Y.Z
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci && npm run build
# Zip du projet SANS : .git, node_modules, tests, storage/app/backups, .env
tar --exclude=.git --exclude=node_modules --exclude=tests \
    --exclude=storage/app/backups --exclude=.env \
    -a -c -f erp-avicole-vX.Y.Z.zip .
```

**Déployer (cPanel)** :
1. Mode maintenance : créer `storage/framework/down` n'est pas suffisant en 11+ —
   utiliser `php artisan down` via le terminal cPanel si dispo, sinon page
   maintenance de l'hébergeur.
2. Sauvegarde DB via l'IHM module Sauvegardes (« Sauvegarder maintenant ») ou phpMyAdmin export.
3. Téléverser + extraire le ZIP dans le dossier de l'app (ÉCRASE le code, PAS `.env` ni `storage/`).
4. Terminal cPanel : `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan up`.
5. Vérifications §2.

**Spécificités mutualisé** : cron cPanel = `php /home/USER/app/artisan schedule:run`
(granularité 1 min si possible, sinon 5 min — les heures des tâches restent respectées) ;
`DB_DUMP_BINARY_PATH` si mysqldump hors PATH ; offsite backups via cron FTP/rclone
vers un stockage EXTERNE au compte.

**Rollback** : re-extraire l'artefact précédent + restore DB du backup pris en 2.

## 4. Mode C — On-premise ferme (Windows ou Linux)

Comme le mode A (git ou artefact selon connectivité), plus :

| Point | Exigence |
|---|---|
| Électricité | **Onduleur obligatoire** sur serveur + box (coupures = risque n°1) |
| Scheduler | Linux : cron ; Windows : Planificateur de tâches → `php artisan schedule:run` chaque minute |
| Accès support | VPN léger (Tailscale/WireGuard) pour maintenance à distance |
| Backups | `BACKUP_DISKS=backups,backups_offsite` → USB/NAS local + `rclone` vers le cloud dès connectivité (script avec re-tentatives) |
| Licence | Vérification en ligne quotidienne (`license:sync` 04:00) — période de grâce hors-ligne selon contrat |
| MySQL | 8.x, `innodb_flush_log_at_trx_commit=1` (défaut) conservé : crash-safe sur coupure |

## 5. Supervision post-déploiement (P2-⑪)

- **Alertes erreurs 500 → WhatsApp admin** : actives par défaut hors local
  (`ErrorAlertService`, throttle 5 min/erreur). Prérequis : `whatsapp.admin_phone`
  renseigné dans Réglages, et/ou utilisateurs admin avec `whatsapp_phone`.
  Désactivable par `ERROR_ALERTS_ENABLED=false`.
- **Santé backups** : IHM module Sauvegardes (âge < 24 h) + `backup:monitor`.
- **Uptime externe** (VPS/mutualisé) : pinger `/up` chaque minute (UptimeRobot ou équivalent) → alerte SMS/mail.
- **Disque** : garder ≥ 20 % libres (les backups remplissent) — la rétention
  spatie nettoie à 01:30, vérifier après le 1er mois.

## 6. Journal des déploiements

| Date | Instance/Client | Tag | Mode | Migrations | Durée | Rollback ? | Opérateur |
|---|---|---|---|---|---|---|---|
| — | — | — | — | — | — | — | — |
