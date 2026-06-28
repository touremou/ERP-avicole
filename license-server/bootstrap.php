<?php

/**
 * Amorçage commun (CLI + HTTP) du serveur de licence — sans dépendance externe.
 * Enregistre un autoloader PSR-4 minimal pour le namespace LicenseServer\ et
 * charge la configuration.
 */

if (! extension_loaded('sodium')) {
    fwrite(STDERR, "L'extension PHP sodium est requise.\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    $prefix = 'LicenseServer\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

@mkdir(__DIR__ . '/storage', 0775);

return require __DIR__ . '/config.php';
