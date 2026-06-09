<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
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
        ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\SetCurrentFarm::class,
        ]);
    })
    /*
        ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (\Throwable $e) {
            \App\Services\ErrorAlertService::handle($e);
        });
    })
    */
        ->create();