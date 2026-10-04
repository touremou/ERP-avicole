<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAppIsInstalled — Redirige vers l'assistant d'installation tant que
 * l'application n'a pas été configurée (pas de fichier storage/installed).
 *
 * Exclut les routes /install/* et /up (health check) pour éviter les
 * boucles de redirection, ainsi que l'environnement de test.
 *
 * Compatibilité ascendante : une installation existante (un administrateur
 * RÉEL, pas un compte de démonstration) est automatiquement considérée comme
 * installée — le marqueur est créé au premier accès, sans passer par
 * l'assistant.
 *
 * ─── DEUXIÈME LECTEUR DE LA MÊME QUESTION ───
 *
 * Ce middleware et `RedirectIfInstalled` répondent tous deux à « cette
 * application est-elle installée ? ». Ils le faisaient chacun à leur façon :
 * l'un par `file_exists`, l'autre — depuis que le marqueur peut vivre en base —
 * par `InstallationState`. Deux réponses possibles à une même question, c'est
 * la forme exacte des défauts que cet audit poursuit ; et celle-ci était de mon
 * fait, pour n'avoir corrigé qu'une des deux portes.
 *
 * Les deux lisent désormais la même déclaration. Le marqueur recréé au premier
 * accès est posé EN BASE autant que sur disque : un hôte qui a perdu son
 * `storage/` cesse ainsi définitivement de pouvoir rouvrir l'assistant, au lieu
 * de dépendre d'une course entre le premier visiteur légitime et un autre.
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

        if ($request->is('install*', 'up', 'manifest.webmanifest')) {
            return $next($request);
        }

        if (InstallationState::marqueurPose()) {
            return $next($request);
        }

        // Compatibilité ascendante : une exploitation déjà en service (un VRAI
        // administrateur) reçoit son marqueur au premier accès.
        //
        // « Un compte existe » ne suffit PLUS. `migrate --seed` crée six comptes
        // de démonstration au mot de passe public `password`, dont deux
        // administrateurs : ce middleware déclarait alors l'application
        // installée et posait le marqueur, si bien que l'assistant — qui crée
        // l'administrateur réel et SUPPRIME ces comptes (#396) — ne s'ouvrait
        // jamais. L'assistant, lui, ne tenait pas cette base pour installée
        // (`estInstallee`) : deux réponses à la même question. Les deux portes
        // lisent désormais la même règle.
        if ($this->tableExists('users') && InstallationState::estInstallee()) {
            InstallationState::marquerInstallee();

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
