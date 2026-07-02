# Runbook — Sauvegardes & Restauration (ERP-avicole)

> Audit 360° P2-⑨. **Règle d'or : un backup jamais restauré n'existe pas.**
> RPO cible ≤ 24 h · RTO cible ≤ 4 h · Rétention pièces comptables : 10 ans (OHADA).

## 1. Ce qui est sauvegardé, par quoi

- **Moteur** : `spatie/laravel-backup` — commande `backup:run`.
- **Contenu de l'archive ZIP** : dump MySQL complet (`db-dumps/mysql-*.sql`) + fichiers
  utilisateurs (`storage/app/public` : photos, logos, justificatifs). Le code vit dans Git.
- **Planification** (`routes/console.php`) : `backup:clean` à 01:30, `backup:run` à 02:00.
- **Chiffrement** : AES-256 si `BACKUP_ARCHIVE_PASSWORD` est défini (**obligatoire en prod** ;
  conserver ce mot de passe dans le coffre — un backup chiffré sans mot de passe = perdu).
- **Vérification** : `BACKUP_VERIFY=true` (défaut) — l'archive est ré-ouverte après création.
- **Santé** : IHM module Sauvegardes + `php artisan backup:monitor` (âge ≤ 1 jour).

## 2. Configuration par variable d'environnement

| Clé | Rôle | Exemple |
|---|---|---|
| `DB_DUMP_BINARY_PATH` | Dossier de `mysqldump` si hors PATH | `C:\wamp64\bin\mysql\mysql8.4.7\bin` (dev) / vide (Linux) |
| `BACKUP_DISKS` | Destinations CSV | `backups,backups_offsite` |
| `BACKUP_OFFSITE_PATH` | Racine de la copie hors site | `/mnt/nas/erp-backups` |
| `BACKUP_ARCHIVE_PASSWORD` | Chiffrement AES de l'archive | *(coffre)* |
| `BACKUP_VERIFY` | Re-vérifier l'archive | `true` |

## 3. Le PRÉREQUIS absolu : le scheduler doit tourner

`backup:run` est planifié par Laravel, mais Laravel ne se réveille pas seul :

| Mode | Mise en place |
|---|---|
| **VPS / on-premise (Linux)** | `crontab -e` → `* * * * * cd /chemin/erp && php artisan schedule:run >> /dev/null 2>&1` |
| **Mutualisé (cPanel)** | Cron cPanel, chaque minute (ou la granularité minimale offerte), même commande |
| **Windows (dev/on-prem)** | Planificateur de tâches : `php artisan schedule:run` chaque minute |

**Preuve de vie** : `php artisan schedule:list` (les entrées backup y figurent) et, chaque
matin, vérifier l'IHM Sauvegardes (âge < 24 h). Un échec de backup est journalisé
(`storage/logs/laravel.log`).

## 4. Copie HORS SITE par mode de déploiement

Un backup sur la même machine que la base **n'est pas un backup** (coupures, vol,
incendie, crash disque — contexte Guinée : risque réel).

| Mode | Stratégie recommandée |
|---|---|
| **VPS cloud** | `BACKUP_OFFSITE_PATH` → volume secondaire **ou** synchronisation du dossier `storage/app/backups` vers un stockage objet (S3/Backblaze B2) via `rclone` en cron (`rclone sync ... remote:erp-backups`) |
| **Mutualisé** | Cron cPanel qui pousse le dossier backups en FTP/rclone vers un stockage externe (JAMAIS le même compte d'hébergement) |
| **On-premise ferme** | `BACKUP_DISKS=backups,backups_offsite` avec `BACKUP_OFFSITE_PATH` vers un **disque USB/NAS local** + `rclone` vers le cloud dès que la connectivité le permet (script cron avec re-tentatives) |

## 5. Drill de RESTAURATION (à rejouer chaque mois + avant tout go-live)

Objectif : prouver qu'on sait revenir en ≤ 4 h. **Jamais sur la base de production** —
toujours vers une base jetable `erp_restore_drill`.

```bash
# 1. Prendre la dernière archive
ls storage/app/backups/<APP_NAME>/            # ex. 2026-07-02-02-00-04.zip

# 2. Extraire (si chiffrée : mot de passe du coffre)
unzip 2026-07-02-02-00-04.zip -d /tmp/drill   # Windows : Expand-Archive

# 3. Recréer une base jetable et importer le dump
mysql -u root -p -e "DROP DATABASE IF EXISTS erp_restore_drill; CREATE DATABASE erp_restore_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p erp_restore_drill < /tmp/drill/db-dumps/mysql-*.sql

# 4. Contrôles d'intégrité (comparer à la source au moment du backup)
mysql -u root -p erp_restore_drill -e "
  SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='erp_restore_drill') AS tables_restaurees,
         (SELECT COUNT(*) FROM batches)  AS lots,
         (SELECT COUNT(*) FROM sales)    AS ventes,
         (SELECT COUNT(*) FROM expenses) AS depenses;"

# 5. Vérifier les fichiers utilisateurs extraits (photos/justificatifs présents)
# 6. Nettoyer
mysql -u root -p -e "DROP DATABASE erp_restore_drill;"
```

**Restauration RÉELLE (incident)** : mêmes étapes vers la base de production
(`php artisan down` d'abord), puis re-déployer les fichiers de `storage/app/public`
depuis l'archive, `php artisan up`, et contrôler les 4 compteurs ci-dessus + une
connexion utilisateur + une vente de test annulée ensuite.

## 6. Procès-verbaux de drill

| Date | Archive testée | Restaurée sur | Tables | Lots / Ventes / Dépenses | Durée | Opérateur | Verdict |
|---|---|---|---|---|---|---|---|
| 2026-07-02 | `Laravel/2026-07-02-18-20-28.zip` (2,83 MB, intégrité vérifiée à la création) | `erp_restore_drill` (MySQL 8.4.7, WAMP dev) | **118/118** | **4 / 8 / 2** = référence exacte | < 5 min | Claude (audit) + Kaba | ✅ **CONFORME** |

## 7. Drill initial (dev) — journal du 2026-07-02

Chaîne complète prouvée sur la machine de développement :
1. `composer install` (le paquet `spatie/laravel-backup` était déclaré mais
   **absent de vendor/** — cause racine du « backup qui ne tourne pas ») ;
2. `php artisan backup:run` → dump `erp_avicole` + 30 fichiers utilisateurs,
   zip 2,83 MB, **vérification d'intégrité OK** (`BACKUP_VERIFY=true`) ;
3. Extraction (`tar -xf`, le module Archive de PowerShell 5.1 refuse les
   chemins absolus du zip — noter : utiliser tar/unzip) ;
4. Import dans `erp_restore_drill` → **118 tables, 4 lots, 8 ventes,
   2 dépenses** = compteurs sources capturés avant le backup ;
5. Base jetable supprimée, artefacts nettoyés.
6. `php artisan schedule:list` atteste : `backup:clean` 01:30 · `backup:run` 02:00.

**Il reste À FAIRE en production** (par déploiement) : activer le cron
`schedule:run` (§3), définir `BACKUP_ARCHIVE_PASSWORD` (coffre) et
`BACKUP_DISKS=backups,backups_offsite` + `BACKUP_OFFSITE_PATH` (§4), puis
rejouer CE drill sur la machine de production avant le go-live.
