<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-list-check text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Protocoles / Itinéraires techniques") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Calendriers culturaux de référence") }}</p>
                </div>
            </div>
            <div class="flex gap-3 items-center">
                <a href="{{ route('cultures.dashboard') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Cultures") }}
                </a>
                @can('cultures.C')
                <a href="{{ route('crop-protocols.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvel itinéraire") }}
                </a>
                @endcan
            </div>
        </div>
        @include('cultures.partials.hub-tabs')
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">
            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="p-5 bg-rose-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg"></i> {{ session('error') }}
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
