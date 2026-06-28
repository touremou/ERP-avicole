<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'redirect.if.installed' => \App\Http\Middleware\RedirectIfInstalled::class,
            'force.json' => \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // NOTE : un seul appel à ->withMiddleware() doit configurer le
        // groupe "web" — chaque appel écrase entièrement les groupes définis
        // par les appels précédents (setMiddlewareGroups remplace, ne fusionne
        // pas). Prepend et append doivent donc être déclarés ensemble ici.
        $middleware->web(
            prepend: [
                \App\Http\Middleware\EnsureAppIsInstalled::class,
            ],
            append: [
                \App\Http\Middleware\SetCurrentFarm::class,
                \App\Http\Middleware\SetUserLocale::class,
                \App\Http\Middleware\EnsureLicensed::class,
            ],
        );

        $middleware->api(append: [
            \App\Http\Middleware\SetUserLocale::class,
        ]);
    })
        ->withExceptions(function (Exceptions $exceptions) {
        // Filet 0 : Sessions expirées/absentes sur les routes API (offline,
        // sync...) → JSON 401, jamais une redirection vers /login. Le
        // middleware "force.json" ne suffit pas : la priorité globale des
        // middlewares exécute "auth" AVANT "force.json", donc l'en-tête
        // Accept n'est pas encore forcé quand Authenticate::class lève
        // l'exception. On se base ici sur le chemin de la requête, qui est
        // toujours fiable.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Session expirée, veuillez vous reconnecter.'], 401);
            }
        });

        // Filet 1 : Intercepte les Gate, Policy et $this->authorize()
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Accès refusé.'], 403);
            }

            return redirect()->back()->with('error', '🔒 ACCÈS REFUSÉ : Privilèges insuffisants pour cette opération.');
        });

        // Filet 2 : Intercepte les abort(403) et middleware 'can'
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() === 403) {
                if ($request->is('api/*')) {
                    return response()->json(['message' => 'Accès refusé.'], 403);
                }

                return redirect()->back()->with('error', '🔒 ACCESS RESTREINTE : Votre permission ne permet pas de faire cette opération.');
            }
        });
    })
    /*
        ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (\Throwable $e) {
            \App\Services\ErrorAlertService::handle($e);
        });
    })
    */
        ->create();