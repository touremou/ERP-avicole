<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                <i class="fa-solid fa-plus-circle text-emerald-500 mr-2"></i> {{ __("Nouvel Article") }}
            </h2>
            <a href="{{ route('stocks.index', ['category' => $category ?? 'oeufs']) }}" class="text-[10px] font-black uppercase italic text-slate-400 hover:text-slate-800 transition-all leading-none no-underline">
                <i class="fa-solid fa-arrow-left mr-1"></i> {{ __("Retour au Stock") }}
            </a>
        </div>
    </x-slot>

    {{-- Initialisation AlpineJS --}}
    <div class="py-12 italic font-bold text-left" 
         x-data="{ 
            cat: '{{ $category ?? 'oeufs' }}',
            consoType: 'Aliment',
            poultryType: 'Chair',
            get units() {
                if (this.cat === 'oeufs') return ['Alvéole', 'Unité'];
                if (this.cat === 'lait') return ['Litre'];
                if (this.cat === 'produits_finis') return ['KG', 'Pcs', 'Unité'];
                if (this.cat === 'litieres') return ['Sac'];
                if (this.cat === 'materiels' || this.cat === 'matiere_premiere') return ['Pcs', 'Unité', 'Boîte', 'Paquet'];
                if (this.cat === 'conso') {
                    if (this.consoType === 'Aliment') return ['KG', 'Sac'];
                    return ['Unité', 'Litre', 'Boîte', 'Flacon'];
                }
                return ['Unité'];
            }
         }">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            
            {{-- PROTECTION PERMISSION C (CRÉATION) --}}
            @can('logistique.C')
                @if ($errors->any())
                    <div class="mb-6 p-6 bg-red-600 text-white rounded-[2rem] shadow-lg text-[10px] font-black uppercase italic animate-pulse">
                        <p class="mb-2 border-b border-white/20 pb-2">⚠️ {{ __("Erreurs de validation") }} :</p>
                        <ul class="list-disc ml-5">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
                    </div>
                @endif

                <div class="bg-white p-12 rounded-[4rem] shadow-xl border border-slate-100 relative overflow-hidden">
                    {{-- Filigrane décoratif --}}
                    <div class="absolute -right-10 -top-10 text-slate-50 opacity-10 pointer-events-none">
                        <i class="fa-solid fa-box-open text-[15rem]"></i>
                    </div>

                    <form action="{{ route('stocks.store') }}" method="POST" class="space-y-8 relative z-10">
                        @csrf

                        <div class="grid grid-cols-1 gap-8">
                            
                            {{-- SÉLECTION DE LA CATÉGORIE (NOUVEAU) --}}
                            <div class="bg-slate-50 p-6 rounded-[2.5rem] border border-slate-100 shadow-inner">
                                <label class="text-[10px] uppercase text-slate-500 ml-6 mb-2 block tracking-widest italic font-black">00. {{ __("Catégorie de l'article") }}</label>
                                <select name="category" x-model="cat" class="w-full bg-white border-none rounded-[1.5rem] p-5 font-black text-xs uppercase shadow-sm focus:ring-2 focus:ring-blue-500 italic cursor-pointer">
                                    <option value="oeufs">🥚 {{ __("Œufs") }}</option>
                                    <option value="lait">🥛 {{ __("Lait") }}</option>
                                    <option value="produits_finis">🥩 {{ __("Produits finis (viande / poisson)") }}</option>
                                    <option value="conso">🌾 {{ __("Aliment & Santé (Conso)") }}</option>
                                    <option value="litieres">🍂 {{ __("Litières") }}</option>
                                    <option value="materiels">🛠️ {{ __("Matériels") }}</option>
                                </select>
                            </div>

                            {{-- NATURE DU PRODUIT (CONSO UNIQUEMENT) --}}
                            <div x-show="cat === 'conso'" class="bg-orange-50/50 p-6 rounded-[2.5rem] border border-orange-100 shadow-inner">
                                <label class="text-[10px] uppercase text-orange-500 ml-6 mb-2 block tracking-widest italic font-black">01. {{ __("Nature du produit") }}</label>
                                <select name="metadata[conso_type]" x-model="consoType" class="w-full bg-white border-none rounded-[1.5rem] p-5 font-black text-xs uppercase shadow-sm focus:ring-2 focus:ring-orange-500 italic cursor-pointer">
                                    <option value="Aliment">🌾 {{ __("Alimentation (Sacs/KG)") }}</option>
                                    <option value="Santé">💉 {{ __("Santé (Vaccins/Vitamines)") }}</option>
                                    <option value="Hygiène">🧼 {{ __("Hygiène & Entretien") }}</option>
                                </select>
                            </div>

                            {{-- SÉLECTEUR DE SECTEUR (DISSOCIATION SI ALIMENT) --}}
                            <div x-show="cat === 'conso' && consoType === 'Aliment'" class="grid grid-cols-2 gap-4">
                                <button type="button" @click="poultryType = 'Chair'" 
                                    :class="poultryType === 'Chair' ? 'bg-slate-900 text-white shadow-lg' : 'bg-slate-100 text-slate-400'"
                                    class="py-4 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all">
                                    <i class="fa-solid fa-feather mr-2"></i> {{ __("Secteur Chair") }}
                                </button>
                                <button type="button" @click="poultryType = 'Ponte'"
                                    :class="poultryType === 'Ponte' ? 'bg-emerald-600 text-white shadow-lg' : 'bg-slate-100 text-slate-400'"
                                    class="py-4 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all">
                                    <i class="fa-solid fa-egg mr-2"></i> {{ __("Secteur Ponte") }}
                                </button>
                                <input type="hidden" name="metadata[poultry_type]" :value="poultryType">
                            </div>

                            {{-- DÉSIGNATION DE L'ARTICLE --}}
                            <div class="text-left">
                                <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest leading-none font-black italic">02. {{ __("Désignation de l'article") }}</label>

                                {{-- OEUFS --}}
                                <template x-if="cat === 'oeufs'">
                                    <select name="item_name" required class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-sm focus:ring-2 focus:ring-emerald-500 shadow-inner italic appearance-none cursor-pointer">
                                        <option value="">-- {{ __("Choisir le Calibre") }} --</option>
                                        <option value="Calibre S">{{ __("Calibre S") }}</option>
                                        <option value="Calibre M">{{ __("Calibre M") }}</option>
                                        <option value="Calibre L">{{ __("Calibre L") }}</option>
                                        <option value="Calibre XL">{{ __("Calibre XL") }}</option>
                                        <option value="Œufs Cassés">{{ __("Œufs Cassés") }}</option>
                                        <option value="Anomaux / Sales">{{ __("Anomaux / Sales") }}</option>
                                    </select>
                                </template>

                                {{-- ALIMENT CHAIR --}}
                                <template x-if="cat === 'conso' && consoType === 'Aliment' && poultryType === 'Chair'">
                                    <select name="item_name" required class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-sm focus:ring-2 focus:ring-slate-900 shadow-inner italic border-l-8 border-slate-900 appearance-none cursor-pointer">
                                        <option value="">-- {{ __("Aliments Chair") }} --</option>
                                        <option value="Chair Démarrage">{{ __("Chair Démarrage") }}</option>
                                        <option value="Chair Croissance">{{ __("Chair Croissance") }}</option>
                                        <option value="Chair Finition">{{ __("Chair Finition") }}</option>
                                    </select>
                                </template>

                                {{-- ALIMENT PONTE --}}
                                <template x-if="cat === 'conso' && consoType === 'Aliment' && poultryType === 'Ponte'">
                                    <select name="item_name" required class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-sm focus:ring-2 focus:ring-emerald-500 shadow-inner italic border-l-8 border-emerald-500 appearance-none cursor-pointer">
                                        <option value="">-- {{ __("Aliments Ponte") }} --</option>
                                        <option value="Ponte Démarrage (Poussin)">{{ __("Ponte Démarrage (Poussin)") }}</option>
                                        <option value="Ponte Croissance (Poulette)">{{ __("Ponte Croissance (Poulette)") }}</option>
                                        <option value="Ponte 1 (Pic de ponte)">{{ __("Ponte 1 (Pic de ponte)") }}</option>
                                        <option value="Ponte 2 (Entretien)">{{ __("Ponte 2 (Entretien)") }}</option>
                                    </select>
                                </template>

                                {{-- AUTRES (Matériels, Hygiène, Santé, Litières) --}}
                                <template x-if="cat !== 'oeufs' && !(cat === 'conso' && consoType === 'Aliment')">
                                    <input type="text" name="item_name" required
                                        class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-sm focus:ring-2 focus:ring-blue-500 transition-all shadow-inner italic"
                                        placeholder="{{ __('NOM DU MATÉRIEL OU PRODUIT...') }}">
                                </template>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-left">
                                {{-- UNITÉ (DYNAMIQUE) --}}
                                <div>
                                    <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">03. {{ __("Unité") }}</label>
                                    <select name="unit" required class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-[10px] italic focus:ring-2 focus:ring-emerald-500 shadow-inner appearance-none cursor-pointer">
                                        <template x-for="u in units" :key="u">
                                            <option :value="u" x-text="u"></option>
                                        </template>
                                    </select>
                                </div>

                                {{-- FOURNISSEUR --}}
                                <div x-show="cat !== 'oeufs'">
                                    <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest italic font-black">04. {{ __("Fournisseur") }}</label>
                                    <select name="metadata[supplier]" class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black text-[10px] uppercase shadow-inner italic appearance-none cursor-pointer">
                                        <option value="">-- {{ __("Source par défaut") }} --</option>
                                        @foreach($providers ?? [] as $provider)
                                            <option value="{{ $provider->name }}">{{ $provider->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
                                {{-- SEUIL D'ALERTE --}}
                                <div>
                                    <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">05. {{ __("Seuil d'alerte") }}</label>
                                    <input type="number" min="0" placeholder="0.001" name="alert_threshold" step="0.001" required
                                        class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black shadow-inner text-center italic focus:ring-2 focus:ring-red-500"
                                        placeholder="{{ __('QTE MIN') }}">
                                </div>

                                {{-- PRIX UNITAIRE (NOUVEAU) --}}
                                <div>
                                    <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">06. {{ __("Prix Unitaire (GNF)") }}</label>
                                    <input type="number" min="0" name="unit_price" step="1" required
                                        class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black shadow-inner text-center text-blue-500 italic focus:ring-2 focus:ring-blue-500"
                                        placeholder="{{ __('Ex: 1500') }}">
                                </div>

                                {{-- STOCK INITIAL --}}
                                <div class="bg-slate-900 rounded-[2.5rem] p-6 text-white shadow-xl relative overflow-hidden group">
                                    <label class="text-[9px] uppercase text-emerald-400 mb-2 block font-black tracking-widest italic leading-none">{{ __("Stock Initial en Entrée") }}</label>
                                    <input type="number" name="current_quantity" min="0" value="0" step="0.01" 
                                            class="w-full bg-white/10 border-none rounded-[1.2rem] p-3 font-black text-3xl text-emerald-400 shadow-inner text-center focus:ring-2 focus:ring-emerald-500">
                                    <i class="fa-solid fa-boxes-stacked absolute -right-2 -bottom-2 text-white/5 text-5xl group-hover:scale-110 transition-transform"></i>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="w-full bg-slate-900 text-white py-8 rounded-[3rem] font-black uppercase italic shadow-2xl hover:bg-emerald-600 transition-all flex items-center justify-center gap-3 tracking-[0.2em] text-xs group">
                                {{ __("Valider la création") }}
                                <i class="fa-solid fa-circle-check text-emerald-400 group-hover:scale-125 transition-transform"></i>
                            </button>
                        </div>
                    </form>
                </div>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none">{{ __("La permission") }} <span class="text-blue-500">stocks.C</span> ({{ __("Créer") }}) {{ __("est requise pour créer de nouveaux articles.") }}</p>
                    <a href="{{ route('stocks.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-emerald-500 transition-all">{{ __("Retour au Stock") }}</a>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>