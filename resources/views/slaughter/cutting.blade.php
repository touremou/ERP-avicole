<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Découpe')" :subtitle="$order->order_number . ' — ' . __('Carcasses disponibles') . ' : ' . ($order->result ? number_format($order->result->total_carcass_weight_kg, 1) . ' kg' : '—')" icon="fa-scissors" accent="rose" :back="route('slaughter.dashboard')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="cuttingForm()" x-cloak>
            
            {{-- 🔒 SÉCURITÉ : Vérification de la permission de Création --}}
            @can('abattoir.C')
                @if($errors->any())
                    <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('slaughter.cutting.store', $order) }}">
                    @csrf
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Poids carcasses entrées (kg)") }} *</label>
                                <input type="number" name="total_input_kg" x-model.number="inputKg" step="0.1" min="0.1" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date") }} *</label>
                                <input type="date" name="session_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                        </div>

                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"><i class="fa-solid fa-scissors text-purple-500"></i> {{ __("Produits de découpe") }}</h3>
                            <button type="button" @click="addProduct()" class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase border-none cursor-pointer"><i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}</button>
                        </div>

                        <template x-for="(p, i) in products" :key="i">
                            <div class="grid grid-cols-12 gap-3 mb-3 items-end p-4 bg-slate-50 rounded-2xl">
                                <div class="col-span-3">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Type") }}</label>
                                    <select :name="'products['+i+'][type]'" x-model="p.type" @change="onProductTypeChange(i)" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        @foreach($cuts as $cut)
                                        <option value="{{ $cut['code'] }}">{{ $cut['label'] }}</option>
                                        @endforeach
                                        <option value="autre">{{ __("Autre") }}</option>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Nom") }}</label>
                                    <input type="text" :name="'products['+i+'][name]'" x-model="p.name" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Poids (kg)") }}</label>
                                    <input type="number" :name="'products['+i+'][kg]'" x-model.number="p.kg" step="0.1" min="0" class="w-full bg-white border-none rounded-xl p-3 text-sm font-black shadow-sm outline-none text-center">
                                </div>
                                <div class="col-span-1">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Pièces") }}</label>
                                    <input type="number" :name="'products['+i+'][pieces]'" x-model.number="p.pieces" min="0" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-center">
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Destination") }}</label>
                                    <select :name="'products['+i+'][destination]'" x-model="p.destination" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        <option value="stock_frais">{{ __("Stock Frais") }}</option>
                                        <option value="stock_congele">{{ __("Congelé") }}</option>
                                        <option value="transformation">{{ __("Transformation") }}</option>
                                        <option value="vente_directe">{{ __("Vente Directe") }}</option>
                                    </select>
                                </div>
                                <div class="col-span-2 flex items-end gap-2">
                                    <input type="number" :name="'products['+i+'][price]'" x-model.number="p.price" min="0" placeholder="{{ __('Prix/kg') }}" class="flex-1 bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-right">
                                    <button type="button" @click="products.splice(i,1)" x-show="products.length > 1" class="text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                        </template>

                        {{-- RÉSUMÉ EN TEMPS RÉEL --}}
                        <div class="mt-6 p-4 bg-slate-900 rounded-2xl text-white grid grid-cols-3 gap-4 text-center">
                            <div><p class="text-[8px] font-black text-emerald-400 uppercase">{{ __("Total sortie") }}</p><p class="text-lg font-black" x-text="totalOutput.toFixed(1) + ' kg'"></p></div>
                            <div><p class="text-[8px] font-black text-amber-400 uppercase">{{ __("Perte") }}</p><p class="text-lg font-black" x-text="loss.toFixed(1) + ' kg (' + lossPercent + '%)'"></p></div>
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Rendement") }} <span class="text-slate-500">/ {{ __("cible") }} <span x-text="cuttingYieldTarget"></span>%</span></p>
                                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : cible de rendement découpe (abattoir.yield_cutting) --}}
                                <p class="text-lg font-black" :class="(100 - parseFloat(lossPercent)) < cuttingYieldTarget ? 'text-red-400' : 'text-emerald-400'" x-text="(100 - parseFloat(lossPercent)).toFixed(1) + '%'"></p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-purple-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-purple-600 transition-all shadow-2xl italic border-none cursor-pointer">
                        <i class="fa-solid fa-scissors mr-2"></i> {{ __("Valider la Découpe") }}
                    </button>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas la permission de déclarer une découpe.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
    function cuttingForm() {
        // ⚙️ NOMENCLATURE DE DÉCOUPE PROPRE À L'ESPÈCE (config/butchery.php)
        const cuts = {{ Js::from($cuts) }};
        const speciesEntierName = {{ Js::from(($order->batch->species?->name_fr ?? 'Poulet') . ' Entier') }};

        // Table code → libellé (le morceau "entier" reprend le nom de l'espèce).
        const names = {};
        cuts.forEach(c => { names[c.code] = c.code === 'entier' ? speciesEntierName : c.label; });

        // Lignes pré-remplies = morceaux marqués "default" dans la nomenclature.
        const defaultProducts = cuts
            .filter(c => c.default)
            .map(c => ({ type: c.code, name: (c.code === 'entier' ? speciesEntierName : c.label), kg: 0, pieces: 0, destination: c.destination, price: 0 }));

        // ⚙️ INJECTION DYNAMIQUE DES SETTINGS
        const lossTolerance = {{ setting('abattoir.tolerance_cutting_loss', 10) }};
        const cuttingYieldTarget = {{ setting('abattoir.yield_cutting', 85) }};

        return {
            inputKg: 0,
            lossTolerance: lossTolerance,
            cuttingYieldTarget: cuttingYieldTarget,
            products: defaultProducts.length ? defaultProducts : [{ type:'autre', name:'', kg:0, pieces:0, destination:'stock_frais', price:0 }],
            get totalOutput() { return this.products.reduce((s,p) => s + (p.kg||0), 0); },
            get loss() { return Math.max(0, this.inputKg - this.totalOutput); },
            get lossPercent() { return this.inputKg > 0 ? (this.loss / this.inputKg * 100).toFixed(1) : '0.0'; },
            addProduct() { this.products.push({ type:'autre', name:'', kg:0, pieces:0, destination:'stock_frais', price:0 }); },
            onProductTypeChange(i) { this.products[i].name = names[this.products[i].type] || ''; },
        }
    }
    </script>
</x-app-layout>