{{--
    Flèche de RETOUR réutilisable pour les pages « feuilles » (create / edit /
    show / détail), placée dans l'en-tête de la page (x-slot header).

    Cible :
      - `:to` explicite si fourni (ex. <x-back :to="route('clients.show', $client)" />) ;
      - sinon AUTO : parent direct `{préfixe}.index` (ex. clients.edit → clients.index,
        sales.return.create → sales.return.index), repli sur la section `{1er}.index`,
        puis sur la page précédente.

    Complément de x-hub-back (injecté par le layout pour les pages de SECTION, qui
    ignore justement les pages feuilles — donc aucun double).

    Usage : « x-back » seul (auto), ou avec « :to="route(...)" » pour forcer la cible
    (ex. edit -> fiche), et « label="..." » / « class="..." » optionnels.
--}}
@props(['to' => null, 'label' => null])

@php
    $href = $to;
    if (! $href) {
        $name = request()->route()?->getName() ?? '';
        $segments = explode('.', $name);
        $first = $segments[0] ?? '';
        $parent = $segments;
        if (count($parent) > 1) {
            array_pop($parent); // retire l'action (create/edit/show/…)
        }
        $candidates = array_values(array_unique(array_filter([
            $parent ? implode('.', $parent) . '.index' : null, // parent direct
            $first ? $first . '.index' : null,                 // repli section
        ])));
        foreach ($candidates as $candidate) {
            if (\Illuminate\Support\Facades\Route::has($candidate)) {
                $href = route($candidate);
                break;
            }
        }
        $href = $href ?: url()->previous();
    }
@endphp

<a href="{{ $href }}"
   {{ $attributes->merge(['class' => 'w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline shrink-0']) }}
   title="{{ $label ?? __('Retour') }}">
    <i class="fa-solid fa-arrow-left"></i>
</a>
