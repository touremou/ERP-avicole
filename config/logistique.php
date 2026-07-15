<?php

/*
|--------------------------------------------------------------------------
| Gestion des écarts logistiques (expédition → réception)
|--------------------------------------------------------------------------
|
| Source de vérité unique du moteur d'écart (App\Services\Discrepancy\
| DiscrepancyEvaluator). Centralise ce qui était auparavant éparpillé et
| codé en dur dans ReconciliationService :
|
|   1. La TOLÉRANCE admise par type de produit (% de la quantité expédiée).
|      Au-delà, la ligne est « hors tolérance » → écart critique.
|   2. Les SEUILS DE SÉVÉRITÉ appliqués au taux d'écart global.
|
| Chaque valeur référence une clé de paramétrage runtime (setting()) afin
| de rester réglable depuis Paramètres > Abattoir, avec une valeur de repli
| (« default ») si le paramètre n'a jamais été défini. On conserve les clés
| setting() historiques pour ne pas perdre les réglages déjà en place.
|
*/

return [

    /*
    | Tolérance d'écart par product_type, en % de la quantité expédiée.
    | 'default' = repli pour tout type non listé (corrige l'ancien repli
    | silencieux « ?? 1 » qui oubliait notamment 'produits_finis').
    */
    'tolerances' => [
        'oeufs'            => ['setting' => 'abattoir.tolerance_eggs',                'default' => 2],
        'aliment'          => ['setting' => 'abattoir.tolerance_feed',                'default' => 1],
        'fumier'           => ['setting' => 'abattoir.tolerance_manure',              'default' => 5],
        'materiel'         => ['setting' => 'abattoir.tolerance_equipment',           'default' => 0],
        'volaille_vivante' => ['setting' => 'abattoir.tolerance_live_poultry',        'default' => 0],
        'volaille_abattue' => ['setting' => 'abattoir.tolerance_slaughtered_poultry', 'default' => 0],
        'animal_vif'       => ['setting' => 'abattoir.tolerance_live_animals',        'default' => 0],
        'carcasse'         => ['setting' => 'abattoir.tolerance_carcass',             'default' => 1],
        'lait'             => ['setting' => 'abattoir.tolerance_milk',                'default' => 1],
        'produits_finis'   => ['setting' => 'abattoir.tolerance_finished_goods',      'default' => 1],
        'autre'            => ['setting' => 'abattoir.tolerance_other',               'default' => 1],
        'default'          => ['setting' => 'abattoir.tolerance_other',               'default' => 1],
    ],

    /*
    | Bandes de sévérité appliquées au TAUX d'écart global (% manquant /
    | expédié). Un taux strictement supérieur à « attention » → attention ;
    | strictement supérieur à « critique » → critique. Une seule ligne hors
    | tolérance force également la sévérité « critique ».
    */
    'severity' => [
        'attention' => ['setting' => 'abattoir.severity_attention', 'default' => 2],
        'critique'  => ['setting' => 'abattoir.severity_critique',  'default' => 5],
    ],
];
