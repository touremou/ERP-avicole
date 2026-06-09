<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('slaughter.dashboard') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Découpe</h2>
                <p class="text-[10px] font-black text-purple-600 uppercase tracking-[0.2em] mt-2 italic">{{ $order->order_number }} — Carcasses disponibles : {{ $order->result ? number_format($order->result->total_carcass_weight_kg, 1) . ' kg' : '—' }}</p>
            </div>
        </div>
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
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Poids carcasses entrées (kg) *</label>
                                <input type="number" name="total_input_kg" x-model.number="inputKg" step="0.1" min="0.1" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Date *</label>
                                <input type="date" name="session_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                        </div>

                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"><i class="fa-solid fa-scissors text-purple-500"></i> Produits de découpe</h3>
                            <button type="button" @click="addProduct()" class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase border-none cursor-pointer"><i class="fa-solid fa-plus mr-1"></i> Ajouter</button>
                        </div>

                        <template x-for="(p, i) in products" :key="i">
                            <div class="grid grid-cols-12 gap-3 mb-3 items-end p-4 bg-slate-50 rounded-2xl">
                                <div class="col-span-3">
                                    <label class="text-[8px] font-black uppercase text-slate-400">Type</label>
                                    <select :name="'products['+i+'][type]'" x-model="p.type" @change="onProductTypeChange(i)" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        <option value="cuisse">Cuisses</option>
                                        <option value="aile">Ailes</option>
                                        <option value="poitrine">Poitrine</option>
                                        <option value="dos">Dos/Carcasse</option>
                                        <option value="abats">Abats</option>
                                        <option value="foie">Foies</option>
                                        <option value="gesier">Gésiers</option>
                                        <option value="entier">Entier</option>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400">Nom</label>
                                    <input type="text" :name="'products['+i+'][name]'" x-model="p.name" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400">Poids (kg)</label>
                                    <input type="number" :name="'products['+i+'][kg]'" x-model.number="p.kg" step="0.1" min="0" class="w-full bg-white border-none rounded-xl p-3 text-sm font-black shadow-sm outline-none text-center">
                                </div>
                                <div class="col-span-1">
                                    <label class="text-[8px] font-black uppercase text-slate-400">Pièces</label>
                                    <input type="number" :name="'products['+i+'][pieces]'" x-model.number="p.pieces" min="0" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-center">
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400">Destination</label>
                                    <select :name="'products['+i+'][destination]'" x-model="p.destination" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        <option value="stock_frais">Stock Frais</option>
                                        <option value="stock_congele">Congelé</option>
                                        <option value="transformation">Transformation</option>
                                        <option value="vente_directe">Vente Directe</option>
                                    </select>
                                </div>
                                <div class="col-span-2 flex items-end gap-2">
                                    <input type="number" :name="'products['+i+'][price]'" x-model.number="p.price" min="0" placeholder="Prix/kg" class="flex-1 bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-right">
                                    <button type="button" @click="products.splice(i,1)" x-show="products.length > 1" class="text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                        </template>

                        {{-- RÉSUMÉ EN TEMPS RÉEL --}}
                        <div class="mt-6 p-4 bg-slate-900 rounded-2xl text-white grid grid-cols-3 gap-4 text-center">
                            <div><p class="text-[8px] font-black text-emerald-400 uppercase">Total sortie</p><p class="text-lg font-black" x-text="totalOutput.toFixed(1) + ' kg'"></p></div>
                            <div><p class="text-[8px] font-black text-amber-400 uppercase">Perte</p><p class="text-lg font-black" x-text="loss.toFixed(1) + ' kg (' + lossPercent + '%)'"></p></div>
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase">Rendement</p>
                                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : lossTolerance --}}
                                <p class="text-lg font-black" :class="lossPercent > lossTolerance ? 'text-red-400' : 'text-emerald-400'" x-text="(100 - parseFloat(lossPercent)).toFixed(1) + '%'"></p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-purple-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-purple-600 transition-all shadow-2xl italic border-none cursor-pointer">
                        <i class="fa-solid fa-scissors mr-2"></i> Valider la Découpe
                    </button>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">Accès Restreint</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">Vous n'avez pas la permission de déclarer une découpe.</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
    function cuttingForm() {
        const names = { cuisse:'Cuisses', aile:'Ailes', poitrine:'Poitrine/Blancs', dos:'Dos/Carcasse', abats:'Abats divers', foie:'Foies', gesier:'Gésiers', entier:'Poulet Entier' };
        
        // ⚙️ INJECTION DYNAMIQUE DES SETTINGS
        const lossTolerance = {{ setting('abattoir.tolerance_cutting_loss', 10) }};

        return {
            inputKg: 0,
            lossTolerance: lossTolerance,
            products: [
                { type:'cuisse', name:'Cuisses', kg:0, pieces:0, destination:'stock_frais', price:0 },
                { type:'aile', name:'Ailes', kg:0, pieces:0, destination:'stock_frais', price:0 },
                { type:'poitrine', name:'Poitrine/Blancs', kg:0, pieces:0, destination:'stock_frais', price:0 },
                { type:'dos', name:'Dos/Carcasse', kg:0, pieces:0, destination:'vente_directe', price:0 },
                { type:'abats', name:'Abats divers', kg:0, pieces:0, destination:'stock_frais', price:0 },
            ],
            get totalOutput() { return this.products.reduce((s,p) => s + (p.kg||0), 0); },
            get loss() { return Math.max(0, this.inputKg - this.totalOutput); },
            get lossPercent() { return this.inputKg > 0 ? (this.loss / this.inputKg * 100).toFixed(1) : '0.0'; },
            addProduct() { this.products.push({ type:'autre', name:'', kg:0, pieces:0, destination:'stock_frais', price:0 }); },
            onProductTypeChange(i) { this.products[i].name = names[this.products[i].type] || ''; },
        }
    }
    </script>
</x-app-layout>