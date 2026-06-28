<?php

/**
 * Configuration du serveur de licence fournisseur.
 *
 * Le catalogue de plans DOIT rester cohérent avec config/license.php de l'ERP
 * client (mêmes slugs de modules, mêmes limites) pour que les jetons émis
 * correspondent à ce que l'ERP déverrouille.
 */
return [
    // Clé privée Ed25519 (base64). Priorité à la variable d'environnement ;
    // sinon fichier storage/private.key. NE JAMAIS committer cette valeur.
    'private_key' => getenv('LICENSE_PRIVATE_KEY')
        ?: (is_file(__DIR__ . '/storage/private.key') ? trim((string) file_get_contents(__DIR__ . '/storage/private.key')) : ''),

    'db_path' => getenv('LICENSE_DB_PATH') ?: __DIR__ . '/storage/licenses.sqlite',

    'plans' => [
        'basic' => [
            'modules'   => ['elevage', 'logistique', 'commerce', 'admin'],
            'max_users' => 3,
            'max_farms' => 1,
            'sms_quota' => 200,
        ],
        'pro' => [
            'modules'   => ['elevage', 'logistique', 'commerce', 'production', 'provenderie', 'depenses', 'annuaire', 'ressources', 'admin'],
            'max_users' => 10,
            'max_farms' => 3,
            'sms_quota' => 1000,
        ],
        'entreprise' => [
            'modules'   => ['*'],
            'max_users' => 0,
            'max_farms' => 0,
            'sms_quota' => 5000,
        ],
    ],
];
