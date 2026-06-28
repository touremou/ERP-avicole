@props([
    'title',
    'subtitle' => null,
    'icon'     => 'fa-layer-group',
    'accent'   => 'emerald',
    'back'     => null,
])

{{--
    En-tête de page standardisé (module production & co.).
    Bulle d'icône + titre + sous-titre, et zone d'actions optionnelle (slot
    `actions`). L'accent colore la bulle/sous-titre par MODULE pour une
    identité visuelle cohérente. Les classes de couleur sont écrites en clair
    ci-dessous (et donc scannées par Tailwind) — ne pas interpoler dynamiquement.
--}}
@php
    $bubble = match ($accent) {
        'cyan'    => 'bg-cyan-600 shadow-cyan-500/20',
        'rose'    => 'bg-rose-600 shadow-rose-500/20',
        'amber'   => 'bg-amber-600 shadow-amber-500/20',
        'blue'    => 'bg-blue-600 shadow-blue-500/20',
        'green'   => 'bg-green-600 shadow-green-500/20',
        'purple'  => 'bg-purple-600 shadow-purple-500/20',
        'teal'    => 'bg-teal-600 shadow-teal-500/20',
        default   => 'bg-emerald-600 shadow-emerald-500/20',
    };
    $subColor = match ($accent) {
        'cyan'    => 'text-cyan-600',
        'rose'    => 'text-rose-600',
        'amber'   => 'text-amber-600',
        'blue'    => 'text-blue-600',
        'green'   => 'text-green-600',
        'purple'  => 'text-purple-600',
        'teal'    => 'text-teal-600',
        default   => 'text-slate-400',
    };
@endphp

<div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 text-left w-full">
    <div class="flex items-center gap-4">
        @if($back)
            <a href="{{ $back }}" class="flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm no-underline flex-shrink-0">
                <i class="fas fa-chevron-left text-xs"></i>
            </a>
        @endif
        <div class="w-10 h-10 md:w-12 md:h-12 {{ $bubble }} rounded-xl md:rounded-2xl flex items-center justify-center text-white shadow-xl rotate-3 transition-transform hover:rotate-0 flex-shrink-0">
            <i class="fa-solid {{ $icon }} text-lg md:text-xl"></i>
        </div>
        <div>
            <h2 class="text-lg md:text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none m-0">{{ $title }}</h2>
            @if($subtitle)
                <p class="text-[9px] md:text-[10px] font-bold {{ $subColor }} uppercase mt-1 tracking-widest italic leading-none m-0">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 md:gap-3 w-full md:w-auto">{{ $actions }}</div>
    @endisset
</div>
