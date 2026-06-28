<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Protocoles / Itinéraires techniques')" :subtitle="__('Calendriers culturaux de référence')" icon="fa-list-check" accent="green">
            <x-slot name="actions">
                @can('cultures.C')
                <a href="{{ route('crop-protocols.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvel itinéraire") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
        @include('cultures.partials.hub-tabs')
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">
            <x-flash />


            {{-- ESPÈCES SANS ITINÉRAIRE --}}
            @if($uncoveredSpecies->isNotEmpty())
                <div x-data="{ open: false }" class="bg-amber-50 border border-amber-100 rounded-[2rem] overflow-hidden">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between gap-4 p-5 text-left">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-amber-100 text-amber-500 flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-triangle-exclamation text-[11px]"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase text-amber-700 italic tracking-widest leading-none">{{ __("Espèces sans itinéraire") }}</p>
                                <p class="text-[9px] font-bold text-amber-500 italic mt-0.5">{{ $uncoveredSpecies->count() }} {{ __("espèce(s) du catalogue non couvertes") }}</p>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-down text-amber-400 text-[10px] transition-transform" :class="open && 'rotate-180'"></i>
                    </button>

                    <div x-show="open" x-cloak x-transition class="px-5 pb-5">
                        @php
                            $byType = $uncoveredSpecies->groupBy('type');
                            $typeLabels = [
                                'cereale'     => 'Céréales',
                                'tubercule'   => 'Tubercules',
                                'legumineuse' => 'Légumineuses',
                                'maraicher'   => 'Maraîchers',
                                'fruitier'    => 'Fruitiers',
                                'oleagineux'  => 'Oléagineux',
                                'legume'      => 'Légumes feuillus',
                                'epice'       => 'Épices & aromates',
                                'autre'       => 'Autres',
                            ];
                        @endphp
                        <div class="space-y-4">
                            @foreach($byType as $type => $species)
                                <div>
                                    <p class="text-[8px] font-black uppercase text-amber-600 tracking-widest italic mb-2">
                                        {{ $typeLabels[$type] ?? ucfirst($type) }}
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($species as $sp)
                                            @can('cultures.C')
                                            <a href="{{ route('crop-protocols.create', ['crop_name' => $sp->name]) }}"
                                               class="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-amber-200 rounded-2xl text-[9px] font-black uppercase text-slate-700 hover:border-green-400 hover:text-green-700 transition no-underline italic group">
                                                {{ $sp->name }}
                                                <i class="fa-solid fa-plus text-[8px] text-amber-300 group-hover:text-green-500 transition"></i>
                                            </a>
                                            @else
                                            <span class="px-3 py-1.5 bg-white border border-amber-200 rounded-2xl text-[9px] font-black uppercase text-slate-500 italic">
                                                {{ $sp->name }}
                                            </span>
                                            @endcan
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @can('cultures.C')
                        <p class="text-[8px] font-bold text-amber-400 italic mt-4 not-italic">
                            <i class="fa-solid fa-circle-info mr-1"></i>{{ __("Cliquez sur une espèce pour créer son itinéraire.") }}
                        </p>
                        @endcan
                    </div>
                </div>
            @endif

            @forelse($protocols as $protocol)
                <a href="{{ route('crop-protocols.show', $protocol) }}" class="block bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm hover:shadow-md transition no-underline">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-11 h-11 rounded-2xl flex items-center justify-center bg-green-50 text-green-600 shadow-inner">
                                <i class="fa-solid fa-seedling"></i>
                            </div>
                            <div>
                                <p class="text-[13px] font-black uppercase text-slate-800 italic leading-none">{{ $protocol->name }}</p>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest italic mt-1.5">
                                    {{ $protocol->crop_name ?: __('Générique') }}
                                    <span class="text-slate-300">•</span> {{ $protocol->zone_label }}
                                    @if($protocol->source)<span class="text-slate-300">•</span> {{ $protocol->source }}@endif
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-6">
                            <div class="text-right">
                                <p class="text-2xl font-black text-slate-900 leading-none">{{ $protocol->items_count }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic">{{ __("étapes") }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-black text-green-600 leading-none">{{ $protocol->cycles_count }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic">{{ __("cycles") }}</p>
                            </div>
                            @unless($protocol->is_active)
                                <span class="text-[8px] font-black uppercase bg-slate-100 text-slate-500 px-3 py-1 rounded-full italic">{{ __("Inactif") }}</span>
                            @endunless
                        </div>
                    </div>
                </a>
            @empty
                <div class="bg-white p-12 rounded-[3rem] border border-slate-100 shadow-sm text-center">
                    <i class="fa-solid fa-list-check text-4xl text-slate-200 mb-4"></i>
                    <p class="text-slate-400 text-[11px] font-black uppercase italic">{{ __("Aucun itinéraire technique") }}</p>
                    <p class="text-slate-300 text-[10px] font-bold italic mt-2 not-italic">{{ __("Créez un calendrier cultural de référence à rattacher à vos cycles.") }}</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
