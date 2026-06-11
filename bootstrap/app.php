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
            ],
        );

        $middleware->api(append: [
            \App\Http\Middleware\SetUserLocale::class,
        ]);
    })
        ->withExceptions(function (Exceptions $exceptions) {
        // Filet 1 : Intercepte les Gate, Policy et $this->authorize()
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            return redirect()->back()->with('error', '🔒 ACCÈS REFUSÉ : Privilèges insuffisants pour cette opération.');
        });

        // Filet 2 : Intercepte les abort(403) et middleware 'can'
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() === 403) {
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