{{-- Bulle d'infos d'une étape de protocole : mode d'application + précautions/conseils.
     Popover Alpine, n'affiche le bouton que si l'étape porte une info utile
     (method ou notes). Variable attendue : $item (CropProtocolItem).
     Optionnel : $align ('right' par défaut) pour le sens d'ouverture. --}}
@php
    $align = $align ?? 'right';
    $hasInfo = $item && ($item->method || $item->notes);
@endphp
@if($hasInfo)
<div x-data="{ open: false }" class="relative inline-block not-italic">
    <button type="button" @click="open = !open" @click.outside="open = false"
            class="w-6 h-6 rounded-full bg-sky-50 text-sky-500 hover:bg-sky-100 transition flex items-center justify-center shrink-0"
            :class="open && 'ring-2 ring-sky-200'"
            title="{{ __('Conseils & mode d\'application') }}">
        <i class="fa-solid fa-circle-info text-[11px]"></i>
    </button>
    <div x-show="open" x-cloak x-transition
         class="absolute {{ $align === 'right' ? 'right-0' : 'left-0' }} top-8 z-30 w-72 bg-white rounded-[1.5rem] border border-slate-100 shadow-2xl p-5 text-left">
        <p class="text-[8px] font-black text-sky-500 uppercase tracking-widest italic mb-3 flex items-center gap-1.5">
            <i class="fa-solid fa-circle-info"></i> {{ __("Conseils & application") }}
        </p>
        @if($item->method)
            <div class="mb-3">
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Mode d'application") }}</p>
                <p class="text-[11px] font-bold text-slate-700">{{ $item->method }}</p>
            </div>
        @endif
        @if($item->notes)
            <div>
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Précautions & conseils") }}</p>
                <p class="text-[11px] font-medium text-slate-600 leading-relaxed">{{ $item->notes }}</p>
            </div>
        @endif
    </div>
</div>
@endif
