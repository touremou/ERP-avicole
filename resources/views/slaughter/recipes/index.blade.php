<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Recettes de désassemblage')" :subtitle="__('BOM inversée : article brut → co-produits / sous-produits / déchets')" icon="fa-diagram-project" accent="rose" :back="route('slaughter.dashboard')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if(session('success'))
                <div class="mb-6 p-5 bg-emerald-50 text-emerald-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-emerald-200">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">{{ session('error') }}</div>
            @endif

            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm divide-y divide-slate-50">
                @foreach($families as $family)
                @php $recipe = $recipes->get($family); @endphp
                <div class="px-8 py-5 flex justify-between items-center">
                    <div>
                        <p class="text-xs font-black text-slate-900 uppercase m-0">{{ ucfirst(str_replace('_', ' ', $family)) }}</p>
                        @if($recipe)
                            <p class="text-[9px] text-slate-400 font-black uppercase m-0">
                                {{ $recipe->name }} — {{ __(":n morceaux", ['n' => $recipe->lines->count()]) }}
                                @if(!$recipe->is_active) · <span class="text-amber-500">{{ __("inactive (repli nomenclature)") }}</span> @endif
                            </p>
                        @else
                            <p class="text-[9px] text-slate-400 font-black uppercase m-0">{{ __("Aucune recette — nomenclature standard utilisée") }}</p>
                        @endif
                    </div>
                    @can('abattoir.M')
                    <div>
                        @if($recipe)
                            <a href="{{ route('slaughter.recipes.edit', $recipe) }}" class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-[8px] uppercase tracking-widest no-underline hover:bg-slate-700">
                                <i class="fa-solid fa-pen mr-1"></i> {{ __("Modifier") }}
                            </a>
                        @else
                            <form method="POST" action="{{ route('slaughter.recipes.seed', $family) }}" class="inline">
                                @csrf
                                <button type="submit" class="bg-rose-500 text-white px-4 py-2 rounded-xl font-black text-[8px] uppercase tracking-widest border-none cursor-pointer hover:bg-rose-600">
                                    <i class="fa-solid fa-wand-magic-sparkles mr-1"></i> {{ __("Créer depuis la nomenclature") }}
                                </button>
                            </form>
                        @endif
                    </div>
                    @endcan
                </div>
                @endforeach
            </div>

            <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-6 ml-4">
                {{ __("La recette active pré-remplit le formulaire de découpe (rendements attendus, conditionnements) et portera la répartition des coûts par valeur.") }}
            </p>
        </div>
    </div>
</x-app-layout>
