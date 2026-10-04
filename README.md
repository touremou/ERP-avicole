# AviSmart ERP

ERP de gestion d'élevage avicole (et productions associées : pisciculture,
cultures, provenderie, abattoir, commerce, finance, RH…), bâti sur **Laravel
13**. Pensé pour le marché africain : fonctionnement **hors-ligne**, paiements
mobile money, notifications WhatsApp/SMS, traçabilité par QR code.

## Documentation

➡️ **Toute la documentation est dans [`docs/`](docs/README.md).**

| Pour… | Lire |
|-------|------|
| Installer (local / en ligne) + vendre une instance | [docs/INSTALLATION.md](docs/INSTALLATION.md) |
| Utiliser les modules métier | [docs/GUIDE.md](docs/GUIDE.md) |
| Déployer & exploiter en production | [DEPLOYMENT.md](DEPLOYMENT.md) |
| **Ouvrir en production** (liste à cocher et signer) | [docs/ops/mise-en-production.md](docs/ops/mise-en-production.md) |
| Serveur de licence (fournisseur) | [license-server/README.md](license-server/README.md) |

## Démarrage express (local)

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite        # DB_CONNECTION=sqlite dans .env
php artisan migrate --seed
php artisan serve                     # http://127.0.0.1:8000
```

`migrate --seed` crée des **comptes de démonstration** au mot de passe public
`password` : ils ne valent pas installation. Au premier accès, l'assistant
**`/install`** prend donc le relais (prérequis, base de données, compte
administrateur) ; il crée **votre** administrateur et supprime ces comptes.
Détails : [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Monétisation (abonnement)

L'ERP intègre un système de **licence signée hors-ligne** (Ed25519) avec plans,
déverrouillage par module, quotas SMS et limites. Les codes sont émis par le
**serveur fournisseur** ([`license-server/`](license-server/README.md)), activés
côté client dans *Tableau de bord → Licence*. Chaîne complète (génération →
activation → renouvellement → révocation) :
[docs/INSTALLATION.md §5](docs/INSTALLATION.md#5-monétisation--de-la-clé-à-lactivation).

## Tests

```bash
php artisan test
```

CI : `.github/workflows/ci.yml` — migrations sur base fraîche, suite PHP sur
SQLite **et** MySQL 8 (dont l'interopérabilité serveur de licence ↔ ERP), tests
JavaScript de la synchronisation hors-ligne
(`node --test 'resources/js/tests/*.test.js'`) et tests de l'application
terrain (`node --experimental-strip-types --test 'mobile/tests/*.test.mjs'`),
sous Node 22.
