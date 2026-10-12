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
            // Contexte ferme pour l'API stateless (étanchéité multi-fermes) —
            // à attacher APRÈS auth:sanctum. Cf. SetApiFarmContext.
            'farm.api' => \App\Http\Middleware\SetApiFarmContext::class,
        ]);

        // SESSION TERMINÉE : l'écran de connexion le DIT. Une session expirée
        // (inactivité) renvoyait à la connexion sans un mot — au moment, le plus
        // souvent, où l'on validait un filtre d'une touche Entrée : « l'appli se
        // déconnecte quand j'appuie sur Entrée ». Après reconnexion, `intended`
        // ramène à la page demandée.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->hasSession() && ! $request->expectsJson()) {
                $request->session()->flash('status', __('Votre session a pris fin. Reconnectez-vous : vous reviendrez à la page demandée.'));
            }

            return route('login');
        });

        // NOTE : un seul appel à ->withMiddleware() doit configurer le
        // groupe "web" — chaque appel écrase entièrement les groupes définis
        // par les appels précédents (setMiddlewareGroups remplace, ne fusionne
        // pas). Prepend et append doivent donc être déclarés ensemble ici.
        $middleware->web(
            prepend: [
                \App\Http\Middleware\EnsureAppIsInstalled::class,
            ],
            append: [
                // Un compte suspendu n'a plus d'accès — à CHAQUE requête, et non
                // au seul instant de la connexion. Avant celui-ci, suspendre un
                // compte bloquait les connexions futures et laissait intact tout
                // ce qui était déjà ouvert : la session du navigateur jusqu'à
                // expiration du cookie, et le jeton du téléphone sans limite.
                \App\Http\Middleware\EnsureAccountIsActive::class,
                \App\Http\Middleware\SetCurrentFarm::class,
                \App\Http\Middleware\SetUserLocale::class,
                \App\Http\Middleware\EnsureLicensed::class,
            ],
        );

        $middleware->api(append: [
            // Même règle au terrain : la révocation des jetons ferme la porte au
            // moment du geste, celui-ci la garde fermée entre-temps.
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\SetUserLocale::class,
            // L'ÉCHÉANCE D'ABONNEMENT NE S'ARRÊTAIT QU'AU BUREAU.
            //
            // Ce middleware n'était posé que sur le groupe web : l'abonnement
            // échu verrouillait l'écran du bureau pendant que l'application
            // terrain continuait de synchroniser ventes et stocks, sans terme.
            // `allowsModule()`, seul contrôle de licence de l'API, lit la liste
            // des modules sans jamais regarder l'échéance.
            //
            // L'intention était écrite deux fois : le middleware porte une
            // branche `api/*` → 402 qu'aucune requête ne pouvait atteindre, et
            // ApiLicenseLockTest annonce « le paywall ne doit pas pouvoir être
            // contourné via la PWA ». Inactif tant que la licence n'est pas armée.
            \App\Http\Middleware\EnsureLicensed::class,
        ]);
    })
        ->withExceptions(function (Exceptions $exceptions) {
        // Alerte WhatsApp de l'admin sur toute erreur serveur non gérée
        // (audit P2-⑪) : throttle 5 min par empreinte, 4xx/validation/refus
        // métier ignorés, jamais bloquant. Cf. ErrorAlertService.
        $exceptions->report(function (\Throwable $e) {
            \App\Services\ErrorAlertService::handle($e);
        });

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

        // Filet « licence » : un refus d'accès dû à un module HORS abonnement
        // (et non à un manque de droits) doit renvoyer vers l'écran de
        // renouvellement avec un message explicite. Évalué avant les filets RBAC.
        $licenseRefusal = function ($request) {
            if (! app(\App\Services\LicenseService::class)->currentRouteModuleLocked()) {
                return null;
            }
            $message = "Ce module n'est pas inclus dans votre abonnement. Contactez le fournisseur pour l'activer.";
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $message], 402);
            }
            return redirect()->route('license.edit')->with('error', $message);
        };

        // Filet 1 : Intercepte les Gate, Policy et $this->authorize()
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) use ($licenseRefusal) {
            if ($redirect = $licenseRefusal($request)) {
                return $redirect;
            }

            if ($request->is('api/*')) {
                return response()->json(['message' => 'Accès refusé.'], 403);
            }

            return redirect()->back()->with('error', '🔒 ACCÈS REFUSÉ : Privilèges insuffisants pour cette opération.');
        });

        // Filet « page expirée » (419) : jeton périmé — page restée ouverte, ou
        // session terminée pendant la saisie. L'écran brut « Page Expired »
        // laissait croire à une panne et perdait la saisie sans le dire.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            $retour = url()->previous();

            if (auth()->check()) {
                return redirect()->to($retour)
                    ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                    ->with('error', __("La page était restée ouverte trop longtemps : rien n'a été enregistré. Vérifiez la saisie et renvoyez-la."));
            }

            $request->session()->put('url.intended', $retour);

            return redirect()->route('login')
                ->with('status', __("Votre session a pris fin : la saisie n'a pas été enregistrée. Reconnectez-vous, vous reviendrez sur la page."));
        });

        // Filet 2 : Intercepte les abort(403) et middleware 'can'
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) use ($licenseRefusal) {
            if ($e->getStatusCode() === 403) {
                if ($redirect = $licenseRefusal($request)) {
                    return $redirect;
                }

                if ($request->is('api/*')) {
                    return response()->json(['message' => 'Accès refusé.'], 403);
                }

                return redirect()->back()->with('error', '🔒 ACCESS RESTREINTE : Votre permission ne permet pas de faire cette opération.');
            }
        });
    })
        ->create();