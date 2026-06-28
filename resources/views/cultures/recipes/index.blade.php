<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-book text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Recettes de transformation") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Standards d'agro-transformation") }}</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('crop-transformations.index') }}" class="bg-white text-slate-700 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm border border-slate-100 italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-industry text-green-500"></i> {{ __("Transformations") }}
                </a>
                @can('cultures.M')
                <a href="{{ route('crop-recipes.import') }}" class="bg-white text-slate-700 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm border border-slate-100 italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-file-csv text-green-500"></i> {{ __("Importer CSV") }}
                </a>
                @endcan
                @can('cultures.C')
                <a href="{{ route('crop-recipes.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle recette") }}
                </a>
                @endcan
            </div>
        </div>
        @include('cultures.partials.hub-tabs')
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">
            <x-flash />

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($recipes as $r)
                    <a href="{{ route('crop-recipes.show', $r) }}" class="block bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm hover:border-green-200 transition no-underline {{ $r->is_active ? '' : 'opacity-50' }}">
                        <div class="flex items-start justify-between mb-2">
                            <span class="text-[8px] font-black uppercase bg-green-100 text-green-700 px-3 py-1 rounded-full">{{ $types[$r->transformation_type] ?? $r->transformation_type }}</span>
                            @unless($r->is_active)<span class="text-[8px] font-black uppercase text-slate-400">{{ __("Inactive") }}</span>@endunless
                        </div>
                        <p class="text-[13px] font-black uppercase text-slate-800 italic leading-tight">{{ $r->name }}</p>
                        <p class="text-[9px] text-slate-400 uppercase mt-1"><i class="fa-solid fa-arrow-right-long"></i> {{ $r->output_product }}</p>
                        <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-50 text-[9px] text-slate-500 uppercase">
                            <span><i class="fa-solid fa-list"></i> {{ $r->items_count }} {{ __("intrants") }}</span>
                            @if($r->expected_yield_percent)<span class="text-green-600 font-black">{{ number_format($r->expected_yield_percent, 0) }}% rdt</span>@endif
                        </div>
                    </a>
                @empty
                    <div class="md:col-span-3 bg-white p-16 rounded-[3rem] border border-slate-100 text-center">
                        <i class="fa-solid fa-book text-5xl text-slate-200 mb-4"></i>
                        <p class="text-slate-400 text-[11px] font-black uppercase italic">{{ __("Aucune recette") }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
