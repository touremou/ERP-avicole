<?php

/**
 * Configuration du système de licence / abonnement (monétisation).
 *
 * Modèle : une licence PAR INSTANCE (déploiement on-premise chez un client),
 * matérialisée par un jeton signé Ed25519 que le fournisseur émet et que le
 * client active hors-ligne (aucun appel réseau requis — adapté à l'Afrique).
 *
 * OPT-IN : tant que `LICENSE_PUBLIC_KEY` est absent, l'enforcement est INACTIF
 * (l'application fonctionne normalement). Le fournisseur active la protection
 * en posant la clé publique (`php artisan license:keygen`) puis en activant un
 * abonnement. Aucune instance existante n'est impactée par défaut.
 */
return [

    // Clé publique Ed25519 (base64) embarquée dans l'instance. La clé PRIVÉE
    // correspondante reste chez le fournisseur et ne doit JAMAIS être livrée.
    // Vide = système de licence désactivé (mode ouvert).
    'public_key' => env('LICENSE_PUBLIC_KEY', ''),

    // Active réellement le blocage à l'expiration. Combiné à la présence de la
    // clé publique : les deux sont requis pour verrouiller l'application.
    'enforce' => env('LICENSE_ENFORCE', true),

    // Jours de grâce après l'échéance : l'application reste utilisable avec un
    // bandeau d'alerte, le temps que le client renouvelle. 0 = blocage immédiat.
    'grace_days' => (int) env('LICENSE_GRACE_DAYS', 7),

    // Coordonnées du fournisseur affichées sur l'écran de renouvellement
    // (surchargeables via les Réglages → groupe "licence").
    'vendor' => [
        'name'    => env('LICENSE_VENDOR_NAME', 'TechCenter'),
        'address' => env('LICENSE_VENDOR_ADDRESS', ''),
        'phone'   => env('LICENSE_VENDOR_PHONE', ''),
    ],

    /**
     * Catalogue des plans commercialisables. Chaque plan déverrouille un
     * ensemble de modules (slugs cf. App\Models\Module) et fixe des limites.
     * 'modules' => ['*'] signifie « tous les modules ».
     *
     * Le jeton de licence porte le plan ET la liste de modules effective au
     * moment de l'émission : modifier ce catalogue ne dégrade jamais une
     * licence déjà vendue (le jeton signé fait foi).
     */
    'plans' => [
        'basic' => [
            'label'     => 'Basic',
            'modules'   => ['elevage', 'logistique', 'commerce', 'admin'],
            'max_users' => 3,
            'max_farms' => 1,
            'sms_quota' => 200,
        ],
        'pro' => [
            'label'     => 'Pro',
            'modules'   => ['elevage', 'logistique', 'commerce', 'production', 'provenderie', 'depenses', 'annuaire', 'ressources', 'admin'],
            'max_users' => 10,
            'max_farms' => 3,
            'sms_quota' => 1000,
        ],
        'entreprise' => [
            'label'     => 'Entreprise',
            'modules'   => ['*'],
            'max_users' => 0, // 0 = illimité
            'max_farms' => 0,
            'sms_quota' => 5000,
        ],
    ],
];
