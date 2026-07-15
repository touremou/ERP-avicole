@props([
    'padding' => 'p-6',
    'rounded' => 'rounded-[2.5rem]',
])

{{-- Panneau blanc standard (cartes, sections, listes). Surcharge possible via
     `class="..."` (fusionnée) ou les props `padding` / `rounded`. --}}
<div {{ $attributes->merge(['class' => "bg-white {$padding} {$rounded} border border-slate-100 shadow-sm"]) }}>
    {{ $slot }}
</div>
