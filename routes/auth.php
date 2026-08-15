<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
 * PAS D'INSCRIPTION PUBLIQUE.
 *
 * Les routes `register` de Breeze étaient restées ouvertes : n'importe qui
 * atteignant le site pouvait créer un compte et se retrouvait IMMÉDIATEMENT
 * authentifié sur le tableau de bord (`Auth::login($user)` puis redirection).
 * Le compte naissait actif (`users.is_active` vaut true par défaut) et sans
 * rôle — les portes de modules le refusaient donc, mais il détenait une session
 * valide, durable, pour sonder chaque écran à la recherche d'une porte oubliée.
 * Et la table des comptes se remplissait sans que personne ne l'ait décidé.
 *
 * Cet ERP ne s'adresse pas au public : il sert UNE exploitation, dont les
 * comptes se créent à l'écran d'administration (droit `S`), avec un rôle choisi.
 * Cette porte-ci n'avait donc aucun usage — seulement un risque.
 *
 * Le contrôleur, sa vue et le test Breeze qui ATTESTAIT le comportement partent
 * avec la route : une vue qui pointe vers une route inexistante est une mine, et
 * le tout premier compte se crée par l'installateur (InstallController), qui a
 * son propre chemin.
 */
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
