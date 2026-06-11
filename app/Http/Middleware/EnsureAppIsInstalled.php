<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAppIsInstalled — Redirige vers l'assistant d'installation tant que
 * l'application n'a pas été configurée (pas de fichier storage/installed).
 *
 * Exclut les routes /install/* et /up (health check) pour éviter les
 * boucles de redirection, ainsi que l'environnement de test.
 *
 * Compatibilité ascendante : une installation existante (table `users`
 * déjà peuplée) est automatiquement considérée comme installée — le
 * marqueur est créé au premier accès, sans passer par l'assistant.
 */
class EnsureAppIsInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        // Le driver de session "database" exige la table `sessions`. Tant
        // que la base n'est pas migrée, on bascule sur des sessions fichier
        // pour permettre l'affichage de l'assistant d'installation.
        if (config('session.driver') === 'database' && ! $this->tableExists('sessions')) {
            config(['session.driver' => 'file']);
        }

        if ($request->is('install*', 'up')) {
            return $next($request);
        }

        if (file_exists(storage_path('installed'))) {
            return $next($request);
        }

        if ($this->tableExists('users') && DB::table('users')->exists()) {
            @file_put_contents(storage_path('installed'), now()->toDateTimeString());
            return $next($request);
        }

        return redirect()->route('install.welcome');
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
