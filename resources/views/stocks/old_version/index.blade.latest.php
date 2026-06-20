<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-4 text-left">
                <div class="w-12 h-12 bg-slate-900 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3">
                    <i class="fa-solid fa-box-archive text-xl"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">📦 Gestion des Stocks</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic leading-none">Inventaire Catégorie : {{ $category }}</p>
                </div>
                
                {{-- Permission C : Ajout d'article --}}
                @can('logistique.C')
                <a href="{{ route('stocks.create', ['category' => $category]) }}" class="bg-emerald-500 text-white p-3 rounded-xl shadow-lg shadow-emerald-500/20 hover:scale-110 transition-all ml-2 group no-underline">
                    <i class="fa-solid fa-plus group-hover:rotate-90 transition-transform duration-300"></i>
                </a>
                @endcan
            </div>
            
            <div class="flex items-center gap-4">
                {{-- Permission M : Synchronisation ERP --}}
                @can('logistique.M')
                <form action="{{ route('stocks.syncAll') }}" method="POST">
                    @csrf
                    <button type="submit" class="p-3 bg-white border border-slate-200 text-slate-400 rounded-xl hover:text-emerald-500 hover:border-emerald-200 transition-all group shadow-sm flex items-center gap-2 italic">
                        <i class="fa-solid fa-arrows-rotate group-active:rotate-180 transition-transform"></i>
                        <span class="text-[8px] font-black uppercase italic pr-2">Sync. ERP</span>
                    </button>
                </form>
                @endcan

                <div class="flex bg-slate-100 p-1 rounded-2xl border border-slate-200">
                    @foreach(['oeufs', 'conso', 'litieres', 'materiels'] as $cat)
                        <a href="{{ route('stocks.index', ['category' => $cat]) }}" 
                           @class(['px-6 py-2 rounded-xl text-[10px] font-black uppercase italic transition-all no-underline', 
                                   'bg-white text-slate-900 shadow-sm' => $category == $cat,
                                   'text-slate-400 hover:text-slate-600' => $category != $cat])>
                            {{ $cat }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
                
                {{-- SECTION PRINCIPALE (L) --}}
                <div class="lg:col-span-3 space-y-12">
                    
                    @if($category === 'conso')
                        {{-- 1. ALIMENTATION --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                            {{-- CHAIR --}}
                            <div class="space-y-6">
                                <div class="flex items-center gap-3 px-6 border-l-4 border-slate-900">
                                    <i class="fa-solid fa-feather-pointed text-slate-900 text-xl"></i>
                                    <h3 class="text-sm font-black uppercase italic tracking-widest text-slate-900">Alimentation Chair</h3>
                                </div>
                                @forelse($stocks->filter(fn($s) => ($s->metadata['poultry_type'] ?? '') === 'Chair' && ($s->metadata['conso_type'] ?? '') === 'Aliment') as $item)
                                    @include('stocks.partials.card', ['item' => $item])
                                @empty
                                    <p class="text-[10px] text-slate-300 uppercase italic px-6 leading-none">Aucun aliment chair référencé</p>
                                @endforelse
                            </div>

                            {{-- PONTE / REPRO --}}
                            <div class="space-y-6">
                                <div class="flex items-center gap-3 px-6 border-l-4 border-emerald-500">
                                    <i class="fa-solid fa-egg text-emerald-500 text-xl"></i>
                                    <h3 class="text-sm font-black uppercase italic tracking-widest text-emerald-600">Alimentation Ponte & Repro</h3>
                                </div>
                                @forelse($stocks->filter(fn($s) => in_array($s->metadata['poultry_type'] ?? '', ['Ponte', 'Reproducteur']) && ($s->metadata['conso_type'] ?? '') === 'Aliment') as $item)
                                    @include('stocks.partials.card', ['item' => $item])
                                @empty
                                    <p class="text-[10px] text-slate-300 uppercase italic px-6 leading-none">Aucun aliment ponte référencé</p>
                                @endforelse
                            </div>
                        </div>

                        {{-- 2. SANTÉ ET PHARMACIE --}}
                        <div class="pt-8 border-t border-slate-100">
                            <div class="flex items-center gap-3 px-6 mb-6">
                                <i class="fa-solid fa-kit-medical text-blue-500 text-xl"></i>
                                <h3 class="text-sm font-black uppercase italic tracking-widest text-blue-600">Santé & Pharmacie</h3>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                @forelse($stocks->filter(fn($s) => ($s->metadata['conso_type'] ?? '') === 'Santé') as $item)
                                    @include('stocks.partials.card', ['item' => $item])
                                @empty
                                    <p class="text-[10px] text-slate-300 uppercase italic px-6">Stock pharmacie vide</p>
                                @endforelse
                            </div>
                        </div>
                        {{-- 3. SECTION HYGIÈNE ET ENTRETIEN --}}
                        <div class="pt-8 border-t border-slate-100">
                            <div class="flex items-center gap-3 px-6 mb-6">
                                <i class="fa-solid fa-soap text-cyan-500 text-xl"></i>
                                <h3 class="text-sm font-black uppercase italic tracking-widest text-cyan-600">Hygiène & Entretien</h3>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                @forelse($stocks->filter(fn($s) => ($s->metadata['conso_type'] ?? '') === 'Hygiène') as $item)
                                    @include('stocks.partials.card', ['item' => $item])
                                @empty
                                    <p class="text-[10px] text-slate-300 uppercase italic px-6">Aucun produit d'entretien</p>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            @foreach($stocks as $item)
                                @include('stocks.partials.card', ['item' => $item])
                            @endforeach
                        </div>
                    @endif

                    {{-- HISTORIQUE DES MOUVEMENTS --}}
                    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden mt-12">
                        <div class="p-8 border-b border-slate-50 bg-slate-50/30 flex justify-between items-center">
                            <h4 class="text-xs font-black uppercase italic tracking-widest text-slate-400">Flux Récents ({{ $category }})</h4>
                            <span class="px-4 py-1.5 bg-slate-900 text-white rounded-full text-[8px] font-black uppercase italic tracking-widest">{{ $recentMovements->count() }} Mouvements</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="text-[8px] font-black text-slate-300 uppercase tracking-widest italic bg-slate-50/20">
                                        <th class="px-8 py-4">Horodatage</th>
                                        <th class="px-4 py-4 text-center">Type</th>
                                        <th class="px-4 py-4 text-center">Volume</th>
                                        <th class="px-8 py-4 text-right">Observation</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    @foreach($recentMovements as $mov)
                                        <tr class="hover:bg-slate-50/50 transition-all font-bold italic">
                                            <td class="px-8 py-5 text-[9px] font-black uppercase text-slate-400">
                                                {{ $mov->created_at->format('d/m H:i') }}
                                            </td>
                                            <td class="px-4 py-5 text-center">
                                                <span @class([
                                                    'text-[7px] px-2 py-1 rounded-md uppercase italic font-black',
                                                    'bg-emerald-50 text-emerald-600' => $mov->type === 'in',
                                                    'bg-rose-50 text-rose-600' => $mov->type === 'out',
                                                    'bg-amber-50 text-amber-600' => $mov->type === 'adjustment',
                                                ])>
                                                    {{ $mov->type === 'in' ? 'Entrée' : ($mov->type === 'out' ? 'Sortie' : 'Ajustement') }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-5 text-center text-[10px] tracking-tighter">
                                                {{ $mov->formatted_quantity }}
                                            </td>
                                            <td class="px-8 py-5 text-right text-[9px] text-slate-400 tracking-tight italic">
                                                {{ $mov->notes }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- FORMULAIRE EXPRESS (C) --}}
                <div class="lg:col-span-1">
                    @can('logistique.C')
                    <div class="bg-slate-900 p-10 rounded-[4rem] shadow-2xl sticky top-24 relative overflow-hidden group"
                        x-data="{ 
                            selectedUnit: '', 
                            inputQty: 0,
                            itemBaseUnit: '',
                            itemCategory: '',
                            updateItem() {
                                const sel = $refs.stockSelect;
                                const opt = sel.options[sel.selectedIndex];
                                this.itemBaseUnit = opt.getAttribute('data-unit');
                                this.itemCategory = opt.getAttribute('data-category');
                                this.selectedUnit = this.itemBaseUnit;
                            },
                            get finalQty() { 
                                if (this.itemCategory === 'conso' && this.itemBaseUnit === 'KG' && this.selectedUnit === 'Sac') {
                                    return (this.inputQty * 50);
                                }
                                return this.inputQty;
                            }
                        }" x-init="updateItem()">
                        
                        <h3 class="text-lg font-black uppercase mb-8 italic text-white tracking-tighter flex items-center gap-2">
                            <i class="fa-solid fa-bolt-lightning text-amber-400"></i> Flux Express
                        </h3>
                        
                        <form action="{{ route('stocks.move') }}" method="POST" class="space-y-6">
                            @csrf
                            <div>
                                <label class="text-[9px] uppercase text-slate-500 ml-4 font-black tracking-[0.2em] mb-2 block italic text-left">Article Cible</label>
                                <select name="stock_id" x-ref="stockSelect" @change="updateItem()"
                                        class="w-full bg-slate-800 border-none rounded-2xl p-4 font-black uppercase text-xs text-white focus:ring-2 focus:ring-emerald-500 italic appearance-none cursor-pointer">
                                    @foreach($stocks as $item)
                                        <option value="{{ $item->id }}" 
                                                data-unit="{{ $item->unit }}"
                                                data-category="{{ $item->category }}">
                                            {{ $item->item_name }} ({{ number_format($item->current_quantity, 1) }} {{ $item->unit }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[9px] uppercase text-slate-500 ml-4 font-black tracking-[0.2em] mb-2 block italic text-left">Mouvement</label>
                                    <select name="type" class="w-full bg-slate-800 border-none rounded-2xl p-4 font-black italic text-xs uppercase text-white shadow-inner appearance-none cursor-pointer">
                                        <option value="in">➕ Entrée</option>
                                        <option value="out">➖ Sortie</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[9px] uppercase text-slate-500 ml-4 font-black tracking-[0.2em] mb-2 block italic text-left">Unité</label>
                                    <select x-model="selectedUnit" class="w-full bg-slate-800 border-none rounded-2xl p-4 font-black italic text-xs uppercase text-white shadow-inner appearance-none cursor-pointer">
                                        <template x-if="itemCategory === 'conso' && itemBaseUnit === 'KG'">
                                            <optgroup label="Gestion au Poids">
                                                <option value="KG">KG</option>
                                                <option value="Sac">Sac (50kg)</option>
                                            </optgroup>
                                        </template>
                                        <template x-if="!(itemCategory === 'conso' && itemBaseUnit === 'KG')">
                                            <optgroup label="Standard">
                                                <option :value="itemBaseUnit" x-text="itemBaseUnit"></option>
                                            </optgroup>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <div class="text-left">
                                <label class="text-[9px] uppercase text-slate-500 ml-4 font-black tracking-[0.2em] mb-2 block italic">Quantité de flux</label>
                                <div class="relative">
                                    <input type="number" x-model="inputQty" step="0.01" min="0" required 
                                        class="w-full bg-slate-800 border-none rounded-2xl p-4 font-black italic text-xl text-emerald-400 placeholder-slate-700" 
                                        placeholder="0.00">
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-[10px] text-slate-500 font-black italic" x-text="selectedUnit"></span>
                                </div>
                                <input type="hidden" name="quantity" :value="finalQty">
                            </div>

                            <div x-show="itemCategory === 'conso' && selectedUnit === 'Sac' && inputQty > 0" 
                                x-transition class="p-4 bg-emerald-500/10 rounded-2xl border border-emerald-500/20 text-center">
                                <p class="text-[10px] text-emerald-400 font-black uppercase italic">
                                    <i class="fa-calculator mr-1"></i> Équivalence : <span x-text="finalQty" class="text-white"></span> KG
                                </p>
                            </div>
                            
                            <button type="submit" class="w-full bg-emerald-500 text-white py-6 rounded-[2.5rem] font-black uppercase italic shadow-xl hover:bg-emerald-400 transition-all flex items-center justify-center gap-2 active:scale-95">
                                Confirmer le Flux <i class="fa-solid fa-circle-check"></i>
                            </button>
                        </form>
                    </div>
                    @else
                    <div class="bg-slate-50 p-10 rounded-[4rem] border border-slate-100 text-center">
                        <i class="fa-solid fa-lock text-slate-200 text-4xl mb-4"></i>
                        <p class="text-[10px] font-black text-slate-400 uppercase italic">Accès restreint aux flux express</p>
                    </div>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>