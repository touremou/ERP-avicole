<?php

/*
|--------------------------------------------------------------------------
| Nomenclature de boucherie & rendements carcasse par espèce
|--------------------------------------------------------------------------
|
| Données zootechniques de référence (non spécifiques à une ferme) utilisées
| par le module Abattoir pour :
|   1. Proposer les morceaux de découpe adaptés à l'espèce (volaille ≠ ovin
|      ≠ bovin ≠ porcin ≠ lapin ≠ poisson).
|   2. Évaluer le rendement carcasse (poids carcasse / poids vif) avec des
|      bandes cible/alerte propres à chaque famille — un poulet rend ~72%,
|      un bovin ~55%, un ovin ~48%.
|
| Résolu via App\Services\ButcheryNomenclature (clé = Species::$family).
|
| Rétrocompat : pour la volaille, les bandes de rendement carcasse sont
| surchargées par les paramètres Paramètres > Abattoir (abattoir.yield_*)
| s'ils existent, afin de ne pas casser les réglages déjà en place.
|
*/

return [

    /*
    | Morceaux de découpe par famille d'espèce.
    | Chaque morceau : code (stable, stocké), label (affiché), destination par
    | défaut (stock_frais|stock_congele|transformation|vente_directe).
    | 'default' => true marque les lignes pré-remplies du formulaire de découpe.
    */
    'cuts' => [

        'volaille' => [
            ['code' => 'cuisse',   'label' => 'Cuisses',         'destination' => 'stock_frais',   'default' => true],
            ['code' => 'aile',     'label' => 'Ailes',           'destination' => 'stock_frais',   'default' => true],
            ['code' => 'poitrine', 'label' => 'Poitrine/Blancs', 'destination' => 'stock_frais',   'default' => true],
            ['code' => 'dos',      'label' => 'Dos/Carcasse',    'destination' => 'vente_directe', 'default' => true],
            ['code' => 'abats',    'label' => 'Abats divers',    'destination' => 'stock_frais',   'default' => true],
            ['code' => 'foie',     'label' => 'Foies',           'destination' => 'stock_frais'],
            ['code' => 'gesier',   'label' => 'Gésiers',         'destination' => 'stock_frais'],
            ['code' => 'entier',   'label' => 'Entier',          'destination' => 'stock_frais'],
        ],

        'petit_ruminant' => [ // mouton, chèvre
            ['code' => 'gigot',      'label' => 'Gigot / Cuissot',   'destination' => 'stock_frais',   'default' => true],
            ['code' => 'epaule',     'label' => 'Épaule',            'destination' => 'stock_frais',   'default' => true],
            ['code' => 'cotelettes', 'label' => 'Côtelettes / Carré','destination' => 'stock_frais',   'default' => true],
            ['code' => 'collier',    'label' => 'Collier',           'destination' => 'stock_frais',   'default' => true],
            ['code' => 'poitrine',   'label' => 'Poitrine / Tendrons','destination' => 'vente_directe','default' => true],
            ['code' => 'abats',      'label' => 'Abats (foie, rognons, fressure)', 'destination' => 'stock_frais'],
            ['code' => 'carcasse',   'label' => 'Carcasse entière',  'destination' => 'vente_directe'],
        ],

        'grand_ruminant' => [ // bovin
            ['code' => 'aloyau',   'label' => 'Aloyau (filet, faux-filet, rumsteck)', 'destination' => 'stock_frais',   'default' => true],
            ['code' => 'cuisse',   'label' => 'Cuisse (gîte, tende de tranche)',      'destination' => 'stock_frais',   'default' => true],
            ['code' => 'epaule',   'label' => 'Épaule (paleron, macreuse, jumeau)',   'destination' => 'stock_frais',   'default' => true],
            ['code' => 'cotes',    'label' => 'Côtes / Entrecôtes',                   'destination' => 'stock_frais',   'default' => true],
            ['code' => 'poitrine', 'label' => 'Poitrine / Plat de côtes',             'destination' => 'vente_directe', 'default' => true],
            ['code' => 'collier',  'label' => 'Collier / Basses côtes',               'destination' => 'vente_directe'],
            ['code' => 'abats',    'label' => 'Abats (foie, langue, tripes)',         'destination' => 'stock_frais'],
        ],

        'porcin' => [
            ['code' => 'jambon',   'label' => 'Jambon / Cuisse',  'destination' => 'stock_frais',   'default' => true],
            ['code' => 'echine',   'label' => 'Échine / Carré',   'destination' => 'stock_frais',   'default' => true],
            ['code' => 'cotes',    'label' => 'Côtes / Travers',  'destination' => 'stock_frais',   'default' => true],
            ['code' => 'poitrine', 'label' => 'Poitrine / Lard',  'destination' => 'transformation','default' => true],
            ['code' => 'epaule',   'label' => 'Épaule / Palette', 'destination' => 'stock_frais',   'default' => true],
            ['code' => 'abats',    'label' => 'Abats',            'destination' => 'stock_frais'],
        ],

        'lagomorphe' => [ // lapin
            ['code' => 'rable',  'label' => 'Râble',          'destination' => 'stock_frais', 'default' => true],
            ['code' => 'cuisse', 'label' => 'Cuisses',        'destination' => 'stock_frais', 'default' => true],
            ['code' => 'avant',  'label' => 'Avant / Épaules','destination' => 'stock_frais', 'default' => true],
            ['code' => 'abats',  'label' => 'Abats (foie, rognons)', 'destination' => 'stock_frais'],
            ['code' => 'entier', 'label' => 'Lapin entier',   'destination' => 'stock_frais'],
        ],

        'aquaculture' => [ // tilapia, carpe, silure
            ['code' => 'filet',       'label' => 'Filets',          'destination' => 'stock_frais',   'default' => true],
            ['code' => 'darne',       'label' => 'Darnes / Tronçons','destination' => 'stock_frais',  'default' => true],
            ['code' => 'entier_vide', 'label' => 'Entier vidé',     'destination' => 'stock_frais',   'default' => true],
            ['code' => 'tete',        'label' => 'Têtes / Parures', 'destination' => 'vente_directe'],
        ],
    ],

    /*
    | Rendement carcasse (poids carcasse / poids vif, en %) par famille.
    | target_min/target_max : plage normale ; alert_min : seuil sous lequel
    | le rendement est jugé anormalement bas (coloration rouge).
    */
    'carcass_yield' => [
        'volaille'       => ['target_min' => 70, 'target_max' => 75, 'alert_min' => 65],
        'petit_ruminant' => ['target_min' => 45, 'target_max' => 52, 'alert_min' => 40],
        'grand_ruminant' => ['target_min' => 52, 'target_max' => 60, 'alert_min' => 48],
        'porcin'         => ['target_min' => 72, 'target_max' => 80, 'alert_min' => 68],
        'lagomorphe'     => ['target_min' => 50, 'target_max' => 60, 'alert_min' => 45],
        'aquaculture'    => ['target_min' => 45, 'target_max' => 55, 'alert_min' => 40],
    ],

    // Famille de repli si l'espèce n'est pas renseignée (rétrocompat poulet).
    'default_family' => 'volaille',

    /*
    | Présentations de la carcasse choisies À L'EXÉCUTION (gammes de sortie).
    | Chacune nomme l'article de stock produit et ajuste la bande de rendement
    | attendue (yield_delta, en points, ajouté à la bande carcasse de l'espèce) :
    |  - PAC / Brut : carcasse vidée standard → bande de base ;
    |  - Effilé : têtes + pattes CONSERVÉES → plus de poids → rendement plus haut.
    | `to_cut` = alimente la découpe (RG matière) ; sinon = article vendable direct.
    */
    'presentations' => [
        'brut' => [
            'label'       => 'Brut — carcasse à découper',
            'name'        => 'Entier Frais',   // rétrocompat : nom historique
            'yield_delta' => 0,
            'to_cut'      => true,
        ],
        'pac' => [
            'label'       => 'PAC — prêt-à-cuire (vidé, emballé)',
            'name'        => 'PAC',
            'yield_delta' => 0,
            'to_cut'      => false,
        ],
        'effile' => [
            'label'       => 'Effilé — têtes/pattes conservées',
            'name'        => 'Effilé',
            'yield_delta' => 12,
            'to_cut'      => false,
        ],
    ],
    'default_presentation' => 'brut',
];
