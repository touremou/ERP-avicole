<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bâtiments compatibles par espèce (hors volaille)
    |--------------------------------------------------------------------------
    |
    | Pour les espèces avicoles, le type de bâtiment correspond directement
    | au slug du type de production (chair/ponte/poussiniere/reproducteur) :
    | la compatibilité se résout donc par simple égalité, sans entrée ici.
    |
    | Pour les autres familles, l'habitat (bâtiment) est dédié à l'ESPÈCE et
    | non à la phase de production (un caprin reste en chèvrerie qu'il soit
    | en engraissement, lactation ou reproduction). Cette table associe donc
    | chaque slug d'espèce à la liste des types de bâtiment ('buildings.type')
    | qui peuvent l'accueillir, en plus de 'mixte' (toujours autorisé).
    |
    | Source unique de vérité partagée par :
    | - App\Models\Species::compatibleBuildingTypes()
    | - App\Http\Requests\Batch\TransferBatchRequest (validation serveur)
    | - resources/views/batches/{create,edit,show}.blade.php (filtres JS)
    |
    */
    'building_types' => [
        'mouton'  => ['bergerie'],
        'chevre'  => ['chevrerie'],
        'vache'   => ['etable'],
        'lapin'   => ['lapiniere'],
        'porc'    => ['porcherie'],
        'tilapia' => ['bassin'],
        'carpe'   => ['bassin'],
        'silure'  => ['bassin'],
    ],

];
