# Phase 3 — Form Requests + Actions métier

## Fichiers livrés

### Form Requests (validation + autorisation)

| Fichier | Destination | Remplace |
|---|---|---|
| `Requests/Batch/StoreBatchRequest.php` | `app/Http/Requests/Batch/` | Validation inline dans `BatchController::store` |
| `Requests/Batch/UpdateBatchRequest.php` | `app/Http/Requests/Batch/` | Validation inline dans `BatchController::update` |
| `Requests/Batch/CloseBatchRequest.php` | `app/Http/Requests/Batch/` | Validation inline dans `BatchController::close` |
| `Requests/Batch/TransferBatchRequest.php` | `app/Http/Requests/Batch/` | Validation inline dans `BatchTransferController::transfer` |
| `Requests/DailyCheck/StoreDailyCheckRequest.php` | `app/Http/Requests/DailyCheck/` | Validation inline dans `DailyCheckController::store` |

### Actions (logique métier isolée)

| Fichier | Destination | Remplace |
|---|---|---|
| `Actions/Batch/CreateBatch.php` | `app/Actions/Batch/` | `BatchController::store` + le `BatchService` manquant |
| `Actions/Batch/UpdateBatch.php` | `app/Actions/Batch/` | `BatchController::update` (80 lignes → 40) |
| `Actions/Batch/CloseBatch.php` | `app/Actions/Batch/` | `BatchController::close` |
| `Actions/Batch/TransferBatch.php` | `app/Actions/Batch/` | `BatchTransferController::transfer` |
| `Actions/Batch/ReopenBatch.php` | `app/Actions/Batch/` | `BatchController::reopen` |
| `Actions/DailyCheck/RecordDailyCheck.php` | `app/Actions/DailyCheck/` | `DailyCheckController::store` |

## Bugs corrigés

| Bug ID | Description | Corrigé par |
|---|---|---|
| **B-02** | `BatchService` inexistant | `CreateBatch` Action le remplace |
| **B-03** | `update()` écrase `current_quantity` | `UpdateBatch` — liste blanche |
| **B-04** | `update()` écrase `qty_alive` | `UpdateBatch` — `qty_alive` absent de la liste |
| **B-07** | Clôture ignore les coûts santé/aliment | `CloseBatch` — calcul marge complet |
| **B-08** | `update()` sans Gate check | `UpdateBatchRequest::authorize()` |
| **B-09** | `syncAllStocks` sans Gate check | À corriger en Phase 4 (controller) |
| **B-12** | Transfert no-op `current_quantity` | `TransferBatch` — ne touche plus current_quantity |
| **S-03** | `reopen()` efface total_revenue | `ReopenBatch` — recalcul via service |
| **S-07** | Désinfection même si multi-lots | `TransferBatch` + `CloseBatch` — vérification |
| **S-09** | Transfert d'un lot clôturé possible | `TransferBatchRequest::withValidator()` |

## Procédure d'intégration

### Étape 1 — Créer les dossiers

```bash
mkdir -p app/Http/Requests/Batch
mkdir -p app/Http/Requests/DailyCheck
mkdir -p app/Actions/Batch
mkdir -p app/Actions/DailyCheck
```

### Étape 2 — Copier les fichiers

```bash
# Form Requests
cp phase3/Requests/Batch/*.php app/Http/Requests/Batch/
cp phase3/Requests/DailyCheck/*.php app/Http/Requests/DailyCheck/

# Actions
cp phase3/Actions/Batch/*.php app/Actions/Batch/
cp phase3/Actions/DailyCheck/*.php app/Actions/DailyCheck/
```

### Étape 3 — Tester les Actions en isolation (tinker)

```bash
php artisan tinker

# Test CreateBatch
> $action = app(\App\Actions\Batch\CreateBatch::class);
> $batch = $action->execute([
>     'code' => 'TEST-' . now()->format('His'),
>     'type' => 'chair',
>     'model_name' => 'Cobb500',
>     'building_id' => 1,   // ID d'un bâtiment existant
>     'employee_id' => 1,
>     'provider_id' => 1,
>     'qty_alive' => 100,
>     'qty_dead' => 5,
>     'arrival_date' => now()->toDateString(),
>     'buy_price_per_unit' => 3500,
> ]);
> $batch->current_quantity   // 100
> $batch->initial_quantity   // 100
> $batch->qty_dead           // 5
> $batch->total_acquisition_cost  // 350000

# Test UpdateBatch — vérifier qu'il NE TOUCHE PAS current_quantity
> $action = app(\App\Actions\Batch\UpdateBatch::class);
> $batch = \App\Models\Batch::where('status', 'Actif')->first();
> $before = $batch->current_quantity;
> $action->execute($batch, ['employee_id' => 2, 'type' => $batch->type, 'arrival_date' => $batch->arrival_date, 'buy_price_per_unit' => $batch->buy_price_per_unit, 'building_id' => $batch->building_id, 'provider_id' => $batch->provider_id, 'model_name' => $batch->model_name, 'status' => 'Actif']);
> $batch->fresh()->current_quantity === $before  // TRUE — effectif inchangé

# Test CloseBatch
> $action = app(\App\Actions\Batch\CloseBatch::class);
> // Utiliser un lot de test, pas un lot de production !
```

### Étape 4 — NE PAS ENCORE MODIFIER LES CONTROLLERS

Les Actions et Form Requests sont prêts mais les controllers ne les utilisent **pas encore**.
C'est la Phase 4 qui fera le branchement.

Pourquoi séparer : si un bug apparaît dans une Action, on peut le corriger
sans toucher au controller. Et on peut tester les Actions indépendamment.

## Architecture résultante

```
Requête HTTP
    │
    ▼
Form Request (validation + autorisation)
    │
    ▼
Controller (routing, réponse HTTP — 5-10 lignes max)
    │
    ▼
Action (logique métier atomique)
    │
    ├──▶ Model (ORM, relations)
    ├──▶ Service (StockIntegrationService, SanitarySchedulerService)
    └──▶ Observer (effets de bord : impact effectif, alertes)
```

### Ce que les controllers vont devenir (Phase 4)

```php
// AVANT — BatchController::store (50+ lignes de logique)
public function store(Request $request, BatchService $batchService)
{
    if (Gate::denies('C')) return back()->with('error', '...');
    $validated = $request->validate([...30 lignes...]);
    // ... 20 lignes de calculs ...
    $batchService->updateOrCreateBatch($validated);
    return redirect(...);
}

// APRÈS — 3 lignes
public function store(StoreBatchRequest $request, CreateBatch $action)
{
    $batch = $action->execute($request->validated());
    return redirect()->route('batches.show', $batch)
        ->with('success', "Lot {$batch->code} créé.");
}
```

## Points d'attention

### UpdateBatch et la liste blanche

La constante `ALLOWED_FIELDS` dans `UpdateBatch` est **exhaustive**.
Si vous ajoutez un nouveau champ au lot (ex: `temperature_target`),
il faut l'ajouter à cette liste sinon il sera silencieusement ignoré.

### RecordDailyCheck et StockIntegrationService

L'action utilise `StockIntegrationService::syncMovement` en mode `'KG'` forcé.
Ceci est cohérent avec le code actuel du controller.
Le bug B-16 (LIKE pour trouver les articles) n'est PAS corrigé ici —
il sera corrigé dans le refactoring du module Stock (post-module Lots).

### TransferBatchRequest et le model binding

Le `withValidator` de `TransferBatchRequest` fait `$this->route('batch')`.
Ça fonctionne car la route utilise le model binding implicite :
`Route::post('/{batch}/transfer', ...)`.
Si votre route utilise un ID brut, remplacer par `Batch::find($this->route('batch'))`.

## Prochaine étape

Phase 4 : Refactoring des Controllers (branchement sur les Actions + Form Requests).
