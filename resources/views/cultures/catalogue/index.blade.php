<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-book-open text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Catalogue des cultures") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Espèces & variétés — contexte guinéen") }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                @can('cultures.M')
                <a href="{{ route('crop-catalogue.import') }}" class="bg-white text-slate-700 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm border border-slate-100 italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-file-csv text-green-500"></i> {{ __("Importer CSV") }}
                </a>
                @endcan
                @can('cultures.C')
                <a href="{{ route('crop-catalogue.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle culture") }}
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- INDICATEURS --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Cultures") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['species'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Variétés") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['varieties'] }}</p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Types") }}</p>
                    <p class="text-3xl font-black leading-none">{{ $stats['families'] }}</p>
                </div>
            </div>

            @forelse($grouped as $type => $list)
                @php $meta = \App\Models\CropSpecies::TYPES[$type] ?? ['label' => ucfirst($type), 'icon' => 'fa-sprout', 'color' => 'slate']; @endphp
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[11px] font-black uppercase text-slate-700 tracking-widest italic mb-6 flex items-center gap-2">
                        <i class="fa-solid {{ $meta['icon'] }} text-{{ $meta['color'] }}-500"></i>
                        {{ $meta['label'] }} <span class="text-slate-300">({{ $list->count() }})</span>
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($list as $sp)
                            <a href="{{ route('crop-catalogue.show', $sp) }}" class="block p-4 bg-slate-50 rounded-[1.5rem] hover:bg-green-50 transition no-underline border border-transparent hover:border-green-200">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-[12px] font-black uppercase text-slate-800 italic leading-tight">{{ $sp->name }}</p>
                                    @if($sp->varieties_count > 0)
                                        <span class="shrink-0 text-[8px] font-black uppercase bg-{{ $meta['color'] }}-100 text-{{ $meta['color'] }}-700 px-2 py-1 rounded-full">{{ $sp->varieties_count }} var.</span>
                                    @endif
                                </div>
                                @if($sp->local_name)<p class="text-[9px] text-slate-400 italic mt-1">🗣 {{ $sp->local_name }}</p>@endif
                                @if($sp->family)<p class="text-[9px] text-slate-400 uppercase mt-0.5">{{ $sp->family }}</p>@endif
                                <div class="flex flex-wrap gap-x-3 mt-2 text-[9px] text-slate-500 uppercase">
                                    @if($sp->cycle_label)<span><i class="fa-regular fa-clock"></i> {{ $sp->cycle_label }}</span>@endif
                                    @if($sp->avg_yield_tha)<span><i class="fa-solid fa-weight-hanging"></i> {{ rtrim(rtrim(number_format($sp->avg_yield_tha, 2, ',', ' '), '0'), ',') }} t/ha</span>@endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="bg-white p-16 rounded-[3rem] border border-slate-100 text-center">
                    <i class="fa-solid fa-seedling text-5xl text-slate-200 mb-4"></i>
                    <p class="text-slate-400 text-[11px] font-black uppercase italic">{{ __("Catalogue vide") }}</p>
                    @can('cultures.C')
                    <a href="{{ route('crop-catalogue.create') }}" class="inline-block mt-4 text-green-600 text-[10px] font-black uppercase italic no-underline">{{ __("Ajouter une première culture") }}</a>
                    @endcan
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
