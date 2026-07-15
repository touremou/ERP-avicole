<?php

/**
 * AviSmart ERP — Routes web.php (v6 — 04/06/2026)
 *
 * MODULES (12) :
 * - Parc (bâtiments)
 * - Technique (lots, santé, rapports, protocoles)
 * - Production (œufs, couvoir)
 * - Provenderie (matières premières, formules, production, machines)
 * - Ventes & Facturation (clients, ventes/BL/factures, paiements)
 * - Logistique & Anti-Fraude (stocks, expéditions, réceptions, écarts)
 * - Eau & Énergie (sources eau/énergie, relevés, achats gasoil, maintenance)
 * - Planification des Bandes (calendrier, occupation bâtiments)
 * - Abattoir & Transformation (abattage, découpe, fumage, stock produits finis)
 * - Notifications WhatsApp (résumé quotidien, alertes temps réel)
 * - Annuaire (employés, fournisseurs, utilisateurs)
 * - Offline (API IndexedDB, sync)
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ProductionNormController;
use App\Http\Controllers\{
    ProfileController, DashboardController, BuildingController,
    BatchController, DailyCheckController, EmployeeController,
    ProviderController, UserController, TrashController,
    ProtocolController, HealthController, ReportController,
    FeedPurchaseController, EggProductionController, EggMovementController,
    IncubationController, IncubatorController, BatchTransferController, StockController,
    RawMaterialController, FormulaController, MillProductionController, MillMachineController,
    ProvenderieDashboardController, ProductionController, SyncGatewayController,
    ClientController, SaleController, PaymentController, DispatchController,
    UtilityController,
    NotificationController,
    PlanningController,
    SlaughterController,
    FarmController,
    ChickDispatchController,
    SettingsController,
    PayrollController,
    TaskController,
    SpeciesController,
    CampaignController,
    MilkProductionController,
    ExpenseController,
    EmployeeAccessController,
    EmployeeSelfController,
    MediaController,
    InstallController,
    PwaController,
    CultureDashboardController,
    PlotController,
    CropCycleController,
    CropTransformationController,
    CropCatalogueController,
    CropCampaignController,
    CropRecipeController,
    CropProtocolController,
    CropReportController,
    CropCalendarEventController,
    WeatherController,
    TraceabilityController,
    NotificationTemplateController
};

Route::redirect('/', '/login');

// Manifest PWA dynamique (nom + icône pilotés par les paramètres).
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// Page de repli hors-ligne (PWA). VOLONTAIREMENT PUBLIQUE : c'est une coquille
// statique qui ne lit que le miroir IndexedDB côté client. Si elle était
// protégée par `auth`, le service worker (qui la pré-cache) ou un repli de
// navigation déclenché DÉCONNECTÉ la ferait mémoriser comme URL « intended » —
// l'utilisateur serait alors renvoyé sur /offline juste après connexion avant
// d'être redirigé vers son tableau de bord.
Route::get('/offline', fn () => view('offline'))->name('offline');

// ──────────────────────────────────────────────
// ASSISTANT D'INSTALLATION (premier démarrage)
// ──────────────────────────────────────────────
Route::prefix('install')->name('install.')->group(function () {
    Route::middleware('redirect.if.installed')->group(function () {
        Route::get('/', [InstallController::class, 'welcome'])->name('welcome');
        Route::get('/database', [InstallController::class, 'database'])->name('database');
        Route::post('/database', [InstallController::class, 'storeDatabase'])->name('database.store');
        Route::get('/migrate', [InstallController::class, 'migrate'])->name('migrate');
        Route::post('/migrate', [InstallController::class, 'runMigrate'])->name('migrate.run');
        Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
        Route::post('/admin', [InstallController::class, 'storeAdmin'])->name('admin.store');
    });

    Route::get('/finish', [InstallController::class, 'finish'])->name('finish');
});

// Service des fichiers publics (logos, photos…) sans dépendre du symlink storage.
// Volontairement public : le logo de l'entreprise s'affiche aussi sur la page de connexion.
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.show');

// ──────────────────────────────────────────────
// TRAÇABILITÉ PUBLIQUE (scan du QR d'un lot / carton d'œufs)
// ──────────────────────────────────────────────
// Volontairement publique : un client, un inspecteur ou un distributeur doit
// pouvoir vérifier l'origine d'un lot en scannant le QR de l'étiquette, sans
// compte. N'expose que des informations d'origine (aucune donnée financière).
Route::get('/trace/lot/{code}', [TraceabilityController::class, 'batch'])->name('trace.batch');
Route::get('/trace/op/{number}', [TraceabilityController::class, 'mill'])->name('trace.mill');
Route::get('/trace/transformation/{number}', [TraceabilityController::class, 'crop'])->name('trace.crop');
Route::get('/trace/expedition/{number}', [TraceabilityController::class, 'dispatch'])->name('trace.dispatch');
Route::get('/trace/recolte/{uuid}', [TraceabilityController::class, 'harvest'])->name('trace.harvest');

// ──────────────────────────────────────────────
// PROFIL & DASHBOARD (tout utilisateur connecté)
// ──────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/analytics', [DashboardController::class, 'analytics'])->name('dashboard.analytics');

    // Personnalisation du tableau de bord (par utilisateur, aucun droit module requis).
    Route::get('/dashboard/preferences', [\App\Http\Controllers\DashboardConfigurationController::class, 'edit'])->name('dashboard.config');
    Route::put('/dashboard/preferences', [\App\Http\Controllers\DashboardConfigurationController::class, 'update'])->name('dashboard.config.update');

    // Espace personnel de l'utilisateur connecté (lecture seule).
    Route::get('/mon-espace', [EmployeeSelfController::class, 'index'])->name('mon-espace');

    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
    });
});

// ──────────────────────────────────────────────
// ACCÈS SÉCURISÉ PAR PERMISSIONS (L, C, M, S)
// ──────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    // ─── BÂTIMENTS ───
    Route::prefix('buildings')->name('buildings.')->controller(BuildingController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{building}', 'show')->name('show')->middleware('can:L');
        Route::get('/{building}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{building}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{building}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── LOTS (Batches) ───
    Route::prefix('batches')->name('batches.')->controller(BatchController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/archives', 'archives')->name('archives')->middleware('can:L');
        Route::get('/{batch}/label', [TraceabilityController::class, 'batchLabel'])->name('label')->where('batch', '[0-9]+')->middleware('can:L');
        Route::get('/{batch}', 'show')->name('show')->where('batch', '[0-9]+')->middleware('can:L');

        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');

        Route::get('/{batch}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{batch}', 'update')->name('update')->middleware('can:M');
        Route::get('/{batch}/close', 'showCloseForm')->name('close_form')->middleware('can:M');
        Route::put('/{batch}/close', 'close')->name('close')->middleware('can:M');
        Route::put('/{batch}/reopen', 'reopen')->name('reopen')->middleware('can:M');
        Route::post('/{batch}/transfer', [BatchTransferController::class, 'transfer'])->name('transfer')->middleware('can:M');

        // S-16 corrigé : syncAllStocks est une opération admin (recalcule TOUS les lots)
        Route::post('/sync-stocks', 'syncAllStocks')->name('sync_stocks')->middleware('can:S');

        Route::delete('/{batch}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── CAMPAGNES SAISONNIÈRES (Tabaski/Eid, Ramadan...) ───
    Route::prefix('campaigns')->name('campaigns.')->controller(CampaignController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:elevage.L');
        Route::get('/create', 'create')->name('create')->middleware('can:elevage.C');
        Route::post('/', 'store')->name('store')->middleware('can:elevage.C');
        Route::get('/{campaign}', 'show')->name('show')->middleware('can:elevage.L');
        Route::get('/{campaign}/edit', 'edit')->name('edit')->middleware('can:elevage.M');
        Route::put('/{campaign}', 'update')->name('update')->middleware('can:elevage.M');
        Route::post('/{campaign}/attach-batch', 'attachBatch')->name('attachBatch')->middleware('can:elevage.M');
        Route::delete('/{campaign}/detach-batch/{batch}', 'detachBatch')->name('detachBatch')->middleware('can:elevage.M');
        Route::delete('/{campaign}', 'destroy')->name('destroy')->middleware('can:elevage.S');
    });

    // ─── STOCKS (Inventaire) ───
    Route::prefix('inventory')->name('stocks.')->controller(StockController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/item/{id}', 'show')->name('show')->middleware('can:L');
        Route::get('/item/{id}/label', [TraceabilityController::class, 'stockLabel'])->name('label')->middleware('can:L');
        Route::get('/export/{category}', 'export')->name('export')->middleware('can:L');

        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/store', 'store')->name('store')->middleware('can:C');
        Route::post('/move', 'move')->name('move')->middleware('can:C');

        // S-16 corrigé : syncAll = opération de maintenance admin
        Route::post('/sync-all', 'syncAll')->name('syncAll')->middleware('can:S');

        Route::get('/{id}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{id}', 'update')->name('update')->middleware('can:M');
        Route::put('/item/{id}/threshold', 'updateThreshold')->name('update_threshold')->middleware('can:M');

        Route::delete('/{id}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── DÉMARQUE & AJUSTEMENTS D'INVENTAIRE (module: logistique) ───
    Route::prefix('stock-adjustments')->name('stock-adjustments.')->controller(\App\Http\Controllers\StockAdjustmentController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/csv', 'exportCsv')->name('csv')->middleware('can:L');
        Route::get('/pdf', 'exportPdf')->name('pdf')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
    });

    // ─── MAINTENANCE TECHNIQUE STOCKS (inventaire physique des œufs) ───
    // Le contrôleur (EggProductionController) impose production.S : on aligne
    // explicitement le middleware de route, le nom `stocks.*` résolvant sinon
    // vers le module logistique et créant une exigence croisée incohérente.
    Route::middleware('can:production.S')->group(function () {
        Route::get('admin/stock-maintenance', [EggProductionController::class, 'maintenance'])->name('stocks.maintenance');
        Route::post('admin/stock-rebase', [EggProductionController::class, 'rebase'])->name('stocks.rebase');
    });

    // ─── PROVENDERIE ───
    Route::prefix('provenderie')->group(function () {
        Route::get('/dashboard', [ProvenderieDashboardController::class, 'index'])->name('provenderie.dashboard')->middleware('can:L');

        Route::prefix('materials')->name('raw-materials.')->controller(RawMaterialController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('can:L');
            Route::post('/', 'store')->name('store')->middleware('can:C');
            Route::put('/{id}', 'update')->name('update')->middleware('can:M');
            Route::put('/{id}/add-stock', 'updateStock')->name('update-stock')->middleware('can:M');
            Route::put('/{id}/remove-stock', 'removeStock')->name('remove-stock')->middleware('can:M');
            Route::put('/{id}/nutrition', 'updateNutrition')->name('nutrition')->middleware('can:M');
            Route::delete('/{id}', 'destroy')->name('destroy')->middleware('can:S');
        });

        Route::middleware('can:L')->resource('formulas', FormulaController::class);
        Route::post('/formulas/norms/import', [FormulaController::class, 'importNorms'])->name('norms.import')->middleware('can:S');

        Route::prefix('production')->name('production.')->controller(MillProductionController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('can:L');
            Route::get('/create', 'create')->name('create')->middleware('can:C');
            Route::post('/', 'store')->name('store')->middleware('can:C');
            Route::get('/{id}/label', [TraceabilityController::class, 'millLabel'])->name('label')->middleware('can:L');
            Route::get('/{id}', 'show')->name('show')->middleware('can:L');
            Route::put('/{id}/complete', 'complete')->name('complete')->middleware('can:M');
            Route::put('/{id}/cancel', 'cancel')->name('cancel')->middleware('can:M');
        });

        Route::prefix('machines')->name('machines.')->controller(MillMachineController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('can:L');
            Route::post('/', 'store')->name('store')->middleware('can:C');
            Route::put('/{id}', 'update')->name('update')->middleware('can:M');
            Route::put('/{id}/reset', 'reset')->name('reset')->middleware('can:M');
            Route::put('/{id}/status', 'updateStatus')->name('status')->middleware('can:M');
            Route::post('/{id}/toggle', 'toggleStatus')->name('toggle')->middleware('can:M');
            Route::delete('/{id}', 'destroy')->name('destroy')->middleware('can:S');
        });
    });

    // ─── PRODUCTION VÉGÉTALE (parcelles, cycles de culture, récoltes) ───
    Route::get('/cultures/dashboard', [CultureDashboardController::class, 'index'])->name('cultures.dashboard')->middleware('can:L');

    Route::prefix('cultures/plots')->name('plots.')->controller(PlotController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{plot}', 'show')->name('show')->where('plot', '[0-9]+')->middleware('can:L');
        Route::get('/{plot}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{plot}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{plot}', 'destroy')->name('destroy')->middleware('can:S');
    });

    Route::prefix('cultures/cycles')->name('crop-cycles.')->controller(CropCycleController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropCycle}', 'show')->name('show')->where('cropCycle', '[0-9]+')->middleware('can:L');
        Route::get('/{cropCycle}/edit', 'edit')->name('edit')->where('cropCycle', '[0-9]+')->middleware('can:M');
        Route::put('/{cropCycle}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{cropCycle}', 'destroy')->name('destroy')->middleware('can:S');
        // Récoltes (sous-ressource du cycle)
        Route::get('/{cropCycle}/harvests/create', 'createHarvest')->name('harvests.create')->middleware('can:C');
        Route::post('/{cropCycle}/harvests', 'storeHarvest')->name('harvests.store')->middleware('can:C');
        Route::get('/{cropCycle}/harvests/{harvest}/label', [TraceabilityController::class, 'harvestLabel'])->name('harvests.label')->middleware('can:L');
        Route::get('/{cropCycle}/harvests/{harvest}/edit', 'editHarvest')->name('harvests.edit')->middleware('can:M');
        Route::put('/{cropCycle}/harvests/{harvest}', 'updateHarvest')->name('harvests.update')->middleware('can:M');
        Route::delete('/{cropCycle}/harvests/{harvest}', 'destroyHarvest')->name('harvests.destroy')->middleware('can:S');
        // Intrants (sous-ressource du cycle)
        Route::get('/{cropCycle}/inputs/create', 'createInput')->name('inputs.create')->middleware('can:C');
        Route::post('/{cropCycle}/inputs', 'storeInput')->name('inputs.store')->middleware('can:C');
        Route::get('/{cropCycle}/inputs/{input}/edit', 'editInput')->name('inputs.edit')->middleware('can:M');
        Route::put('/{cropCycle}/inputs/{input}', 'updateInput')->name('inputs.update')->middleware('can:M');
        Route::delete('/{cropCycle}/inputs/{input}', 'destroyInput')->name('inputs.destroy')->middleware('can:S');

        // Validation des étapes de l'itinéraire technique appliqué au cycle.
        Route::post('/{cropCycle}/protocol-steps/{item}/complete', 'completeStep')->name('steps.complete')->middleware('can:M');
        Route::delete('/{cropCycle}/protocol-steps/{item}/complete', 'uncompleteStep')->name('steps.uncomplete')->middleware('can:M');
    });

    Route::prefix('cultures/transformations')->name('crop-transformations.')->controller(CropTransformationController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropTransformation}/label', [TraceabilityController::class, 'cropLabel'])->name('label')->where('cropTransformation', '[0-9]+')->middleware('can:L');
        Route::get('/{cropTransformation}', 'show')->name('show')->where('cropTransformation', '[0-9]+')->middleware('can:L');
        Route::get('/{cropTransformation}/edit', 'edit')->name('edit')->where('cropTransformation', '[0-9]+')->middleware('can:M');
        Route::put('/{cropTransformation}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{cropTransformation}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Catalogue des cultures (espèces & variétés)
    Route::prefix('cultures/catalogue')->name('crop-catalogue.')->controller(CropCatalogueController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/import', 'importForm')->name('import')->middleware('can:M');
        Route::post('/import', 'importStore')->name('import.store')->middleware('can:M');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropCatalogue}', 'show')->name('show')->where('cropCatalogue', '[0-9]+')->middleware('can:L');
        Route::get('/{cropCatalogue}/edit', 'edit')->name('edit')->where('cropCatalogue', '[0-9]+')->middleware('can:M');
        Route::put('/{cropCatalogue}', 'update')->name('update')->middleware('can:M');
        Route::post('/{cropCatalogue}/varieties', 'storeVariety')->name('varieties.store')->middleware('can:C');
        Route::put('/varieties/{variety}', 'updateVariety')->name('varieties.update')->middleware('can:M');
        Route::delete('/varieties/{variety}', 'destroyVariety')->name('varieties.destroy')->middleware('can:S');
    });

    // Campagnes agricoles
    Route::prefix('cultures/campaigns')->name('crop-campaigns.')->controller(CropCampaignController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropCampaign}', 'show')->name('show')->where('cropCampaign', '[0-9]+')->middleware('can:L');
        Route::get('/{cropCampaign}/edit', 'edit')->name('edit')->where('cropCampaign', '[0-9]+')->middleware('can:M');
        Route::put('/{cropCampaign}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{cropCampaign}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Recettes de transformation
    Route::prefix('cultures/recipes')->name('crop-recipes.')->controller(CropRecipeController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/import', 'importForm')->name('import')->middleware('can:M');
        Route::post('/import', 'importStore')->name('import.store')->middleware('can:M');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropRecipe}', 'show')->name('show')->where('cropRecipe', '[0-9]+')->middleware('can:L');
        Route::get('/{cropRecipe}/edit', 'edit')->name('edit')->where('cropRecipe', '[0-9]+')->middleware('can:M');
        Route::put('/{cropRecipe}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{cropRecipe}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Protocoles / itinéraires techniques par culture
    Route::prefix('cultures/protocols')->name('crop-protocols.')->controller(CropProtocolController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropProtocol}', 'show')->name('show')->where('cropProtocol', '[0-9]+')->middleware('can:L');
        Route::get('/{cropProtocol}/edit', 'edit')->name('edit')->where('cropProtocol', '[0-9]+')->middleware('can:M');
        Route::put('/{cropProtocol}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{cropProtocol}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Météo & pluviométrie
    Route::prefix('cultures/weather')->name('weather.')->controller(WeatherController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::post('/fetch', 'fetchNow')->name('fetch')->middleware('can:C');
        Route::get('/{weather}/edit', 'edit')->name('edit')->where('weather', '[0-9]+')->middleware('can:M');
        Route::put('/{weather}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{weather}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Calendrier cultural
    Route::get('/cultures/calendar', [CultureDashboardController::class, 'calendar'])->name('cultures.calendar')->middleware('can:L');

    // Événements calendaires libres
    Route::prefix('cultures/calendar-events')->name('crop-calendar-events.')->controller(CropCalendarEventController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{cropCalendarEvent}/edit', 'edit')->name('edit')->where('cropCalendarEvent', '[0-9]+')->middleware('can:M');
        Route::put('/{cropCalendarEvent}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{cropCalendarEvent}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Rapports production végétale
    Route::prefix('cultures/reports')->name('crop-reports.')->controller(CropReportController::class)->middleware('can:L')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/yield', 'yield')->name('yield');
        Route::get('/yield/pdf', 'yieldPdf')->name('yield.pdf');
        Route::get('/inputs', 'inputs')->name('inputs');
        Route::get('/inputs/pdf', 'inputsPdf')->name('inputs.pdf');
        Route::get('/campaigns', 'campaigns')->name('campaigns');
        Route::get('/campaigns/pdf', 'campaignsPdf')->name('campaigns.pdf');
        Route::get('/transformations', 'transformations')->name('transformations');
        Route::get('/transformations/pdf', 'transformationsPdf')->name('transformations.pdf');
    });

    // ─── COUVOIR & INCUBATION ───
    Route::prefix('incubations')->name('incubations.')->controller(IncubationController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        // Création & édition se font via la modale et les actions mirage/éclosion
        // de la vue index : pas de méthodes create()/edit() dédiées au contrôleur.
        Route::post('/store', 'store')->name('store')->middleware('can:C');
        Route::post('/{incubation}/mirage', 'recordMirage')->name('mirage')->middleware('can:M');
        Route::post('/{incubation}/hatch', 'recordHatch')->name('hatch')->middleware('can:M');
        Route::delete('/{incubation}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // Dispatch poussins post-éclosion
    Route::get('/incubations/{incubation}/dispatch', [ChickDispatchController::class, 'show'])->name('chick-dispatches.show')->middleware('can:L');
    Route::post('/incubations/{incubation}/dispatch', [ChickDispatchController::class, 'store'])->name('chick-dispatches.store')->middleware('can:C');

    Route::prefix('incubators-devices')->name('incubators.')->controller(IncubatorController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{incubator}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{incubator}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{incubator}', 'destroy')->name('destroy')->middleware('can:S');
        Route::post('/{incubator}/maintenance', 'addMaintenance')->name('maintenance.store')->middleware('can:C');
    });

    // ─── PRODUCTION ŒUFS ───
    Route::prefix('egg-production')->name('egg-productions.')->controller(EggProductionController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/store', 'store')->name('store')->middleware('can:C');
        // Feuille de tournée multi-lots (déclarée AVANT les routes {eggProduction})
        Route::get('/tour', 'tour')->name('tour')->middleware('can:C');
        Route::post('/tour', 'tourStore')->name('tour.store')->middleware('can:C');
        Route::get('/{eggProduction}/tri', 'tri')->name('tri')->middleware('can:L');
        Route::get('/{eggProduction}/label', [TraceabilityController::class, 'eggLabel'])->name('label')->middleware('can:L');
        Route::get('/{eggProduction}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{eggProduction}', 'update')->name('update')->middleware('can:M');
        Route::put('/{eggProduction}/tri', 'updateTri')->name('update-tri')->middleware('can:M');
        Route::delete('/{eggProduction}', 'destroy')->name('destroy')->middleware('can:S');
    });

    Route::post('/egg-movements/store', [EggMovementController::class, 'store'])->name('egg-movements.store')->middleware('can:C');

    // ─── COLLECTE DE LAIT (laiterie caprine) ───
    Route::prefix('milk-productions')->name('milk-productions.')->controller(MilkProductionController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:production.L');
        Route::get('/create', 'create')->name('create')->middleware('can:production.C');
        Route::post('/', 'store')->name('store')->middleware('can:production.C');
        Route::get('/{milkProduction}/edit', 'edit')->name('edit')->middleware('can:production.M');
        Route::put('/{milkProduction}', 'update')->name('update')->middleware('can:production.M');
        Route::delete('/{milkProduction}', 'destroy')->name('destroy')->middleware('can:production.S');
    });

    // ─── SANTÉ & PROPHYLAXIE ───
    Route::prefix('health')->name('health.')->controller(HealthController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{health}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{health}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{health}', 'destroy')->name('destroy')->middleware('can:S');

        // Incidents sanitaires → HealthIncidentController dédié
        Route::get('/incidents', [\App\Http\Controllers\HealthIncidentController::class, 'index'])->name('incidents.index')->middleware('can:L');
        Route::get('/incidents/create', [\App\Http\Controllers\HealthIncidentController::class, 'index'])->name('incidents.create')->middleware('can:C');
        Route::get('/incidents/{incident}', [\App\Http\Controllers\HealthIncidentController::class, 'show'])->name('incidents.show')->where('incident', '[0-9]+')->middleware('can:L');
        Route::post('/incidents', [\App\Http\Controllers\HealthIncidentController::class, 'store'])->name('incidents.store')->middleware('can:C');
        Route::put('/incidents/{incident}/diagnose', [\App\Http\Controllers\HealthIncidentController::class, 'diagnose'])->name('incidents.diagnose')->middleware('can:M');
        Route::patch('/incidents/{incident}/resolve', [\App\Http\Controllers\HealthIncidentController::class, 'resolve'])->name('incidents.resolve')->middleware('can:M');
        Route::patch('/incidents/{incident}/close-fast', [\App\Http\Controllers\HealthIncidentController::class, 'closeFast'])->name('incidents.closeFast')->middleware('can:M');
        Route::patch('/incidents/{incident}/quarantine', [\App\Http\Controllers\HealthIncidentController::class, 'toggleQuarantine'])->name('incidents.quarantine')->middleware('can:M');
    });

    Route::middleware('can:L')->resource('daily-checks', DailyCheckController::class);

    // ─── PROTOCOLES ───
    Route::middleware(['auth', 'can:C'])->group(function () {
        Route::get('/protocols/export/{protocol}', [ProtocolController::class, 'export'])->name('protocols.export');
        Route::post('/protocols/import', [ProtocolController::class, 'import'])->name('protocols.import');
        Route::post('/protocols/{protocol}/duplicate', [ProtocolController::class, 'duplicate'])->name('protocols.duplicate');
        Route::post('/protocols/{protocol}/add-step', [ProtocolController::class, 'addStep'])->name('protocols.addStep');
        Route::delete('/step/{step}', [ProtocolController::class, 'destroyStep'])->name('protocols.destroyStep');
    });

    Route::middleware(['auth', 'can:L'])->resource('protocols', ProtocolController::class);

    // ─── API INDEXEDDB (données du mode terrain) ───
    Route::middleware(['force.json', 'auth'])->prefix('api/offline')->name('offline.')->group(function () {
        // Controllers optimisés (colonnes limitées, sync incrémentale)
        Route::get('/batches', [BatchController::class, 'getOfflineBatches'])->name('batches');
        Route::get('/buildings', [BuildingController::class, 'getOfflineBuildings'])->name('buildings');

        // Closures pour les référentiels simples (colonnes déjà limitées ou petites tables)
        Route::get('/employees', fn() => \App\Models\Employee::active()
            ->get(['id', 'first_name', 'last_name', 'job_title as position']))->name('employees');
        Route::get('/providers', fn() => \App\Models\Provider::where('status', 'Actif')
            ->get(['id', 'name', 'phone']))->name('providers');
        Route::get('/protocols', fn() => \App\Models\Protocol::all(['id', 'name', 'type']))->name('protocols');
        Route::get('/norms', fn() => \App\Models\ProductionNorm::select('id', 'model_name', 'batch_type')
            ->distinct()->get())->name('norms');
        Route::get('/stocks', fn() => \App\Models\Stock::all(['id', 'item_name', 'current_quantity', 'category', 'unit']))->name('stocks');
        Route::get('/clients', [ClientController::class, 'getOfflineClients'])->name('clients');
    });

    // ─── SYNCHRONISATION OFFLINE → SERVEUR (porte LEGACY, session web) ───
    // Endpoints appelés par sync-engine.js quand le réseau revient.
    // Depuis la fusion A2, la logique vit dans App\Services\Sync\SyncService ;
    // cette passerelle ne fait que traduire l'ancien contrat HTTP.
    // @deprecated → basculera sur /api/v1/sync/push avec la PWA.
    Route::middleware(['force.json', 'auth'])->prefix('api/sync')->name('sync.')->controller(SyncGatewayController::class)->group(function () {
        Route::post('/reconcile', 'reconcile')->name('reconcile');
        Route::post('/daily-checks', 'reconcileDailyCheck')->name('daily_checks');
        Route::post('/egg-collections', 'reconcileEggCollection')->name('egg_collections');
        Route::post('/stock-movements', 'reconcileStockMovement')->name('stock_movements');
        Route::post('/sales', 'reconcileSale')->name('sales');
        Route::post('/expenses', 'reconcileExpense')->name('expenses');
    });

    // ─── ACHATS ALIMENT ───
    Route::prefix('feed-purchases')->name('feed-purchases.')->controller(FeedPurchaseController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{feed_purchase}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{feed_purchase}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{feed_purchase}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── RAPPORTS ───
    Route::prefix('reports')->name('reports.')->controller(ReportController::class)->middleware('can:L')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/technical', 'technicalPerformance')->name('technical');
        Route::get('/technical/pdf', 'technicalPerformancePdf')->name('technical.pdf');
        Route::get('/profit-loss', 'profitLoss')->name('profit_loss');
        Route::get('/profit-loss/pdf', 'profitLossPdf')->name('profit_loss.pdf');
        Route::get('/nursery', 'nurseryReport')->name('nursery');
        Route::get('/nursery/pdf', 'nurseryReportPdf')->name('nursery.pdf');
        Route::get('/health-incidents', 'healthIncidentsReport')->name('health_incidents');
        Route::get('/health-finance', 'healthFinancialReport')->name('health_finance');
        Route::get('/health-finance/pdf', 'healthFinancialReportPdf')->name('health_finance.pdf');
        Route::get('/monthly', 'monthlyExpenses')->name('monthly');
        Route::get('/monthly/pdf', 'monthlyExpensesPdf')->name('monthly.pdf');
        Route::get('/gmq', 'gmqReport')->name('gmq');
        Route::get('/gmq/pdf', 'gmqReportPdf')->name('gmq.pdf');
        Route::get('/aquaculture', 'aquacultureReport')->name('aquaculture');
        Route::get('/aquaculture/pdf', 'aquacultureReportPdf')->name('aquaculture.pdf');
    });

    // ──────────────────────────────────────────────
    // VENTES & FACTURATION
    // ──────────────────────────────────────────────

    // ─── CLIENTS ───
    Route::prefix('clients')->name('clients.')->controller(ClientController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{client}/statement', 'statement')->name('statement')->middleware('can:L');
        Route::get('/{client}/statement/pdf', 'statementPdf')->name('statement.pdf')->middleware('can:L');
        Route::get('/{client}', 'show')->name('show')->middleware('can:L');
        Route::get('/{client}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{client}', 'update')->name('update')->middleware('can:M');
        Route::delete('/{client}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── VENTES & BONS DE LIVRAISON ───
    // ─── HUB COMMERCE (point d'entrée unifié du module) ───
    Route::get('/commerce', [\App\Http\Controllers\CommerceController::class, 'index'])->name('commerce.index')->middleware('can:L');

    // ─── POINT DE VENTE (POS / caisse, module: commerce) ───
    Route::prefix('pos')->name('pos.')->controller(\App\Http\Controllers\PosController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:C');
        Route::post('/checkout', 'checkout')->name('checkout')->middleware('can:C');
        Route::post('/clients', 'storeClient')->name('clients.store'); // autorisation gérée dans le contrôleur (JSON 403)
        Route::get('/receipt/{sale}', 'receipt')->name('receipt')->middleware('can:C');
        Route::post('/encash/{sale}', 'encash')->name('encash')->middleware('can:C');
        Route::get('/report', 'report')->name('report')->middleware('can:L');
    });

    // ─── SESSIONS DE CAISSE (ouverture/clôture + comptage, module: commerce) ───
    Route::prefix('cash-register')->name('cash-register.')->controller(\App\Http\Controllers\CashRegisterController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::post('/open', 'open')->name('open')->middleware('can:C');
        Route::post('/{session}/close', 'close')->name('close')->middleware('can:C');
    });

    // ─── JOURNAL DES AVOIRS (retours, module: commerce) ───
    Route::prefix('returns')->name('returns.')->controller(\App\Http\Controllers\SaleReturnController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/csv', 'exportCsv')->name('csv')->middleware('can:L');
        Route::get('/pdf', 'exportPdf')->name('pdf')->middleware('can:L');
    });

    // Catalogue d'articles vendables (commerce).
    Route::prefix('products')->name('products.')->controller(\App\Http\Controllers\ProductController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:commerce.L');
        Route::get('/create', 'create')->name('create')->middleware('can:commerce.C');
        Route::post('/', 'store')->name('store')->middleware('can:commerce.C');
        Route::get('/{product}/edit', 'edit')->name('edit')->middleware('can:commerce.M');
        Route::put('/{product}', 'update')->name('update')->middleware('can:commerce.M');
        Route::delete('/{product}', 'destroy')->name('destroy')->middleware('can:commerce.S');
    });

    Route::prefix('sales')->name('sales.')->controller(SaleController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');

        // Recouvrement : encours en retard + relances de paiement.
        Route::get('/receivables', [\App\Http\Controllers\ReceivablesController::class, 'index'])->name('receivables')->middleware('can:L');
        Route::post('/receivables/{sale}/remind', [\App\Http\Controllers\ReceivablesController::class, 'remind'])->name('receivables.remind')->where('sale', '[0-9]+')->middleware('can:M');

        // Groupes de prix (tarifs) — gestion + suggestion de prix pour le formulaire.
        Route::get('/price-lists', [\App\Http\Controllers\SalePriceListController::class, 'index'])->name('price-lists')->middleware('can:M');
        Route::post('/price-lists', [\App\Http\Controllers\SalePriceListController::class, 'store'])->name('price-lists.store')->middleware('can:M');
        Route::put('/price-lists/{priceList}', [\App\Http\Controllers\SalePriceListController::class, 'updateItems'])->name('price-lists.update')->middleware('can:M');
        Route::get('/suggest-price', [\App\Http\Controllers\SalePriceListController::class, 'suggest'])->name('suggest-price')->middleware('can:L');
        Route::get('/catalog-prices', [\App\Http\Controllers\SalePriceListController::class, 'catalogPrices'])->name('catalog-prices')->middleware('can:L');

        Route::get('/{sale}', 'show')->name('show')->middleware('can:L');
        Route::get('/{sale}/print', 'print')->name('print')->middleware('can:L');
        Route::put('/{sale}/validate', 'validate')->name('validate')->middleware('can:M');
        Route::put('/{sale}/deliver', 'deliver')->name('deliver')->middleware('can:M');
        Route::put('/{sale}/cancel', 'cancel')->name('cancel')->middleware('can:S');
        // Retours client & remboursements (avoirs).
        Route::get('/{sale}/return', [\App\Http\Controllers\SaleReturnController::class, 'create'])->name('return.create')->middleware('can:M');
        Route::post('/{sale}/return', [\App\Http\Controllers\SaleReturnController::class, 'store'])->name('return.store')->middleware('can:M');
    });

    // ─── PAIEMENTS / ENCAISSEMENTS ───
    Route::prefix('payments')->name('payments.')->controller(PaymentController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::post('/', 'store')->name('store')->middleware('can:C');
    });

    // ─── REGISTRE DES DÉPENSES (module: depenses) ───
    // ─── TRÉSORERIE (comptes Caisse / Mobile Money / Banque, module: depenses) ───
    Route::prefix('treasury')->name('treasury.')->controller(\App\Http\Controllers\TreasuryController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/report', 'report')->name('report')->middleware('can:L');
        Route::get('/report/csv', 'reportCsv')->name('report.csv')->middleware('can:L');
        Route::get('/report/pdf', 'reportPdf')->name('report.pdf')->middleware('can:L');
        Route::post('/account', 'storeAccount')->name('account.store')->middleware('can:C');
        Route::post('/mapping', 'updateMapping')->name('mapping')->middleware('can:C');
        Route::post('/transfer', 'transfer')->name('transfer')->middleware('can:C');
        Route::get('/{account}', 'show')->name('show')->middleware('can:L');
        Route::post('/{account}/movement', 'storeMovement')->name('movement')->middleware('can:C');
    });

    // ─── HUB FINANCE (point d'entrée unifié du module depenses) ───
    Route::get('/finance', [\App\Http\Controllers\FinanceController::class, 'index'])->name('finance.index')->middleware('can:L');

    // ─── HUBS DE MODULES (points d'entrée unifiés : KPIs + accès groupés) ───
    Route::get('/elevage', [\App\Http\Controllers\ElevageHubController::class, 'index'])->name('elevage.index')->middleware('can:L');
    Route::get('/productions', [\App\Http\Controllers\ProductionHubController::class, 'index'])->name('productions.index')->middleware('can:L');
    Route::get('/annuaire', [\App\Http\Controllers\AnnuaireHubController::class, 'index'])->name('annuaire.index')->middleware('can:L');
    Route::get('/logistique', [\App\Http\Controllers\LogistiqueHubController::class, 'index'])->name('logistique.index')->middleware('can:L');

    // ─── ACHATS FOURNISSEURS & DETTES (compte à payer, module: depenses) ───
    Route::prefix('purchases')->name('purchases.')->controller(\App\Http\Controllers\SupplierInvoiceController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/provider/{provider}/statement', 'statement')->name('statement')->middleware('can:L');
        Route::get('/provider/{provider}/statement/pdf', 'statementPdf')->name('statement.pdf')->middleware('can:L');
        Route::get('/{invoice}', 'show')->name('show')->middleware('can:L');
        Route::put('/{invoice}/validate', 'validateInvoice')->name('validate')->middleware('can:M');
        Route::put('/{invoice}/cancel', 'cancel')->name('cancel')->middleware('can:M');
        Route::post('/{invoice}/pay', 'pay')->name('pay')->middleware('can:C');
    });

    Route::prefix('expenses')->name('expenses.')->controller(ExpenseController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{expense}', 'show')->name('show')->middleware('can:L');
        Route::get('/{expense}/justificatif', 'downloadJustificatif')->name('justificatif')->middleware('can:L');
        Route::get('/{expense}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{expense}', 'update')->name('update')->middleware('can:M');
        Route::put('/{expense}/approve', 'approve')->name('approve')->middleware('can:M');
        Route::put('/{expense}/cancel', 'cancel')->name('cancel')->middleware('can:M');
        Route::delete('/{expense}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── SUIVI BUDGÉTAIRE (module: depenses — contrôle d'accès dans le contrôleur) ───
    Route::prefix('budgets')->name('budgets.')->controller(\App\Http\Controllers\BudgetController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/export', 'export')->name('export');
        Route::get('/export-pdf', 'exportPdf')->name('export-pdf');
        Route::post('/', 'store')->name('store');
        Route::post('/copy-previous', 'copyPrevious')->name('copy-previous');
    });

    // ──────────────────────────────────────────────
    // LOGISTIQUE & ANTI-FRAUDE (Three-Way Matching)
    // ──────────────────────────────────────────────

    // ─── EXPÉDITIONS & RÉCEPTIONS ───
    Route::prefix('dispatches')->name('dispatches.')->controller(DispatchController::class)->group(function () {
        // Rapports d'écart (AVANT les routes à paramètre pour éviter le conflit {dispatch})
        Route::get('/reports/discrepancies', 'discrepancies')->name('discrepancies')->middleware('can:L');
        Route::put('/reports/{report}/resolve', 'resolveDiscrepancy')->name('discrepancy.resolve')->middleware('can:S');

        // CRUD Expéditions
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{dispatch}/label', [TraceabilityController::class, 'dispatchLabel'])->name('label')->where('dispatch', '[0-9]+')->middleware('can:L');
        Route::get('/{dispatch}', 'show')->name('show')->middleware('can:L');

        // Réception (saisie par le magasin). L'accès est gouverné DANS le
        // contrôleur (canReceive) : le récepteur DÉSIGNÉ à l'expédition peut
        // valider même sans logistique.M, et un responsable logistique.M reste
        // habilité en secours. L'anti-fraude expéditeur ≠ récepteur est appliquée
        // dans ValidateReception.
        Route::get('/{dispatch}/reception', 'showReceptionForm')->name('reception.create');
        Route::post('/{dispatch}/reception', 'storeReception')->name('reception.store');
    });

    // ──────────────────────────────────────────────
    // EAU & ÉNERGIE
    // ──────────────────────────────────────────────

    // ─── RESSOURCES EAU & ÉNERGIE ───
    Route::prefix('utilities')->name('utilities.')->controller(UtilityController::class)->group(function () {
        Route::get('/dashboard', 'dashboard')->name('dashboard')->middleware('can:L');

        // Sources d'eau
        Route::get('/water-sources', 'waterSources')->name('water.sources')->middleware('can:L');
        Route::post('/water-sources', 'storeWaterSource')->name('water.sources.store')->middleware('can:C');
        Route::get('/water-sources/{source}/edit', 'editWaterSource')->name('water.sources.edit')->middleware('can:M');
        Route::put('/water-sources/{source}', 'updateWaterSource')->name('water.sources.update')->middleware('can:M');
        Route::delete('/water-sources/{source}', 'destroyWaterSource')->name('water.sources.destroy')->middleware('can:S');
        Route::post('/water-readings', 'storeWaterReading')->name('water.readings.store')->middleware('can:C');

        // Sources d'énergie
        Route::get('/energy-sources', 'energySources')->name('energy.sources')->middleware('can:L');
        Route::post('/energy-sources', 'storeEnergySource')->name('energy.sources.store')->middleware('can:C');
        Route::get('/energy-sources/{source}/edit', 'editEnergySource')->name('energy.sources.edit')->middleware('can:M');
        Route::put('/energy-sources/{source}', 'updateEnergySource')->name('energy.sources.update')->middleware('can:M');
        Route::delete('/energy-sources/{source}', 'destroyEnergySource')->name('energy.sources.destroy')->middleware('can:S');
        Route::post('/energy-readings', 'storeEnergyReading')->name('energy.readings.store')->middleware('can:C');
        Route::put('/energy-sources/{source}/maintenance', 'recordMaintenance')->name('energy.maintenance')->middleware('can:M');
        Route::get('/energy-sources/{source}/logs', 'assetLogs')->name('energy.logs')->middleware('can:L');

        // Achats carburant
        Route::get('/fuel-purchases', 'fuelPurchases')->name('fuel.index')->middleware('can:L');
        Route::post('/fuel-purchases', 'storeFuelPurchase')->name('fuel.store')->middleware('can:C');
        Route::get('/fuel-purchases/{purchase}/edit', 'editFuelPurchase')->name('fuel.edit')->middleware('can:M');
        Route::put('/fuel-purchases/{purchase}', 'updateFuelPurchase')->name('fuel.update')->middleware('can:M');
        Route::delete('/fuel-purchases/{purchase}', 'destroyFuelPurchase')->name('fuel.destroy')->middleware('can:S');
    });

    // ─── NOTIFICATIONS WHATSAPP ───
    Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->group(function () {
        Route::get('/preferences', 'preferences')->name('preferences')->middleware('can:L');
        Route::put('/preferences', 'updatePreferences')->name('preferences.update')->middleware('can:L');
        Route::post('/test', 'sendTest')->name('test')->middleware('can:L');
        Route::post('/test-sms', 'sendTestSms')->name('test_sms')->middleware('can:L');
        Route::post('/test-mail', 'sendTestMail')->name('test_mail')->middleware('can:L');
        Route::get('/logs', 'logs')->name('logs')->middleware('can:S');
        // Journal d'audit (qui a modifié quoi) — lecture seule, admin.
        Route::get('/audit', [\App\Http\Controllers\AuditLogController::class, 'index'])->name('audit');
        // Modèles de messages éditables (admin).
        Route::get('/templates', [NotificationTemplateController::class, 'index'])->name('templates')->middleware('can:S');
        Route::put('/templates/{template}', [NotificationTemplateController::class, 'update'])->name('templates.update')->middleware('can:S');
        Route::put('/templates/{template}/reset', [NotificationTemplateController::class, 'reset'])->name('templates.reset')->middleware('can:S');
        // Cloche in-app : gérer ses propres notifications (aucun droit module requis).
        Route::post('/read-all', 'markAllRead')->name('read-all');
        Route::get('/{id}/read', 'markRead')->name('read');
    });

    // ──────────────────────────────────────────────
    // PLANIFICATION DES BANDES
    // ──────────────────────────────────────────────

    Route::prefix('planning')->name('planning.')->controller(PlanningController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::get('/create', 'create')->name('create')->middleware('can:C');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/{plan}', 'show')->name('show')->middleware('can:L');
        Route::get('/{plan}/activate', 'activateForm')->name('activate')->middleware('can:M');
        Route::put('/{plan}/status', 'updateStatus')->name('status')->middleware('can:M');
        Route::delete('/{plan}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ──────────────────────────────────────────────
    // ABATTOIR & TRANSFORMATION
    // ──────────────────────────────────────────────

    Route::prefix('slaughter')->name('slaughter.')->controller(SlaughterController::class)->group(function () {
        Route::get('/dashboard', 'dashboard')->name('dashboard')->middleware('can:L');

        // Ordres d'abattage
        Route::get('/orders/create', 'createOrder')->name('orders.create')->middleware('can:C');
        Route::post('/orders', 'storeOrder')->name('orders.store')->middleware('can:C');
        Route::patch('/orders/{order}/cancel', 'cancelOrder')->name('orders.cancel')->middleware('can:M');

        // Blocage / libération qualité (RG-02/RG-03) — libération réservée
        // au niveau QUALITÉ (S), motif obligatoire dans les deux sens.
        Route::get('/orders/{order}/tracabilite', 'traceability')->name('orders.traceability')->middleware('can:L');
        Route::patch('/orders/{order}/block', 'blockOrder')->name('orders.block')->middleware('can:M');
        Route::patch('/orders/{order}/release', 'releaseOrder')->name('orders.release')->middleware('can:S');

        // Réception du vif (CCP 1) — registre IMMUABLE : pas d'edit/update/destroy.
        Route::get('/receptions', [\App\Http\Controllers\SlaughterReceptionController::class, 'index'])->name('receptions.index')->middleware('can:L');
        Route::get('/receptions/create', [\App\Http\Controllers\SlaughterReceptionController::class, 'create'])->name('receptions.create')->middleware('can:C');
        Route::post('/receptions', [\App\Http\Controllers\SlaughterReceptionController::class, 'store'])->name('receptions.store')->middleware('can:C');

        // Registres HACCP (CCP, températures, nettoyage) — INSERT-ONLY (RG-06).
        Route::get('/registres', [\App\Http\Controllers\HaccpRegisterController::class, 'registersHub'])->name('registres.index')->middleware('can:L');
        Route::get('/registres/ccp', [\App\Http\Controllers\HaccpRegisterController::class, 'ccpIndex'])->name('registres.ccp')->middleware('can:L');
        Route::get('/registres/ccp/create', [\App\Http\Controllers\HaccpRegisterController::class, 'ccpCreate'])->name('registres.ccp.create')->middleware('can:C');
        Route::post('/registres/ccp', [\App\Http\Controllers\HaccpRegisterController::class, 'ccpStore'])->name('registres.ccp.store')->middleware('can:C');
        Route::get('/registres/temperatures', [\App\Http\Controllers\HaccpRegisterController::class, 'temperatureIndex'])->name('registres.temperatures')->middleware('can:L');
        Route::post('/registres/temperatures', [\App\Http\Controllers\HaccpRegisterController::class, 'temperatureStore'])->name('registres.temperatures.store')->middleware('can:C');
        Route::get('/registres/nettoyage', [\App\Http\Controllers\HaccpRegisterController::class, 'cleaningIndex'])->name('registres.nettoyage')->middleware('can:L');
        Route::post('/registres/nettoyage', [\App\Http\Controllers\HaccpRegisterController::class, 'cleaningStore'])->name('registres.nettoyage.store')->middleware('can:C');
        Route::get('/registres/sous-produits', [\App\Http\Controllers\HaccpRegisterController::class, 'byproductsIndex'])->name('registres.sous_produits')->middleware('can:L');
        Route::post('/registres/sous-produits', [\App\Http\Controllers\HaccpRegisterController::class, 'byproductsStore'])->name('registres.sous_produits.store')->middleware('can:C');
        Route::get('/registres/export', [\App\Http\Controllers\HaccpRegisterController::class, 'export'])->name('registres.export')->middleware('can:L');

        // Exécution abattage
        Route::get('/orders/{order}/execute', 'showExecuteForm')->name('execute.form')->middleware('can:M');
        Route::post('/orders/{order}/execute', 'executeSlaughter')->name('execute.store')->middleware('can:M');

        // Découpe
        Route::get('/orders/{order}/cutting', 'showCuttingForm')->name('cutting.form')->middleware('can:C');
        Route::post('/orders/{order}/cutting', 'storeCutting')->name('cutting.store')->middleware('can:C');

        // Transformation
        Route::get('/transform', 'showTransformForm')->name('transform.form')->middleware('can:C');
        Route::post('/transform', 'storeTransformation')->name('transform.store')->middleware('can:C');
        Route::patch('/transform/{transformation}/complete', 'completeTransformation')->name('transform.complete')->middleware('can:M');

        // Stock produits finis
        Route::get('/finished-products', 'finishedProducts')->name('finished')->middleware('can:L');
        Route::put('/finished-products/{product}', 'updateProduct')->name('finished.update')->middleware('can:M');
        Route::post('/finished-products/{product}/transfer', 'transferToStock')->name('finished.transfer')->middleware('can:M');
        Route::post('/finished-products/{product}/adjust', 'adjustQuantity')->name('finished.adjust')->middleware('can:M');
        Route::post('/finished-products/{product}/dispose', 'dispose')->name('finished.dispose')->middleware('can:M');
    });

    // ─── EMPLOYÉS & FOURNISSEURS ───
    // S-17 corrigé : Sortis du bloc can:S.
    // Les controllers gèrent les permissions fines en interne :
    //   EmployeeController::index() → Gate::denies('L')
    //   EmployeeController::store() → Form Request authorize() → Gate::allows('C')
    //   EmployeeController::destroy() → Gate::denies('S')
    Route::resource('employees', EmployeeController::class);
    Route::put('/employees/{id}/status', [EmployeeController::class, 'updateStatus'])->name('employees.status');

    // ─── ESPACE EMPLOYÉ : gestion du compte de connexion (réservé admin.S) ───
    Route::controller(EmployeeAccessController::class)->group(function () {
        Route::post('/employees/{employee}/access', 'store')->name('employees.access.store');
        Route::put('/employees/{employee}/access', 'update')->name('employees.access.update');
        Route::put('/employees/{employee}/access/password', 'resetPassword')->name('employees.access.password');
    });

    Route::resource('providers', ProviderController::class);
    // S-18 corrigé : une seule route PUT (sémantiquement correct pour changement d'état)
    Route::put('/providers/{provider}/blacklist', [ProviderController::class, 'blacklist'])->name('providers.blacklist');

    // ─── ADMINISTRATION (S requis) ───
    Route::middleware('can:S')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'store', 'destroy']);
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.update_role');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle_active');
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.reset_password');
        Route::post('/roles', [UserController::class, 'storeRole'])->name('roles.store');
        Route::delete('/roles/{role}', [UserController::class, 'destroyRole'])->name('roles.destroy');
        Route::post('/roles/module-matrix', [UserController::class, 'updateModuleMatrix'])->name('roles.update_module_matrix');

        // Référentiel des NORMES zootechniques : rattaché à l'ÉLEVAGE (il est
        // consulté depuis les lots, « Référentiel Normes »), donc préfixe/nom
        // batches.norms.* → fil d'Ariane « Lots › Normes » et retour vers les
        // lots, et NON vers l'admin. Gestion réservée aux admins (can:S, hérité).
        Route::prefix('batches/norms')->name('batches.norms.')->controller(ProductionNormController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/import', 'import')->name('import');
            Route::post('/', 'store')->name('store');
            Route::put('/{norm}', 'update')->name('update');
            Route::delete('/{norm}', 'destroy')->name('destroy');
        });

        // B-19 corrigé : ProductionNormController (pas NormController)
        Route::prefix('admin')->name('admin.')->group(function () {
            // Gestion des espèces (multiespèces) — relève bien de l'administration.
            Route::get('/species', [SpeciesController::class, 'index'])->name('species.index');
            Route::patch('/species/{species}/toggle', [SpeciesController::class, 'toggle'])->name('species.toggle');
            Route::delete('/species/{species}', [SpeciesController::class, 'destroy'])->name('species.destroy');
        });

        // API espèces — endpoint JSON pour sélecteur dynamique
        Route::get('/api/species/{species}/production-types', [SpeciesController::class, 'productionTypesForSpecies'])->name('api.species.production-types');
    });

    // ─── PLANNING TÂCHES OPÉRATIONNELLES ───
    Route::prefix('tasks')->name('tasks.')->controller(TaskController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        // Routes littérales EN PREMIER (avant {task})
        Route::post('/generate', 'generate')->name('generate')->middleware('can:M');
        Route::post('/store', 'storeManual')->name('store')->middleware('can:C');
        Route::get('/templates', 'templates')->name('templates')->middleware('can:M');
        Route::post('/templates', 'storeTemplate')->name('templates.store')->middleware('can:M');
        // Routes avec template paramétrisé
        Route::get('/templates/{template}/edit', 'editTemplate')->name('templates.edit')->middleware('can:M');
        Route::put('/templates/{template}', 'updateTemplate')->name('templates.update')->middleware('can:M');
        Route::post('/templates/{template}/toggle', 'toggleTemplate')->name('templates.toggle')->middleware('can:M');
        Route::delete('/templates/{template}', 'destroyTemplate')->name('templates.destroy')->middleware('can:S');
        // Routes avec task paramétrisé EN DERNIER
        Route::get('/{task}/edit', 'edit')->name('edit')->middleware('can:M');
        Route::put('/{task}', 'update')->name('update')->middleware('can:M');
        Route::post('/{task}/complete', 'complete')->name('complete')->middleware('can:M');
        Route::post('/{task}/assign', 'assign')->name('assign')->middleware('can:M');
        Route::delete('/{task}', 'destroy')->name('destroy')->middleware('can:S');
    });

    // ─── PAIE & CONGÉS (RH) ───
    // ─── POINTAGE DE PRÉSENCE (RH léger, module: annuaire) ───
    Route::prefix('attendance')->name('attendance.')->controller(\App\Http\Controllers\AttendanceController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::post('/', 'store')->name('store')->middleware('can:C');
        Route::get('/report', 'report')->name('report')->middleware('can:L');
        Route::get('/report/csv', 'exportCsv')->name('report.csv')->middleware('can:L');
        Route::get('/report/pdf', 'exportPdf')->name('report.pdf')->middleware('can:L');
    });

    Route::prefix('payroll')->name('payroll.')->controller(PayrollController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:L');
        Route::post('/period', 'createPeriod')->name('create-period')->middleware('can:C');
        Route::get('/{period}', 'show')->name('show')->middleware('can:L');
        Route::post('/{period}/generate', 'generate')->name('generate')->middleware('can:M');
        Route::post('/{period}/validate', 'validatePeriod')->name('validate')->middleware('can:S');
        Route::post('/payslip/{payslip}/line', 'addLine')->name('add-line')->middleware('can:M');
        Route::post('/payslip/{payslip}/overtime', 'recordOvertime')->name('overtime')->middleware('can:M');
        Route::delete('/line/{line}', 'removeLine')->name('remove-line')->middleware('can:M');
        Route::post('/payslip/{payslip}/pay', 'markPaid')->name('mark-paid')->middleware('can:M');
        // Congés
        Route::get('/leaves/manage', 'leaves')->name('leaves')->middleware('can:L');
        Route::post('/leaves', 'storeLeave')->name('leaves.store')->middleware('can:C');
        Route::post('/leaves/{leave}/approve', 'approveLeave')->name('leaves.approve')->middleware('can:S');
        Route::post('/leaves/{leave}/reject', 'rejectLeave')->name('leaves.reject')->middleware('can:S');
        Route::post('/leaves/{leave}/delegate', 'delegateLeaveTasks')->name('leaves.delegate')->middleware('auth');
        Route::post('/leaves/{leave}/end', 'endLeave')->name('leaves.end')->middleware('can:M');
        // Impression & Historique
        Route::get('/payslip/{payslip}/print', 'printPayslip')->name('print')->middleware('can:L');
        Route::get('/employee/{employee}/history', 'employeeHistory')->name('employee-history')->middleware('can:L');
    });

    // ─── PARAMÈTRES SYSTÈME ───
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index')->middleware('can:S');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update')->middleware('can:S');
    Route::get('/settings/logs', [SettingsController::class, 'logs'])->name('settings.logs')->middleware('can:S');

    // ─── LICENCE / ABONNEMENT ───
    // edit : accessible à tout utilisateur connecté (écran de renouvellement) ;
    // update : activation réservée à l'administrateur (contrôle dans le contrôleur).
    Route::get('/license', [\App\Http\Controllers\LicenseController::class, 'edit'])->name('license.edit');
    Route::put('/license', [\App\Http\Controllers\LicenseController::class, 'update'])->name('license.update');

    // ─── SAUVEGARDES (admin) ───
    Route::get('/backups', [\App\Http\Controllers\BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups/run', [\App\Http\Controllers\BackupController::class, 'run'])->name('backups.run');
    Route::get('/backups/download/{name}', [\App\Http\Controllers\BackupController::class, 'download'])->name('backups.download');

    // ─── MULTI-FERME / MULTI-SITE ───
    Route::prefix('farms')->name('farms.')->controller(FarmController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:S');
        Route::post('/', 'store')->name('store')->middleware('can:S');
        Route::put('/{farm}', 'update')->name('update')->middleware('can:S');
        Route::post('/switch', 'switchFarm')->name('switch');
        Route::post('/{farm}/users', 'manageUsers')->name('users')->middleware('can:S');
    });

    // ─── CORBEILLE ───
    Route::controller(TrashController::class)->group(function () {
        Route::get('/trash', 'index')->name('trash.index')->middleware('can:L');
        Route::post('/trash/restore/{type}/{id}', 'restore')->name('trash.restore')->middleware('can:M');
        Route::delete('/trash/force-delete/{type}/{id}', 'forceDelete')->name('trash.forceDelete')->middleware('can:S');
        Route::delete('/trash/clear-all', 'clearAll')->name('trash.clearAll')->middleware('can:S');
    });
});

require __DIR__ . '/auth.php';
