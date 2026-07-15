@props([
    'href'    => null,        // rend un <a> si fourni, sinon un <button>
    'icon'    => null,        // classe Font Awesome (ex. 'fa-plus')
    'accent'  => 'emerald',   // couleur de survol (primary) / de texte (secondary)
    'variant' => 'primary',   // primary = sombre ; secondary = blanc bordé
    'type'    => 'submit',
])

{{-- Bouton d'action standardisé, aligné sur le langage visuel de l'app
     (sombre → accent au survol). Classes de couleur écrites en clair (scannées). --}}
@php
    $hover = match ($accent) {
        'cyan'   => 'hover:bg-cyan-600',
        'rose'   => 'hover:bg-rose-600',
        'amber'  => 'hover:bg-amber-600',
        'blue'   => 'hover:bg-blue-600',
        'green'  => 'hover:bg-green-600',
        'teal'   => 'hover:bg-teal-700',
        'purple' => 'hover:bg-purple-600',
        default  => 'hover:bg-emerald-600',
    };
    $accentText = match ($accent) {
        'cyan'   => 'text-cyan-600',
        'rose'   => 'text-rose-600',
        'amber'  => 'text-amber-600',
        'blue'   => 'text-blue-600',
        'green'  => 'text-green-600',
        'teal'   => 'text-teal-600',
        'purple' => 'text-purple-600',
        default  => 'text-emerald-600',
    };

    $base = 'inline-flex items-center justify-center gap-2 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest italic transition-all no-underline cursor-pointer border-none';

    $classes = $variant === 'secondary'
        ? "{$base} bg-white border border-slate-200 {$accentText} hover:bg-slate-50 shadow-sm"
        : "{$base} bg-slate-900 text-white {$hover} shadow-xl";
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if($icon)<i class="fa-solid {{ $icon }}"></i>@endif {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if($icon)<i class="fa-solid {{ $icon }}"></i>@endif {{ $slot }}
    </button>
@endif
