<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-book text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $recipe->name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ $recipe->type_label }} <i class="fa-solid fa-arrow-right-long mx-1"></i> {{ $recipe->output_product }}</p>
                </div>
            </div>
            <div class="flex gap-3 items-center">
                <a href="{{ route('crop-recipes.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Recettes") }}
                </a>
                @can('cultures.M')
                <a href="{{ route('crop-recipes.edit', $recipe) }}" class="bg-white border border-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-50 transition italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-pen text-green-500"></i>{{ __("Modifier") }}
                </a>
                @endcan
                @can('cultures.S')
                <form action="{{ route('crop-recipes.destroy', $recipe) }}" method="POST" onsubmit="return confirm('Supprimer cette recette ?')">
                    @csrf @method('DELETE')
                    <button class="text-rose-400 hover:text-rose-600 text-[10px] font-black uppercase italic"><i class="fa-solid fa-trash mr-1"></i>{{ __("Supprimer") }}</button>
                </form>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">
            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Rendement attendu") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $recipe->expected_yield_percent ? number_format($recipe->expected_yield_percent, 0).'%' : '—' }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Conservation") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $recipe->shelf_life_days ? $recipe->shelf_life_days.' j' : '—' }}</p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Coût de réf.") }}</p>
                    <p class="text-2xl font-black leading-none">{{ $recipe->estimated_cost ? number_format($recipe->estimated_cost, 0, ',', ' ') : '—' }} <small class="text-[9px] opacity-40">GNF</small></p>
                </div>
            </div>

            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-700 tracking-widest italic mb-6">{{ __("Intrants") }}</h3>
                @forelse($recipe->items as $item)
                    <div class="flex items-center justify-between p-4 mb-2 bg-slate-50 rounded-[1.5rem]">
                        <p class="text-[12px] font-black uppercase text-slate-800 italic">{{ $item->input_product }}</p>
                        <p class="text-[12px] font-black text-green-600">{{ number_format($item->quantity, 2, ',', ' ') }} <small class="text-[9px] opacity-50">{{ $item->unit }}</small></p>
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-6">{{ __("Aucun intrant défini") }}</p>
                @endforelse
            </div>

            @if($recipe->notes)
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-[11px] text-slate-600 not-italic">{{ $recipe->notes }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
