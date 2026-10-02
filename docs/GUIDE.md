# Guide complet — AviSmart ERP

> ERP de gestion d'élevage multi-espèces (volaille, ruminants, pisciculture)
> pour la Guinée : production, provenderie, couvoir, abattoir, ventes,
> logistique anti-fraude, RH/paie, énergie et notifications WhatsApp.

Ce guide couvre trois volets :

1. **[Installation & déploiement](#1-installation--déploiement)** — mise en service, de l'assistant `/install` à la production.
2. **[Administration](#2-administration)** — utilisateurs, rôles, permissions, fermes, paramètres système.
3. **[Utilisation par module](#3-utilisation-par-module)** — fonctionnement de chaque module métier.

Le détail des optimisations de production, de la checklist de sécurité et de
la procédure de sauvegarde se trouve dans [`DEPLOYMENT.md`](../DEPLOYMENT.md).
La liste de contrôle de **mise en production** est dans
[`ops/mise-en-production.md`](ops/mise-en-production.md).

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

Ouvrir ensuite l'application dans un navigateur : tant que l'application
n'est pas installée, **toutes les pages redirigent vers l'assistant
d'installation `/install`**, qui enchaîne 5 étapes :

1. **Prérequis** — vérification de la version PHP, des extensions et des permissions d'écriture (`storage/`, `bootstrap/cache/`, `.env`).
2. **Base de données** — choix MySQL ou SQLite, test de connexion, écriture des variables `DB_*` dans `.env` (et génération d'`APP_KEY` si absente). La base MySQL est créée si elle n'existe pas.
3. **Migrations** — création des tables + chargement des données de référence (espèces, normes zootechniques, modules, paramètres) **et de comptes de démonstration**, tous au mot de passe public `password`.
4. **Administrateur** — nom de l'entreprise et **votre** compte administrateur (une adresse de démonstration est refusée). À la validation, **tous les comptes de démonstration sont supprimés** : aucun compte au mot de passe public ne survit à l'installation.
5. **Terminé** — bascule de `.env` en `APP_ENV=production` / `APP_DEBUG=false`, et pose du marqueur d'installation **sur disque (`storage/installed`) et en base** ; `/install` devient inaccessible.

> **Pourquoi le marqueur est aussi en base** : `storage/` ne voyage pas avec
> l'application (le déploiement l'exclut). Un nouvel hôte branché sur une base
> existante n'a donc pas le fichier ; c'est le marqueur en base — et la
> présence d'un administrateur réel — qui gardent alors l'assistant fermé.
>
> Une installation existante est reconnue automatiquement : le marqueur est
> posé au premier accès sans repasser par l'assistant. Pour installer 100 %
> en ligne de commande, voir [`DEPLOYMENT.md` §2](../DEPLOYMENT.md).

### 1.3 Mise en production

**Avant la toute première mise en production, suivre la liste de contrôle
[`ops/mise-en-production.md`](ops/mise-en-production.md)** (configuration,
base InnoDB, diagnostic, remise en ordre des données, sauvegarde restaurée,
recette, signatures).

Pour vérifier une installation à tout moment — lecture seule, rien n'est
modifié :

```bash
php artisan avismart:diagnostic     # code de sortie non nul s'il y a un point BLOQUANT
```

Il signale notamment le mode débogage resté actif, les comptes de
démonstration encore au mot de passe public, les canaux d'alerte muets, les
sauvegardes et le planificateur.

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

Le planificateur (`schedule:run`, lancé chaque minute par le cron ci-dessus)
porte **25 tâches** ; `php artisan schedule:list` les affiche toutes avec leur
prochaine exécution. Les plus importantes :

| Commande | Horaire | Rôle |
|----------|---------|------|
| `backup:clean` / `backup:run` | 01:30 / 02:00 | Purge puis sauvegarde nocturne (base + fichiers) |
| `avismart:check-backups` | 03:00 | Alerte si la dernière sauvegarde manque ou est trop ancienne |
| `farm:release-buildings` | quotidien | Libère les bâtiments dont le vide sanitaire est terminé (durée : `elevage.sanitary_break_days`, 14 j par défaut) |
| `batches:rebuild-quantities --force` | quotidien | Recalcule les effectifs vivants des lots depuis les pointages |
| `tasks:generate` | 05:00 | Génère les tâches quotidiennes depuis les modèles actifs |
| `avismart:daily-summary` | `whatsapp.daily_summary_hour` (défaut 07:00) | Résumé quotidien WhatsApp aux abonnés |
| `sales:payment-reminders` | 09:00 | Relance des factures échues |
| `treasury:repair-balances` | lundi 06:00 | **Rapport seul** : compare les soldes au grand-livre, ne corrige rien |

> Sans le cron, **aucune** de ces tâches ne tourne — sauvegardes comprises.
> `avismart:diagnostic` signale un planificateur muet.

### 1.5 Mode hors-ligne

Deux outils, pour deux situations :

- **Application terrain** (`mobile/`, cf. §1.7) — l'outil prévu pour travailler
  sans réseau. Chaque saisie part dans une **file locale** et porte un
  identifiant unique créé à la saisie : au retour du réseau, un rejeu ne peut
  pas l'enregistrer deux fois. Une saisie refusée par le serveur (droit
  manquant, stock insuffisant, jour déjà pointé…) sort de la file vers le bac
  **« À corriger »**, avec le motif.
- **Navigateur** — si la base devient inaccessible, l'application web passe en
  mode dégradé : consultation et saisie (L/C) restent possibles, modification
  et suppression (M/S) sont bloquées. Les créations de lots, pointages,
  collectes d'œufs, mouvements de stock, ventes et dépenses saisis ainsi sont
  mis en file dans le
  navigateur et envoyés au serveur (`/api/sync/*`) au retour de la connexion.

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

### 1.7 API mobile (v1) et application terrain

L'application terrain (`mobile/`, React, installable en PWA)
parle à une API REST sous `/api/v1`, authentifiée par **jetons Sanctum** (un
jeton par appareil). Les permissions L/C/M/S et la matrice Modules × Rôles
s'appliquent exactement comme sur le web.

| Méthode | Endpoint | Rôle |
|---|---|---|
| `POST` | `/api/v1/auth/login` | Obtenir un jeton (`email`, `password`, `device_name`) — limité à 10 essais/min |
| `GET` | `/api/v1/auth/me` | Profil, permissions et ferme courante |
| `POST` | `/api/v1/auth/logout` | Rendre le jeton de l'appareil |
| `PATCH` | `/api/v1/auth/password` | Changer son mot de passe (voir §2.1) |
| `GET` | `/api/v1/sync/pull` | Référentiels à jour (lots, stocks, clients, employés…), filtrés par droits |
| `POST` | `/api/v1/sync/push` | **Toutes les saisies terrain** : une file d'opérations typées (`daily_check.create`, `egg_collection.create`, `sale.create`, `expense.create`, `stock_movement.create`…) |
| `GET` | `/api/v1/batches`, `/api/v1/tasks`, `/api/v1/me/week`, `/api/v1/*/today` | Consultations |

Le détail de l'idempotence et des statuts renvoyés par `sync/push`
(`success`, `already_synced`, `conflict`, `validation_failed`,
`permission_denied`) est décrit dans [`mobile/phase-0-spec.md`](mobile/phase-0-spec.md).

Toutes les routes (hors connexion) exigent `Authorization: Bearer <jeton>`.

> **Abonnement** : quand le système de licence est armé et l'abonnement échu
> (période de grâce passée), l'API répond **402** — comme le web renvoie vers
> l'écran d'activation. L'application terrain l'affiche à l'agent ; ses saisies
> non envoyées **restent sur le téléphone** et partiront au renouvellement.

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

Le contrôle d'accès repose sur la **matrice Modules × Rôles**
(`Admin > Rôles & permissions`) : pour chaque rôle et chaque module, quatre
droits L/C/M/S (Lire, Créer, Modifier, Supprimer). **Elle fait seule
autorité** : un module non coché, c'est aucun accès — il n'y a pas de repli
sur un droit global.

Quatre rôles sont créés à l'installation, que l'on peut ajuster ou compléter :
`admin` (L/C/M/S), `technicien` (L/C/M), `vendeur` (L/C), `ouvrier` (L).
L'administrateur (`admin`) bénéficie d'un accès complet. Les droits sont mis
en cache 5 minutes ; `php artisan cache:clear` applique un changement
immédiatement.

Chaque employé peut recevoir un **compte de connexion** lié à sa fiche
(`Annuaire > Employés > Accès`), avec rôle et statut actif/inactif.

**Sécurité des comptes — ce qui se passe, et quand :**

| Geste | Effet sur les appareils (application terrain) |
|---|---|
| L'utilisateur change **son** mot de passe (web ou application) | Ses **autres** appareils sont déconnectés ; celui qui fait la demande reste connecté |
| Réinitialisation par lien « mot de passe oublié » | **Tous** ses appareils sont déconnectés |
| Un administrateur réinitialise le mot de passe (Utilisateurs **ou** Espace RH) | **Tous** ses appareils sont déconnectés |
| Un administrateur **suspend** le compte | Tous ses appareils sont déconnectés, et **le restent** à la réactivation : chaque appareil devra se reconnecter |

> Les sessions ouvertes dans un **navigateur** ne sont pas coupées par un
> changement de mot de passe. Le formulaire « mot de passe oublié » répond de
> la même façon que l'adresse existe ou non, et il est limité à 6 demandes
> par minute.

**Supprimer ou suspendre ?** La suppression d'un compte est **refusée** dès
qu'elle emporterait des enregistrements (par exemple ses clôtures de caisse) :
on **suspend** alors le compte, ce qui coupe l'accès en gardant l'historique.

### 2.2 Multi-ferme / multi-site

Le menu `Admin > Fermes` gère plusieurs sites. Chaque utilisateur est
rattaché à une ou plusieurs fermes (avec une ferme par défaut) ; un sélecteur
en en-tête permet de basculer. Toutes les données opérationnelles (lots,
stocks, ventes…) sont cloisonnées par ferme (`farm_id`).

### 2.3 Paramètres système

`Paramètres` (réservé admin) regroupe les réglages par domaine : Général,
Élevage, Production, Pisciculture, Provenderie, Abattoir, Couvoir, Cultures,
Planning, Énergie, WhatsApp, RH & Paie, Stocks, Ventes, Numérotation,
Étiquettes…

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

**Espèces** (`Paramètres > Espèces`) : désactiver une espèce la retire de
**tous les choix à venir** — création de lot, types de bâtiment, types de
production (formules d'aliment, plans de bande, protocoles), normes. Elle ne
réécrit rien de ce qui existe : un bâtiment, une formule ou un
protocole déjà rattachés gardent leur type et restent filtrables. Une espèce
qui a encore des lots actifs ne peut pas être désactivée.

> Ce réglage s'applique à **toutes les fermes** de l'installation (il n'est
> pas, aujourd'hui, propre à un site).

### 2.4 Notifications WhatsApp

`config/whatsapp.php` + groupe de paramètres WhatsApp. Drivers disponibles :
`log` (développement), `callmebot` (gratuit, test), `ultramsg`, `wati`,
`twilio`. Le paramètre `whatsapp.api_url` permet une instance auto-hébergée
(ultramsg/wati). `whatsapp.admin_phone` sert de destinataire de secours pour
les alertes critiques (mortalité, stock, gasoil, fraude). Chaque envoi est
journalisé dans le centre de notifications.

### 2.5 Corbeille et intégrité

Les suppressions de bâtiments, fournisseurs, employés et lots passent par
une **corbeille** (`Admin > Corbeille`) avec restauration. La **suppression
définitive** depuis la corbeille est **refusée** tant que l'élément porte un
historique — pointages, bulletins de paie, ventes, etc. —, et le refus dit
ce qui serait emporté. D'autres garde-fous empêchent les suppressions
destructrices ailleurs : article de stock avec historique de mouvements,
formule déjà produite, compte utilisateur portant des clôtures de caisse.

---

## 3. Utilisation par module

### 3.1 Parc (bâtiments)

Référentiel des bâtiments : capacité, surface, type (chair, ponte,
poussinière, bergerie, porcherie…), statut (Vide, Disponible, Occupé, En
désinfection, Maintenance). Les **types proposés suivent les espèces actives**
(§2.3) ; `Mixte` est toujours proposé. Le vide sanitaire — durée réglable
(`elevage.sanitary_break_days`, 14 jours par défaut) — est levé
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
