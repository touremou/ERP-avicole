{{--
    Options du select "type" de protocole, générées dynamiquement à partir
    des types de production de toutes les espèces actives (et plus
    seulement les phases volaille chair/ponte/poussinière/reproducteur).

    Variables attendues :
    - $productionTypes : Collection<ProductionType> (avec relation species chargée)
    - $selected        : valeur actuellement sélectionnée (slug)
--}}
@foreach($productionTypes->groupBy(fn($pt) => $pt->species->name_fr ?? __("Autres")) as $speciesLabel => $types)
    <optgroup label="{{ strtoupper($speciesLabel) }}">
        @foreach($types as $pt)
            <option value="{{ $pt->slug }}" {{ (string) $selected === (string) $pt->slug ? 'selected' : '' }}>
                {{ $pt->name_fr }}
            </option>
        @endforeach
    </optgroup>
@endforeach
