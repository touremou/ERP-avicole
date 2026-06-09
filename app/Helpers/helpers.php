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
