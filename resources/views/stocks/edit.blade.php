<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3">
                    <i class="fa-solid fa-pen-to-square text-xl"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Ajustement Fiche") }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic leading-none">
                        {{ strtoupper($stock->category === 'matiere_premiere' ? 'materiels' : $stock->category) }} • {{ $stock->item_name }}
                    </p>
                </div>
            </div>
            <a href="{{ route('stocks.index', ['category' => $stock->category]) }}" class="text-[10px] font-black uppercase italic text-slate-400 hover:text-slate-800 transition-all no-underline">
                <i class="fa-solid fa-arrow-left mr-1"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    {{-- Initialisation AlpineJS --}}
    <div class="py-12 italic font-bold text-left" x-data="{ 
        cat: '{{ $stock->category }}', 
        qty: {{ old('current_quantity', $stock->current_quantity) }},
        unit: '{{ $stock->unit }}',
        consoType: '{{ $stock->getMeta('conso_type', 'Aliment') }}',
        poultryType: '{{ $stock->getMeta('poultry_type', 'Chair') }}',
        get units() {
            if (this.cat === 'oeufs') return ['Alvéole', 'Unité'];
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
            
            {{-- PROTECTION PERMISSION MODIFICATION (M) --}}
            @can('logistique.M')
                @if ($errors->any())
                    <div class="mb-6 p-6 bg-red-600 text-white rounded-[2.5rem] shadow-lg text-[10px] font-black uppercase italic animate-pulse">
                        <p class="mb-2 border-b border-white/20 pb-2">⚠️ {{ __("Erreur(s) détectée(s)") }} :</p>
                        <ul class="list-disc ml-5">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
                    </div>
                @endif

                <div class="bg-white p-12 rounded-[4rem] shadow-xl border border-slate-100 relative overflow-hidden">
                    <form action="{{ route('stocks.update', $stock->id) }}" method="POST" class="space-y-8 relative z-10">
                        @csrf
                        @method('PUT')
                        
                        {{-- Logique de synchronisation --}}
                        @php 
                            $isSynced = ($stock->category === 'oeufs') || 
                                        ($stock->category === 'conso' && (Str::contains($stock->item_name, ['Chair', 'Ponte']) || in_array($stock->item_name, ['Démarrage', 'Croissance', 'Ponte', 'Finition'])));
                        @endphp

                        {{-- 1. SECTION SECTEUR (ALIMENTS) --}}
                        <template x-if="cat === 'conso' && consoType === 'Aliment'">
                            <div class="grid grid-cols-2 gap-4 mb-8">
                                <button type="button" @click="poultryType = 'Chair'" 
                                    :class="poultryType === 'Chair' ? 'bg-slate-900 text-white shadow-lg' : 'bg-slate-50 text-slate-400'"
                                    class="py-4 rounded-2xl text-[10px] font-black uppercase italic transition-all">
                                    <i class="fa-solid fa-feather mr-2"></i> {{ __("Secteur Chair") }}
                                </button>
                                <button type="button" @click="poultryType = 'Ponte'"
                                    :class="poultryType === 'Ponte' ? 'bg-emerald-600 text-white shadow-lg' : 'bg-slate-50 text-slate-400'"
                                    class="py-4 rounded-2xl text-[10px] font-black uppercase italic transition-all">
                                    <i class="fa-solid fa-egg mr-2"></i> {{ __("Secteur Ponte") }}
                                </button>
                                <input type="hidden" name="metadata[poultry_type]" :value="poultryType">
                                <input type="hidden" name="metadata[conso_type]" :value="consoType">
                            </div>
                        </template>

                        {{-- 2. DÉSIGNATION (AVEC LOCK VISUEL) --}}
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">{{ __("Désignation de l'article") }}</label>
                            @if($isSynced)
                                <div class="w-full bg-slate-50 border-2 border-slate-100 rounded-[2rem] p-5 font-black uppercase text-sm text-slate-400 shadow-inner flex justify-between items-center cursor-not-allowed italic">
                                    {{ $stock->item_name }}
                                    <i class="fa-solid fa-lock text-[10px] opacity-20"></i>
                                </div>
                                <input type="hidden" name="item_name" value="{{ $stock->item_name }}">
                            @else
                                <input type="text" name="item_name" value="{{ old('item_name', $stock->item_name) }}" required
                                       class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-sm focus:ring-2 focus:ring-blue-500 transition-all shadow-inner italic">
                            @endif
                        </div>

                        {{-- 3. AJUSTEMENT DE STOCK --}}
                        <div class="bg-slate-900 p-8 rounded-[3rem] shadow-2xl relative">
                            <label class="text-[10px] uppercase text-emerald-400 mb-3 block tracking-widest font-black italic leading-none">⚖️ {{ __("Correction de l'Inventaire Physique") }}</label>
                            <div class="flex items-center gap-4">
                                <input type="number" min="0" name="current_quantity" step="0.001" x-model="qty"
                                       class="w-full bg-white/10 border-none rounded-2xl p-4 font-black text-4xl text-white focus:ring-2 focus:ring-emerald-500 transition-all text-center">
                                <span class="text-slate-500 uppercase text-xs italic font-black" x-text="unit"></span>
                            </div>

                            {{-- Tooltip conversion Œufs --}}
                            <template x-if="unit === 'Alvéole'">
                                <div class="mt-4 p-4 bg-white/5 rounded-2xl border border-white/5 flex justify-between items-center">
                                    <p class="text-[9px] text-slate-500 uppercase italic font-black">{{ __("Lecture inventaire") }} :</p>
                                    <p class="text-xs text-blue-400 font-black italic tracking-tighter uppercase">
                                        <span x-text="Math.floor(qty)"></span> {{ __("Plateaux") }} +
                                        <span x-text="Math.round((qty - Math.floor(qty)) * setting('general.eggs_per_tray', 30))"></span> {{ __("Œufs") }}
                                    </p>
                                </div>
                            </template>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
                            {{-- UNITÉ --}}
                            <div>
                                <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">{{ __("Unité de mesure") }}</label>
                                @if($isSynced || $stock->category === 'litieres')
                                    <div class="w-full bg-slate-50 border border-slate-100 rounded-[2rem] p-5 font-black uppercase text-xs text-slate-400 shadow-inner cursor-not-allowed italic">
                                        {{ $stock->unit }}
                                    </div>
                                    <input type="hidden" name="unit" value="{{ $stock->unit }}">
                                @else
                                    <select name="unit" x-model="unit" class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black uppercase text-[10px] italic focus:ring-2 focus:ring-blue-500 shadow-inner appearance-none cursor-pointer">
                                        <template x-for="u in units" :key="u">
                                            <option :value="u" x-text="u" :selected="u === unit"></option>
                                        </template>
                                    </select>
                                @endif
                            </div>

                            {{-- SEUIL D'ALERTE --}}
                            <div>
                                <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">{{ __("Seuil critique d'alerte") }}</label>
                                <input type="number" name="alert_threshold" step="0.01" value="{{ old('alert_threshold', $stock->alert_threshold) }}" required
                                        class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black focus:ring-2 focus:ring-red-500 shadow-inner text-center italic">
                            </div>

                            {{-- PRIX UNITAIRE (NOUVEAU) --}}
                            <div>
                                <label class="text-[10px] uppercase text-slate-400 ml-6 mb-2 block tracking-widest font-black italic">{{ __("Prix Unitaire (GNF)") }}</label>
                                {{-- On utilise last_unit_price ou unit_price selon le nom de ta colonne en BDD --}}
                                <input type="number" name="unit_price" step="1" value="{{ old('unit_price', $stock->unit_price ?? $stock->last_unit_price ?? 0) }}" required
                                        class="w-full bg-slate-50 border-none rounded-[2rem] p-5 font-black text-blue-500 focus:ring-2 focus:ring-blue-500 shadow-inner text-center italic">
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="w-full bg-slate-900 text-white py-8 rounded-[3.5rem] font-black uppercase italic shadow-2xl hover:bg-emerald-600 transition-all flex items-center justify-center gap-4 tracking-[0.2em] text-xs group">
                                {{ __("Appliquer les modifications") }}
                                <i class="fa-solid fa-circle-check text-emerald-400 group-hover:scale-125 transition-transform"></i>
                            </button>
                        </div>
                    </form>

                    <i class="fa-solid fa-boxes-stacked absolute -right-10 -bottom-10 text-slate-50 text-[18rem] -rotate-12 pointer-events-none opacity-50"></i>
                </div>
            @else
                {{-- ACCÈS REFUSÉ (M MANQUANT) --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Refusé") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none">{{ __("La permission") }} <span class="text-orange-500">stocks.M</span> ({{ __("Modifier") }}) {{ __("est requise pour ajuster l'inventaire.") }}</p>
                    <a href="{{ route('stocks.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-emerald-500 transition-all">{{ __("Retour au Stock") }}</a>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>