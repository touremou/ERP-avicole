{{--
    SÉLECTEUR DE CATÉGORIE — rendu unique.

    Quatre menus déroulants portaient leur propre liste, tous limités aux six
    catégories d'ÉLEVAGE, alors que le modèle en connaît quatorze et que les
    modèles de tâches agricoles utilisent les autres. On ne pouvait donc créer
    aucune tâche d'irrigation, de semis ni de relevé : un arrosage se rangeait
    sous « Alimentation ».

    @param string|null $selected  slug déjà retenu
    @param string|null $name      nom du champ (défaut « category »)
    @param bool $required
    @param string|null $blank     libellé d'une option vide (filtres)
    @param string $class
--}}
@php
    $name = $name ?? 'category';
    $selected = $selected ?? null;
    $required = $required ?? false;
    $blank = $blank ?? null;
    $class = $class ?? 'w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none';
@endphp

<select name="{{ $name }}" @if($required) required @endif class="{{ $class }}"
        @isset($onchange) onchange="{{ $onchange }}" @endisset>
    @if($blank !== null)
        <option value="">{{ $blank }}</option>
    @endif

    @foreach(\App\Models\TaskTemplate::categoryOptionGroups() as $group => $options)
        <optgroup label="── {{ __($group) }} ──">
            @foreach($options as $slug => $label)
                <option value="{{ $slug }}" @selected($selected === $slug)>{{ $label }}</option>
            @endforeach
        </optgroup>
    @endforeach

    {{-- Catégorie héritée d'anciennes données : on ne la fait pas disparaître du
         menu, sinon enregistrer la fiche la remplacerait en silence. --}}
    @if($selected && ! array_key_exists($selected, \App\Models\TaskTemplate::CATEGORIES))
        <optgroup label="── {{ __('Autre') }} ──">
            <option value="{{ $selected }}" selected>{{ \App\Models\TaskTemplate::categoryMeta($selected)['label'] }}</option>
        </optgroup>
    @endif
</select>
