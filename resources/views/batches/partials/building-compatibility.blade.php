{{--
    Compatibilité bâtiment / espèce — source unique de vérité partagée par
    batches/create.blade.php, batches/edit.blade.php et batches/show.blade.php
    (modale de mutation), alignée sur App\Models\Species::compatibleBuildingTypes()
    et App\Http\Requests\Batch\TransferBatchRequest (cf. config/livestock.php).
--}}
<script>
    // Bâtiments compatibles par espèce (hors volaille). 'mixte' est toujours
    // autorisé. Pour les espèces absentes (volaille notamment), la
    // compatibilité se résout par égalité directe type bâtiment / type cible.
    const SPECIES_BUILDING_TYPES = @json(config('livestock.building_types'));

    function isBuildingCompatible(buildingType, speciesSlug, targetType = '') {
        if (buildingType === 'mixte') return true;

        const allowed = SPECIES_BUILDING_TYPES[speciesSlug];
        if (allowed) return allowed.includes(buildingType);

        return targetType === '' || buildingType === targetType;
    }
</script>
