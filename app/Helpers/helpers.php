<?php

/**
 * HELPER GLOBAL : setting()
 *
 * Usage :
 *   setting('elevage.cycle_chair')         → 42
 *   setting('general.tva_rate', 18)        → 18 (avec fallback)
 *   setting('abattoir.tolerance_eggs')     → 2
 *
 * Fichier : app/helpers.php
 *
 * Enregistrement dans composer.json :
 *   "autoload": {
 *       "files": ["app/helpers.php"]
 *   }
 *
 * Puis : composer dump-autoload
 */

if (! function_exists('setting')) {
    /**
     * Récupère un paramètre système.
     *
     * @param string     $dotKey  Clé au format "group.key" (ex: "elevage.cycle_chair")
     * @param mixed|null $default Valeur par défaut si non trouvé
     * @return mixed
     */
    function setting(string $dotKey, $default = null)
    {
        return \App\Models\Setting::get($dotKey, $default);
    }
}

if (! function_exists('currency')) {
    /**
     * Symbole monétaire de l'application (paramètre general.currency).
     * Centralise ce qui était codé en dur (« GNF ») un peu partout.
     */
    function currency(): string
    {
        return (string) setting('general.currency', 'GNF');
    }
}

if (! function_exists('money')) {
    /**
     * Formate un montant avec la devise courante.
     *   money(12345)      → "12 345 GNF"
     *   money(1234.5, 2)  → "1 234,50 GNF"
     */
    function money($amount, int $decimals = 0): string
    {
        return number_format((float) $amount, $decimals, ',', ' ') . ' ' . currency();
    }
}

if (! function_exists('cash_round')) {
    /**
     * Arrondi monétaire à la coupure de caisse paramétrée (ventes.cash_rounding).
     *
     * En Guinée, certaines coupures ne circulent plus : on ne peut pas rendre
     * la monnaie au franc près. On arrondit donc le total payable à la coupure
     * la plus proche (0 = pas d'arrondi, 100, 500, 1000, 2000 GNF…), à la manière
     * de l'arrondi suédois. Le client règle alors un montant « rond », sans
     * dette fantôme ni écart entre la vente et l'encaissement.
     *
     *   cash_round(55100) avec step=1000 → 55000
     *   cash_round(55600) avec step=1000 → 56000
     *   cash_round(55100) avec step=0    → 55100 (désactivé)
     *
     * @param  float|int  $amount  Montant brut.
     * @param  int|null   $step    Coupure forcée (sinon lue dans les réglages).
     */
    function cash_round($amount, ?int $step = null): float
    {
        $step = $step ?? (int) setting('ventes.cash_rounding', 0);
        if ($step <= 0) {
            return round((float) $amount, 2);
        }

        return round((float) $amount / $step) * $step;
    }
}

if (! function_exists('license_allows_module')) {
    /**
     * Le module $slug est-il déverrouillé par l'abonnement courant ?
     *
     * Renvoie toujours true quand le système de licence est inactif (mode
     * ouvert). Pratique dans les vues pour masquer une tuile/un lien de module
     * non inclus dans le plan du client.
     */
    function license_allows_module(string $slug): bool
    {
        return app(\App\Services\LicenseService::class)->allowsModule($slug);
    }
}

if (! function_exists('media_url')) {
    /**
     * URL d'un fichier stocké sur le disque "public" (logos, photos, etc.).
     *
     * On passe par une route applicative (App\Http\Controllers\MediaController)
     * plutôt que par asset('storage/...'). Avantages :
     *   - fonctionne même si le lien symbolique `php artisan storage:link` n'a pas
     *     été créé sur le serveur (cause n°1 des images qui ne s'affichent pas) ;
     *   - l'URL est relative à la requête courante (bon schéma http/https derrière
     *     un proxy), ce qui évite les blocages "mixed content".
     *
     * @param  string|null $path     Chemin relatif sur le disque public (ex: "employees/photos/x.jpg")
     * @param  string|null $fallback URL renvoyée si $path est vide
     * @return string|null
     */
    function media_url(?string $path, ?string $fallback = null): ?string
    {
        if (empty($path)) {
            return $fallback;
        }

        return url('media/' . ltrim($path, '/'));
    }
}

if (! function_exists('dashboard_block_visible')) {
    /**
     * Indique si un bloc du tableau de bord doit être affiché pour l'utilisateur
     * courant. Tout bloc est visible par défaut ; il n'est masqué que si
     * l'utilisateur l'a explicitement retiré dans ses préférences de dashboard.
     *
     * La relation dashboardConfiguration est mise en cache sur l'instance User
     * pour la durée de la requête : aucun coût de requête répété par bloc.
     */
    function dashboard_block_visible(string $key): bool
    {
        $user = auth()->user();
        if (! $user) {
            return true;
        }

        $config = $user->dashboardConfiguration;

        return ! ($config && in_array($key, $config->hidden_blocks ?? [], true));
    }
}
