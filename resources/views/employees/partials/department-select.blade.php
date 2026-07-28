{{--
    SÉLECTEUR DE SERVICE — rendu unique.

    La création et l'édition portaient chacune leur liste, avec des libellés
    DIFFÉRENTS pour le même service : « Élevage / Technique » d'un côté, « Élevage
    & Production » de l'autre. Et les deux se limitaient à trois services, alors
    que l'exploitation compte des cultures, une provenderie, un abattoir et un
    comptoir de vente.

    @param string|null $selected  clef déjà retenue
    @param string $class
--}}
@php
    $selected = $selected ?? null;
    $class = $class ?? 'w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner appearance-none font-black text-slate-800 italic cursor-pointer';
@endphp

<select name="department" class="{{ $class }}">
    @foreach(\App\Models\Employee::departmentOptions() as $key => $label)
        <option value="{{ $key }}" @selected($selected === $key)>{{ $label }}</option>
    @endforeach

    {{-- Service hérité d'anciennes données : le faire disparaître du menu le
         remplacerait en silence à l'enregistrement de la fiche. --}}
    @if($selected && ! array_key_exists($selected, \App\Models\Employee::DEPARTMENTS))
        <option value="{{ $selected }}" selected>{{ $selected }}</option>
    @endif
</select>
