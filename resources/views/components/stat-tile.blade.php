@props([
    'label',
    'value',
    'unit'   => null,
    'accent' => 'slate',   // couleur du libellé
    'dark'   => false,     // tuile sombre (mise en avant)
    'sub'    => null,      // petite mention secondaire sous la valeur
])

{{-- Tuile KPI standardisée. text-{accent}-500 est garanti par le safelist. --}}
@php
    $labelColor = $dark ? 'text-'.$accent.'-400' : 'text-'.$accent.'-500';
@endphp

<div {{ $attributes->merge(['class' => 'p-7 rounded-[2.5rem] shadow-sm ' . ($dark ? 'bg-slate-900 text-white' : 'bg-white border border-slate-100')]) }}>
    <p class="text-[9px] font-black {{ $labelColor }} uppercase tracking-widest mb-2 italic">{{ $label }}</p>
    <p class="text-3xl font-black italic tracking-tighter {{ $dark ? '' : 'text-slate-900' }}">
        {{ $value }}@if($unit) <small class="text-sm {{ $dark ? 'opacity-60' : 'opacity-40' }}">{{ $unit }}</small>@endif
    </p>
    @if($sub)
        <p class="text-[8px] {{ $dark ? 'opacity-60' : 'text-slate-400' }} mt-2 uppercase font-black tracking-widest">{{ $sub }}</p>
    @endif
</div>
