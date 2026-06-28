{{--
    Bandeaux de session (succès / avertissement / erreur) standardisés et
    réutilisables. Remplace les blocs @if(session(...)) recopiés dans chaque vue.
    Usage : placer <x-flash /> en haut du contenu de page.
--}}
@php
    $flashes = [
        'success' => ['bg-emerald-500', 'fa-check-double'],
        'warning' => ['bg-amber-500',   'fa-triangle-exclamation'],
        'error'   => ['bg-red-500',     'fa-circle-xmark'],
    ];
@endphp

@foreach($flashes as $key => [$bg, $icon])
    @if(session($key))
        <div class="mb-6 p-5 {{ $bg }} text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
            <i class="fa-solid {{ $icon }} mr-3 text-lg"></i>{{ session($key) }}
        </div>
    @endif
@endforeach
