{{--
    Carte repliable pour les formulaires de saisie des registres : la page
    s'ouvre sur les DONNÉES, la saisie est à un tap. `open` force l'ouverture
    (ex. erreurs de validation → le formulaire se rouvre tout seul).
--}}
@props(['title', 'icon' => 'fa-plus', 'open' => false, 'hint' => null])
<div x-data="{ open: {{ $open ? 'true' : 'false' }} }" class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm mb-6 overflow-hidden">
    <button type="button" @click="open = !open"
        class="w-full flex justify-between items-center px-8 py-5 bg-transparent border-none cursor-pointer text-left hover:bg-slate-50/60 transition-colors">
        <span>
            <span class="text-[10px] font-black uppercase tracking-widest text-rose-600 italic"><i class="fa-solid {{ $icon }} mr-1"></i> {{ $title }}</span>
            @if($hint)
                <span class="block text-[8px] font-black uppercase tracking-widest text-slate-400 mt-1 italic" x-show="!open">{{ $hint }}</span>
            @endif
        </span>
        <i class="fa-solid text-slate-300" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
    </button>
    <div x-show="open" x-transition.opacity.duration.150ms x-cloak class="px-8 pb-8">
        {{ $slot }}
    </div>
</div>
