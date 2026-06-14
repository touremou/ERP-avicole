@props([
    'align' => 'right',   // 'right' | 'left'
    'width' => 'w-56',    // classe de largeur Tailwind du panneau
    // Style du panneau déroulant — surchargé par appel pour conserver
    // l'apparence propre à chaque menu (cloche, drawer, switcher…).
    'panel' => 'bg-white rounded-2xl shadow-2xl border border-slate-100 p-2',
])

@php
    $alignment = $align === 'left' ? 'left-0' : 'right-0';
@endphp

{{--
    Menu déroulant canonique — comportement unique et robuste pour TOUS les
    menus de l'application : ouverture au clic (fiable au tactile, fin du
    double-tap), fermeture au clic en dehors et à la touche Échap, transition
    et x-cloak. L'apparence reste libre via les slots `trigger` / contenu et
    la prop `panel`.
--}}
<div
    {{ $attributes->merge(['class' => 'relative']) }}
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
    @click.outside="open = false"
>
    <div @click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="true" class="cursor-pointer">
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-end="opacity-0"
        class="absolute {{ $alignment }} top-full mt-1 {{ $width }} {{ $panel }} z-50"
    >
        {{ $slot }}
    </div>
</div>
