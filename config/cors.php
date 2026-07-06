<?php

/**
 * CORS — accès de la PWA terrain (sous-domaine app.*) à l'API v1.
 *
 * L'authentification mobile se fait par token Bearer (Sanctum personal access
 * tokens), PAS par cookie de session : on n'a donc pas besoin de
 * `supports_credentials` (qui interdirait par ailleurs le wildcard d'origine).
 *
 * Les origines autorisées sont pilotées par l'env `CORS_ALLOWED_ORIGINS`
 * (liste séparée par des virgules), ex. :
 *   CORS_ALLOWED_ORIGINS=https://app.ferme.example.com,https://app-staging.ferme.example.com
 * Vide → '*' (pratique en staging/dev, à restreindre en production).
 * Cf. docs/mobile/deploiement-staging.md.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins !== [] ? $origins : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // X-Farm-Id doit être lisible côté client si jamais on le renvoie ; les
    // écritures n'en ont pas besoin en réponse. On expose le strict utile.
    'exposed_headers' => [],

    'max_age' => 3600,

    // Tokens Bearer, pas de cookie → pas de credentials cross-origine.
    'supports_credentials' => false,
];
