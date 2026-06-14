# Guide complet — AviSmart ERP

> ERP de gestion d'élevage multi-espèces (volaille, ruminants, pisciculture)
> pour la Guinée : production, provenderie, couvoir, abattoir, ventes,
> logistique anti-fraude, RH/paie, énergie et notifications WhatsApp.

Ce guide couvre trois volets :

1. **[Installation & déploiement](#1-installation--déploiement)** — mise en service, de l'assistant `/install` à la production.
2. **[Administration](#2-administration)** — utilisateurs, rôles, permissions, fermes, paramètres système.
3. **[Utilisation par module](#3-utilisation-par-module)** — fonctionnement de chaque module métier.

Le détail des optimisations de production, de la checklist de sécurité et de
la procédure de sauvegarde se trouve dans [`DEPLOYMENT.md`](DEPLOYMENT.md).

---

## 1. Installation & déploiement

### 1.1 Prérequis

| Composant | Version |
|-----------|---------|
| PHP | 8.3+ avec `pdo_mysql` (ou `pdo_sqlite`), `mbstring`, `gd`, `intl`, `zip`, `curl`, `xml`, `ctype`, `fileinfo`, `tokenizer`, `openssl` |
| Base de données | MySQL 8 / MariaDB 10.6+ (SQLite possible pour une petite installation) |
| Outils de build | Composer 2, Node 18+ |
| Serveur web | Nginx/Apache + HTTPS (certificat valide) |

### 1.2 Première installation (assistant web)

```bash
git clone <repo> && cd ERP-avicole
cp .env.production.example .env        # APP_NAME, APP_URL, mail, WhatsApp…
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan storage:link
```

Ouvrir ensuite l'application dans un navigateur : tant qu'aucun compte
n'existe en base, **toutes les pages redirigent vers l'assistant
d'installation `/install`**, qui enchaîne 5 étapes :

1. **Prérequis** — vérification de la version PHP, des extensions et des permissions d'écriture (`storage/`, `bootstrap/cache/`, `.env`).
2. **Base de données** — choix MySQL ou SQLite, test de connexion, écriture des variables `DB_*` dans `.env` (et génération d'`APP_KEY` si absente). La base MySQL est créée si elle n'existe pas.
3. **Migrations** — création des tables + chargement des données de référence (espèces, normes zootechniques, modules, paramètres).
4. **Administrateur** — nom de l'entreprise, compte administrateur (remplace le compte de démonstration `admin@admin.com`) et suppression optionnelle du second compte de démonstration `user@users.com`.
5. **Terminé** — pose du marqueur `storage/installed` ; `/install` devient inaccessible.

> Une installation existante (table `users` déjà peuplée) est reconnue
> automatiquement : le marqueur est posé au premier accès sans repasser par
> l'assistant. Pour installer 100 % en ligne de commande, voir
> [`DEPLOYMENT.md` §2](DEPLOYMENT.md).

### 1.3 Mise en production

À chaque déploiement :

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache
php artisan view:cache && php artisan event:cache
```

Et une seule fois, le cron du planificateur :

```cron
* * * * * cd /chemin/ERP-avicole && php artisan schedule:run >> /dev/null 2>&1
```

### 1.4 Tâches planifiées

| Commande | Horaire | Rôle |
|----------|---------|------|
| `farm:release-buildings` | quotidien (minuit) | Libère les bâtiments dont le vide sanitaire de 14 jours est terminé |
| `stocks:sync` | quotidien (minuit) | Synchronise les stocks de sujets et d'œufs (calibres & pertes) |
| `tasks:generate` | 05:00 | Génère les tâches quotidiennes depuis les templates actifs |
| `avismart:daily-summary` | paramétrable (`whatsapp.daily_summary_hour`, défaut 07:00) | Envoie le résumé quotidien WhatsApp aux abonnés |

### 1.5 Mode hors-ligne

Si la base MySQL devient inaccessible, l'application bascule en **mode
offline** : consultation et saisie (L/C) restent possibles côté navigateur
(IndexedDB), puis les données sont synchronisées au retour du serveur via le
module de synchronisation (`/sync`). Les opérations de modification et de
suppression (M/S) sont bloquées tant que la base est indisponible.

### 1.6 Application installable (PWA)

AviSmart est une **Progressive Web App** : depuis un navigateur mobile
(Chrome/Edge Android, Safari iOS) ou desktop, l'option « Installer
l'application » / « Ajouter à l'écran d'accueil » crée une véritable
application avec icône, écran de démarrage et fenêtre autonome (sans barre
d'adresse). Le service worker existant assure le repli hors-ligne (§1.5).

- Le **nom** et l'**icône** de l'application reprennent les paramètres
  `Général > Nom de l'entreprise` et `Général > Logo` ; sans logo, l'icône
  AviSmart par défaut (œuf sur fond vert) est utilisée. Le manifest est
  servi dynamiquement sur `/manifest.webmanifest`.
- **HTTPS est obligatoire** pour l'installation PWA (hors `localhost`).

### 1.7 API mobile (v1)

Une API REST authentifiée par **tokens Sanctum** est exposée sous `/api/v1`
pour la future application mobile native (opérations terrain). Les
permissions L/C/M/S et la matrice Modules × Rôles s'appliquent exactement
comme sur le web (FormRequests et Actions métier partagés).

| Méthode | Endpoint | Rôle |
|---|---|---|
| `POST` | `/api/v1/auth/login` | Obtenir un token (`email`, `password`, `device_name`) — limité à 10 essais/min |
| `GET` | `/api/v1/auth/me` | Profil de l'utilisateur connecté |
| `POST` | `/api/v1/auth/logout` | Révoquer le token de l'appareil courant |
| `GET` | `/api/v1/batches` | Lots actifs (`?status=all` pour tout) |
| `GET` | `/api/v1/batches/{id}` | Détail d'un lot + dernier pointage |
| `POST` | `/api/v1/daily-checks` | Pointage journalier (mortalité, aliment, eau…) |
| `POST` | `/api/v1/egg-productions` | Collecte d'œufs (cumul par jour, taux de ponte recalculé) |

Toutes les routes (hors login) exigent le header `Authorization: Bearer <token>`.
Les tokens sont révocables individuellement (table `personal_access_tokens`).

### 1.8 Langue

L'application est en **français par défaut** (`APP_LOCALE=fr`), y compris les
messages de validation, d'authentification et de pagination
(`lang/fr/*.php`, générés depuis [laravel-lang](https://github.com/Laravel-Lang/lang)).
Les fichiers anglais (`lang/en/*.php`) et `lang/fr.json` (traduction des
chaînes `__('...')` de l'interface d'authentification Breeze) sont fournis.

Chaque utilisateur peut choisir **sa propre langue** (Français/English)
dans `Profil > Informations du profil > Langue` : le choix est enregistré
sur son compte (`users.locale`) et appliqué à toutes ses requêtes (web et
API) par le middleware `SetUserLocale`. Sans choix explicite, la langue
par défaut de l'application s'applique. Les langues proposées sont
définies dans `config/app.php` (`supported_locales`).

---

## 2. Administration

### 2.1 Utilisateurs, rôles et permissions

Le contrôle d'accès combine deux niveaux :

- **Permissions globales L/C/M/S** (Lire, Créer, Modifier, Supprimer) portées par le rôle de l'utilisateur. Quatre rôles de base : `admin` (tout), `manager` (L/C/M), `operator` (L/C), `viewer` (L).
- **Matrice Modules × Rôles** (`Admin > Rôles & permissions`) : dès qu'un rôle dispose d'une matrice configurée, **elle fait seule autorité, module par module** — y compris pour restreindre (ex. : un opérateur limité à la lecture du module Élevage). Les rôles sans matrice retombent sur le comportement global L/C/M/S.

L'administrateur (`admin`) bénéficie d'un bypass complet. Le cache des
permissions est de 5 minutes : un changement de matrice peut mettre jusqu'à
5 minutes à se propager (ou `php artisan cache:clear` pour l'appliquer
immédiatement).

Chaque employé peut recevoir un **compte de connexion** lié à sa fiche
(`Annuaire > Employés > Accès`), avec rôle et statut actif/inactif.

### 2.2 Multi-ferme / multi-site

Le menu `Admin > Fermes` gère plusieurs sites. Chaque utilisateur est
rattaché à une ou plusieurs fermes (avec une ferme par défaut) ; un sélecteur
en en-tête permet de basculer. Toutes les données opérationnelles (lots,
stocks, ventes…) sont cloisonnées par ferme (`farm_id`).

### 2.3 Paramètres système

`Paramètres` (réservé admin) expose 13 groupes : Général, Élevage,
Production, Pisciculture, Provenderie, Abattoir, Couvoir, Planning, Énergie,
WhatsApp, RH & Paie, Stocks, Ventes.

**Principe : tout paramètre visible s'applique réellement.** Chaque clé est
consommée par le code (voir l'audit complet dans
[`docs/SETTINGS_AUDIT.md`](SETTINGS_AUDIT.md)). Exemples structurants :

- `general.timezone` — fuseau horaire appliqué au runtime.
- `general.company_logo` — logo affiché dans le menu et les PDF.
- `ventes.invoice_prefix_bl` / `invoice_prefix_tva` — numérotation des BL/factures.
- `elevage.cycle_*` — durées de cycle par espèce (date de fin prévisionnelle des lots).
- `energie.autonomy_alert_hours` — seuil d'alerte d'autonomie gasoil des groupes électrogènes.
- `whatsapp.daily_summary_hour` — heure d'envoi du résumé quotidien.

Le cache des paramètres a un TTL d'une heure ; toute modification via
l'interface le vide automatiquement. Après une modification directe en base :
`php artisan cache:clear`.

### 2.4 Notifications WhatsApp

`config/whatsapp.php` + groupe de paramètres WhatsApp. Drivers disponibles :
`log` (développement), `callmebot` (gratuit, test), `ultramsg`, `wati`,
`twilio`. Le paramètre `whatsapp.api_url` permet une instance auto-hébergée
(ultramsg/wati). `whatsapp.admin_phone` sert de destinataire de secours pour
les alertes critiques (mortalité, stock, gasoil, fraude). Chaque envoi est
journalisé dans le centre de notifications.

### 2.5 Corbeille et intégrité

Les suppressions passent par une **corbeille** (`Admin > Corbeille`,
soft-delete) avec restauration. Des garde-fous d'intégrité empêchent les
suppressions destructrices : stock avec historique de mouvements, formule
déjà produite, lot parent référencé, etc.

---

## 3. Utilisation par module

### 3.1 Parc (bâtiments)

Référentiel des bâtiments : capacité, surface, type (chair/ponte…), statut
(Vide, Occupé, Vide sanitaire). Le vide sanitaire de 14 jours est levé
automatiquement chaque nuit (`farm:release-buildings`).

### 3.2 Élevage (lots)

Cœur du système : chaque **lot** (bande) appartient à une espèce et un type
de production — volaille (chair, ponte, reproducteur, poussinière, caille,
dinde), ruminants (caprin lait, ovin, dont objectif Tabaski avec poids cible
`elevage.tabaski_target_weight`), pisciculture (tilapia, carpe).

- **Suivi quotidien** (`daily-checks`) : mortalité, consommation aliment/eau, pesées, observations. Les extensions par espèce (lait, GMQ…) s'affichent selon le type de lot.
- La **date de fin prévisionnelle** est calculée depuis la norme zootechnique du type de production, à défaut depuis les paramètres `elevage.cycle_*`.
- KPI sur fiche lot : taux de mortalité, indice de consommation (cibles `provenderie.fc_target_*`), GMQ (cibles `elevage.gmq_cible_*`), poids moyen.
- **Transferts de lots** entre bâtiments et **campagnes saisonnières** (Tabaski, Ramadan) pour piloter des objectifs de vente datés.

### 3.3 Santé & prophylaxie

Protocoles de soins par espèce (vaccins, traitements, rappels), événements
de santé par lot, coûts vétérinaires intégrés au rapport santé-finance.

### 3.4 Production (œufs & lait)

- **Œufs** : saisie journalière par lot avec calibres pilotés par `production.egg_grades`, taux de ponte comparé à la courbe de référence, badge Montée/Pic/Post-pic (`production.peak_laying_week`), mouvements d'œufs (casse, conso, incubation) synchronisés avec les stocks.
- **Lait** (caprin) : collecte par lot avec cible par tête (`elevage.lait_cible_chevre`).

### 3.5 Couvoir & incubation

Incubations par machine (couveuses gérées dans `incubators-devices`) :
mise en incubation, **mirage** (date prévue J+`couvoir.mirage_day`),
**éclosion**, avec cibles de fertilité et d'éclosabilité
(`couvoir.fertility_target` / `hatchability_target`). Le **dispatch
poussins** post-éclosion répartit les sujets vers les lots de destination.

### 3.6 Provenderie (usine d'aliment)

Matières premières (achats, stocks), **formules** par espèce, productions
d'aliment (consommation de matières → production de sacs), machines et
maintenance. Une formule déjà produite ne peut pas être supprimée. Les achats
d'aliment externes passent par `feed-purchases`.

### 3.7 Stocks & logistique anti-fraude

Inventaire multi-catégories (œufs, aliment, litières, matériels…) avec
mouvements tracés (entrée/sortie/ajustement avec delta journalisé),
conversion d'unités (sac→kg), seuils d'alerte, et **expéditions/réceptions**
(`dispatches`) avec détection d'écarts entre quantités expédiées et reçues
(anti-fraude).

### 3.8 Ventes & facturation

Clients (plafond crédit par défaut `ventes.credit_limit_default`), ventes
avec **BL et factures TVA** (préfixes paramétrables, pied de page, échéance
`ventes.payment_delay_days`), encaissements partiels avec suivi du reste dû
(un encaissement supérieur au reste dû est refusé), export PDF.

### 3.9 Dépenses

Registre des dépenses générales par catégorie, intégré au rapport de
charges mensuelles et au compte de résultat.

### 3.10 Abattoir & transformation

Sessions d'abattage par lot, découpe (rendement cible
`abattoir.yield_cutting` coloré en temps réel), fumage, stock de produits
finis.

### 3.11 Eau & énergie

Sources d'eau et d'énergie (EDG, groupes électrogènes, solaire), relevés de
consommation, achats de gasoil, maintenance. Alertes d'autonomie gasoil
(`energie.autonomy_alert_hours`) relayées au tableau de bord et par
WhatsApp ; valorisation de la production solaire en équivalent EDG
(`energie.kwh_price_edg`).

### 3.12 Planning & tâches

Planification des bandes (calendrier d'occupation des bâtiments, lots
planifiés par espèce) et **tâches opérationnelles** générées chaque matin
depuis des templates (vaccinations, pesées, nettoyages…), assignables aux
employés.

### 3.13 RH & paie

Employés (fiches, congés avec dotation initiale `rh.annual_leave_days`),
**bulletins de paie** (heures supplémentaires majorées `rh.overtime_rate`,
modes de paiement `rh.payment_methods`, pied de bulletin paramétrable),
fournisseurs, et comptes de connexion liés aux employés.

### 3.14 Rapports

Tous exportables en PDF, filtrables par espèce/période : performance
technique, compte de résultat (profit & loss), poussinière, santé-finance,
charges mensuelles, GMQ (ruminants), pisciculture (survie/IC/cycles), plus
le flux de trésorerie au tableau de bord.

### 3.15 Notifications

Centre de notifications WhatsApp : abonnements par utilisateur et par type
d'alerte, résumé quotidien, alertes temps réel (mortalité élevée, stock sous
seuil, gasoil critique, écart de réception), historique des envois.

---

*Document maintenu avec l'application — toute évolution de module doit être
répercutée ici et dans [`docs/SETTINGS_AUDIT.md`](SETTINGS_AUDIT.md) pour
les nouveaux paramètres.*
