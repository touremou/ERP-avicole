<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\FieldOperationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Application mobile native (opérations terrain)
|--------------------------------------------------------------------------
| Authentification par token Sanctum (POST /api/v1/auth/login avec
| email + password + device_name, puis header Authorization: Bearer …).
| Les permissions L/C/M/S et la matrice Modules × Rôles s'appliquent
| comme sur le web (FormRequests et Gates partagés).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
        Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');

        Route::post('/daily-checks', [FieldOperationController::class, 'storeDailyCheck'])->name('daily-checks.store');
        Route::post('/egg-productions', [FieldOperationController::class, 'storeEggCollection'])->name('egg-productions.store');
    });
});
