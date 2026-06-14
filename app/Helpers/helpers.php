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
