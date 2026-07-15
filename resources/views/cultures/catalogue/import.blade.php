<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Importer le Catalogue')" :subtitle="__('Import CSV des espèces et variétés')" icon="fa-file-import" accent="green" />
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
                <p class="text-[9px] text-slate-500 uppercase tracking-widest mb-2 font-bold">{{ __("En-tête (ligne 1) : type, name, varieties") }}</p>
                <div class="bg-white rounded-xl p-4 font-mono text-[10px] text-slate-600 border border-slate-100">
                    <p class="text-green-600 font-black">type,name,varieties</p>
                    <p>cereale,Maïs,Jaune Dentifrice;Hybride 614</p>
                    <p>maraicher,Tomate,Roma;Cherry</p>
                    <p>fruitier,Mangue</p>
                    <p>tubercule,Manioc,Douce;Amère</p>
                </div>
                <div class="mt-4 space-y-2">
                    <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">{{ __("Types valides :") }}</p>
                    <div class="flex flex-wrap gap-2 mt-1">
                        @foreach($types as $key => $meta)
                            <span class="px-3 py-1 bg-green-50 text-green-700 rounded-full text-[9px] font-black uppercase">{{ $key }}</span>
                        @endforeach
                    </div>
                    <p class="text-[9px] text-slate-400 mt-2 not-italic normal-case font-medium">{{ __("La colonne 'varieties' est optionnelle. Séparer les variétés par des points-virgules.") }}</p>
                </div>
            </div>

            {{-- FORMULAIRE --}}
            <form action="{{ route('crop-catalogue.import.store') }}" method="POST" enctype="multipart/form-data" class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm space-y-6">
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
