<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Application mobile (opérations terrain, offline-first)
|--------------------------------------------------------------------------
| Authentification par token Sanctum (POST /api/v1/auth/login avec
| email + password + device_name, puis header Authorization: Bearer …).
| Les permissions L/C/M/S (matrice Modules × Rôles) s'appliquent comme sur
| le web. Le middleware farm.api fixe la ferme courante (en-tête X-Farm-Id
| optionnel, vérifié contre farm_user) : FarmScope borne lectures ET
| écritures à cette ferme — étanchéité multi-sites.
|
| Écritures terrain : UNE porte unique — POST /sync/push (opérations
| idempotentes à uuid, statut par opération). Cf. docs/mobile/phase-0-spec.md.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Sonde publique (connectivité + CORS + version) — cf. déploiement staging.
    Route::get('/health', \App\Http\Controllers\Api\HealthController::class)->name('health');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    // ── Ingestion IoT (exigence 3 pré-MEP) ──────────────────────────────
    // Endpoint générique découplé : clé d'API (X-Api-Key), contrat strict,
    // écrêtage anti-spam, écriture en zone TAMPON (telemetry_logs) puis
    // association au lot par le worker telemetry:process. Le throttle HTTP
    // borne en plus un capteur fou (120 req/min max, avant même l'écrêtage).
    Route::post('/telemetry/temperature', [\App\Http\Controllers\Api\TelemetryController::class, 'storeTemperature'])
        ->middleware('throttle:120,1')
        ->name('telemetry.temperature');

    Route::middleware(['auth:sanctum', 'farm.api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // Appareils connectés (un token Sanctum = un device) : liste +
        // révocation à distance (téléphone perdu). L'appareil courant se
        // déconnecte via /auth/logout, jamais par ici.
        Route::get('/devices', [\App\Http\Controllers\Api\DeviceController::class, 'index'])->name('devices.index');
        Route::delete('/devices/{deviceId}', [\App\Http\Controllers\Api\DeviceController::class, 'destroy'])
            ->whereNumber('deviceId')
            ->name('devices.destroy');

        // Tâches assignées (miroir mobile de « Mes tâches »).
        Route::get('/tasks', [\App\Http\Controllers\Api\TaskController::class, 'index'])->name('tasks.index');

        // Centre de notifications (miroir mobile de la cloche web).
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead'])->name('notifications.read_all');
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
        Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
        // Fiche enrichie : indicateurs + historique des pointages (courbe de poids).
        Route::get('/batches/{batch}/history', [BatchController::class, 'history'])->name('batches.history');

        // Journal des ventes du jour (consultation commerce.L).
        Route::get('/sales/today', [\App\Http\Controllers\Api\SaleJournalController::class, 'today'])->name('sales.today');

        // Journal de trésorerie du jour (consultation tresorerie.L).
        Route::get('/treasury/today', [\App\Http\Controllers\Api\TreasuryJournalController::class, 'today'])->name('treasury.today');

        // Journal de production Provenderie du jour (consultation provenderie.L).
        Route::get('/provenderie/today', [\App\Http\Controllers\Api\MillJournalController::class, 'today'])->name('provenderie.today');

        // Photos terrain (téléversées AVANT le push de l'op qui les référence).
        Route::post('/photos', [\App\Http\Controllers\Api\PhotoController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('photos.store');

        // Synchronisation offline (fusion audit A2 — remplace les anciennes
        // routes d'écriture éparses /daily-checks et /egg-productions).
        Route::post('/sync/push', [SyncController::class, 'push'])
            ->middleware('throttle:60,1')
            ->name('sync.push');
        Route::get('/sync/pull', [SyncController::class, 'pull'])
            ->middleware('throttle:60,1')
            ->name('sync.pull');
    });
});
