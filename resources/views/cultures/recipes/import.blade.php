<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-file-import text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Importer des Recettes") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Import CSV des recettes de transformation") }}</p>
                </div>
            </div>
            <a href="{{ route('crop-recipes.index') }}" class="bg-slate-900 text-white px-6 py-3 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-xl italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-arrow-left"></i> {{ __("Retour") }}
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            @if($errors->any())
                <div class="p-5 bg-rose-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl italic">
                    <i class="fa-solid fa-circle-exclamation mr-3 text-lg"></i>
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            {{-- FORMAT ATTENDU --}}
            <div class="bg-slate-50 rounded-[2rem] p-6 border border-slate-100">
                <h3 class="text-[10px] font-black text-slate-700 uppercase tracking-widest mb-3 italic">{{ __("Format CSV attendu") }}</h3>
                <p class="text-[9px] text-slate-500 uppercase tracking-widest mb-2 font-bold">{{ __("En-tête (ligne 1) : name, transformation_type, output_product, description, ingredients") }}</p>
                <div class="bg-white rounded-xl p-4 font-mono text-[10px] text-slate-600 border border-slate-100">
                    <p class="text-green-600 font-black">name,transformation_type,output_product,description,ingredients</p>
                    <p>Gari de Manioc,fermentation,Gari,Farine de manioc fermentée,Manioc:50:kg;Eau:10:L</p>
                    <p>Jus de Mangue,jus,Jus mangue,,Mangue:20:kg;Sucre:2:kg</p>
                    <p>Farine de Maïs,mouture,Farine maïs,,Maïs:100:kg</p>
                </div>
                <div class="mt-4 space-y-2">
                    <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">{{ __("Types valides :") }}</p>
                    <div class="flex flex-wrap gap-2 mt-1">
                        @foreach($types as $key => $label)
                            <span class="px-3 py-1 bg-green-50 text-green-700 rounded-full text-[9px] font-black uppercase">{{ $key }}</span>
                        @endforeach
                    </div>
                    <p class="text-[9px] text-slate-400 mt-2 not-italic normal-case font-medium">{{ __("Format ingrédients : nom:quantité:unité, séparés par des points-virgules. La colonne 'ingredients' est optionnelle.") }}</p>
                </div>
            </div>

            {{-- FORMULAIRE --}}
            <form action="{{ route('crop-recipes.import.store') }}" method="POST" enctype="multipart/form-data" class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm space-y-6">
                @csrf

                <div>
                    <label for="file" class="block text-[9px] font-black text-slate-600 uppercase tracking-widest mb-2 italic">{{ __("Fichier CSV") }} *</label>
                    <input
                        type="file"
                        id="file"
                        name="file"
                        accept=".csv,.txt"
                        required
                        class="w-full text-[11px] font-bold text-slate-700 border border-slate-200 rounded-2xl px-5 py-4 focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                </div>

                <button type="submit" class="w-full bg-green-600 text-white py-4 rounded-[2rem] font-black text-[11px] uppercase tracking-widest hover:bg-green-700 transition-all shadow-xl italic">
                    <i class="fa-solid fa-upload mr-2"></i> {{ __("Lancer l'importation") }}
                </button>
            </form>

        </div>
    </div>
</x-app-layout>
