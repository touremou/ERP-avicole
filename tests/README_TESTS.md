# AviSmart ERP — Suite de Tests Pest

## Installation

### 1. Installer Pest (si pas encore fait)

```bash
composer require pestphp/pest --dev --with-all-dependencies
composer require pestphp/pest-plugin-laravel --dev

# Initialiser Pest
php artisan pest:install
```

### 2. Copier les fichiers de tests

```bash
# Helper
cp tests/Helpers/AviSmartTestHelper.php tests/Helpers/AviSmartTestHelper.php

# Tests Feature (E2E HTTP)
cp tests/Feature/BatchModuleTest.php tests/Feature/BatchModuleTest.php
cp tests/Feature/StockModuleTest.php tests/Feature/StockModuleTest.php
cp tests/Feature/PermissionsSecurityTest.php tests/Feature/PermissionsSecurityTest.php
cp tests/Feature/ProvenderiModuleTest.php tests/Feature/ProvenderiModuleTest.php
cp tests/Feature/DashboardTest.php tests/Feature/DashboardTest.php

# Tests Unit (services isolés)
cp tests/Unit/StockIntegrationServiceTest.php tests/Unit/StockIntegrationServiceTest.php
cp tests/Unit/DashboardServiceTest.php tests/Unit/DashboardServiceTest.php
```

### 3. Vérifier les factories

Les tests utilisent des `Model::factory()`. Si les factories n'existent pas encore :

```bash
php artisan make:factory BatchFactory --model=Batch
php artisan make:factory BuildingFactory --model=Building
php artisan make:factory EmployeeFactory --model=Employee
php artisan make:factory ProviderFactory --model=Provider
php artisan make:factory StockFactory --model=Stock
php artisan make:factory StockMovementFactory --model=StockMovement
php artisan make:factory DailyCheckFactory --model=DailyCheck
php artisan make:factory RawMaterialFactory --model=RawMaterial
php artisan make:factory FormulaFactory --model=Formula
php artisan make:factory MillProductionFactory --model=MillProduction
php artisan make:factory MillMachineFactory --model=MillMachine
```

### 4. Base de données de test

Dans `.env.testing` (ou `phpunit.xml`) :

```env
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

Ou avec MySQL :

```env
DB_DATABASE=avismart_test
```

## Exécution

```bash
# Tous les tests
php artisan test

# Un module spécifique
php artisan test --filter=BatchModuleTest
php artisan test --filter=StockModuleTest
php artisan test --filter=PermissionsSecurityTest
php artisan test --filter=DashboardTest

# Tests unitaires uniquement
php artisan test tests/Unit

# Tests Feature uniquement
php artisan test tests/Feature

# Avec couverture (nécessite Xdebug ou PCOV)
php artisan test --coverage
```

## Matrice de couverture

| Fichier de test | Module | Bugs couverts | Tests |
|---|---|---|---:|
| `BatchModuleTest.php` | Lots | B-01 à B-13, S-02 à S-10 | 10 |
| `StockModuleTest.php` | Stock | ST-01 à ST-08, STQ-01/02 | 12 |
| `PermissionsSecurityTest.php` | Transversal | B-14, B-20, B-21, S-16, S-17 | 10 |
| `ProvenderiModuleTest.php` | Provenderie | P-01 à P-12 | 6 |
| `DashboardTest.php` | Dashboard | DS-01 | 4 |
| `StockIntegrationServiceTest.php` | Service Stock | B-16, B-17 | 8 |
| `DashboardServiceTest.php` | Service Dashboard | DS-01 à DS-05 | 6 |
| **Total** | | **~50 bugs** | **56** |

## Quels bugs chaque test vérifie

### BatchModuleTest
| Test | Bug |
|---|---|
| `créer un lot initialise current_quantity` | B-02 |
| `modifier un lot ne change PAS current_quantity` | B-03/B-04 |
| `pointage avec mortalité décrémente current_quantity` | B-11 |
| `pas de doublon pointage même date` | B-13 |
| `clôturer change le statut` | B-07 |
| `transférer change le building_id` | B-12 |
| `visiteur ne peut PAS créer` | B-08/S-06 |

### PermissionsSecurityTest
| Test | Bug |
|---|---|
| `offline seules L et C accordées` | B-14 |
| `user sans role_id n'a aucune permission` | B-20 |
| `reconcile rejette données invalides` | B-21 |
| `reconcile refuse visiteur` | B-21 |
| `conflit si serveur plus récent` | B-21 |

### StockIntegrationServiceTest
| Test | Bug |
|---|---|
| `recherche exacte trouve le bon article` | B-16 |
| `conversion Sac→KG` | B-17 |
| `conversion Unité→Alvéole` | B-17 |
| `sortie ne descend pas sous zéro` | Nouveau |

## Conventions

- **Noms en français** : les descriptions de tests sont en français pour coller au contexte métier
- **RefreshDatabase** : chaque test repart d'une base vide
- **AviSmartTestHelper** : centralise le setup RBAC pour éviter la duplication
- **Pest** : syntaxe fonctionnelle (`test()`, `expect()`, `beforeEach()`)
