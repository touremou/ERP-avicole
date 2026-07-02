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
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'farm.api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
        Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');

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
