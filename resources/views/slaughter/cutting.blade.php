<x-app-layout>
    <x-slot name="header">
        {{-- Plafond réel = carcasse RESTANTE (produite − déjà découpée). --}}
        <x-page-header :title="__('Découpe')" :subtitle="$order->order_number . ' — ' . __('Carcasse restante à découper') . ' : ' . number_format($remainingKg, 1) . ' kg / ' . ($order->result ? number_format($order->result->total_carcass_weight_kg, 1) : '—') . ' kg'" icon="fa-scissors" accent="rose" :back="route('slaughter.dashboard')" />
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
                                <input type="number" name="total_input_kg" x-model.number="inputKg" step="0.1" min="0.1" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center" :class="inputExceeds ? 'ring-2 ring-red-400' : ''">
                                {{-- Plafond client-side : carcasse restante (le serveur re-vérifie sous verrou). --}}
                                <p class="text-[8px] font-black uppercase m-0 ml-2" :class="inputExceeds ? 'text-red-500' : 'text-slate-400'"
                                   x-text="inputExceeds
                                       ? {{ Js::from(__('Dépasse la carcasse restante (:kg kg max)')) }}.replace(':kg', remainingKg.toFixed(1))
                                       : {{ Js::from(__('Restant : :kg kg')) }}.replace(':kg', remainingKg.toFixed(1))"></p>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date") }} *</label>
                                <input type="date" name="session_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                        </div>

                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"><i class="fa-solid fa-scissors text-purple-500"></i> {{ __("Produits de découpe") }}</h3>
                            <div class="flex items-center gap-2">
                                {{-- RECETTE : source des lignes pré-remplies + rendements attendus --}}
                                <a href="{{ route('slaughter.recipes.index') }}" class="text-[8px] font-black uppercase tracking-widest no-underline {{ $hasRecipe ? 'text-emerald-500' : 'text-slate-400' }}" title="{{ __('Recettes de désassemblage (BOM inversée)') }}">
                                    <i class="fa-solid fa-diagram-project mr-1"></i>{{ $hasRecipe ? __("Recette active") : __("Nomenclature std") }}
                                </a>
                                <button type="button" @click="addProduct()" class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase border-none cursor-pointer"><i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}</button>
                            </div>
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
                                    {{-- RECETTE : rendement attendu de la ligne → attendu ≈ x kg pour l'entrée saisie --}}
                                    <p class="text-[8px] font-black m-0 mt-1 text-center" x-show="expectedKg(p) !== null" :class="deviationClass(p)" x-text="'≈ ' + (expectedKg(p) ?? 0).toFixed(1) + ' kg ' + '{{ __('attendu') }}'"></p>
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
                                        <option value="dechet">{{ __("Déchet (pesé, non stocké)") }}</option>
                                    </select>
                                </div>
                                <div class="col-span-2 flex items-end gap-2">
                                    <input type="number" :name="'products['+i+'][price]'" x-model.number="p.price" min="0" placeholder="{{ __('Prix/kg') }}" class="flex-1 bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-right">
                                    <button type="button" @click="products.splice(i,1)" x-show="products.length > 1" class="text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-trash"></i></button>
                                </div>

                                {{-- CALIBRAGE & CONDITIONNEMENT (UVC) --}}
                                <div class="col-span-4">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Calibre — optionnel") }}</label>
                                    <input type="text" :name="'products['+i+'][calibre]'" x-model="p.calibre" list="calibres" placeholder="{{ __('Ex. S, M, L') }}" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                </div>
                                <div class="col-span-4">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Conditionnement") }}</label>
                                    <select :name="'products['+i+'][packaging]'" x-model="p.packaging" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        <option value="vrac">{{ __("Vrac") }}</option>
                                        <option value="barquette">{{ __("Barquette") }}</option>
                                        <option value="sachet">{{ __("Sachet (abats ensachés)") }}</option>
                                    </select>
                                </div>
                                <div class="col-span-4" x-show="p.packaging !== 'vrac'">
                                    <label class="text-[8px] font-black uppercase text-slate-400">{{ __("Nb d'UVC (barquettes/sachets)") }}</label>
                                    <input type="number" :name="'products['+i+'][pack_count]'" x-model.number="p.pack_count" min="0" placeholder="{{ __('ex. 12') }}" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-center">
                                </div>
                            </div>
                        </template>
                        <datalist id="calibres">
                            <option value="S"></option><option value="M"></option><option value="L"></option><option value="XL"></option>
                        </datalist>

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

                    {{-- Blocage client-side : entrée > carcasse restante OU sortie > entrée
                         (une découpe ne crée pas de matière) — le serveur re-vérifie sous verrou. --}}
                    <div x-show="outputExceeds" class="mb-4 p-4 bg-red-50 text-red-600 rounded-2xl text-[10px] font-black uppercase tracking-widest border border-red-200">
                        ⚠️ <span x-text="{{ Js::from(__('Le total des morceaux (:out kg) dépasse le poids entré (:in kg) — une découpe ne crée pas de matière.')) }}.replace(':out', totalOutput.toFixed(1)).replace(':in', (inputKg || 0).toFixed(1))"></span>
                    </div>
                    <button type="submit" :disabled="blocked"
                        :class="blocked ? 'bg-slate-300 cursor-not-allowed' : 'bg-purple-500 hover:bg-purple-600 cursor-pointer'"
                        class="w-full text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none">
                        <i class="fa-solid fa-scissors mr-2"></i>
                        <span x-text="inputExceeds ? {{ Js::from(__('CARCASSE RESTANTE INSUFFISANTE')) }} : (outputExceeds ? {{ Js::from(__('SORTIE > ENTRÉE')) }} : {{ Js::from(__('Valider la Découpe')) }})"></span>
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
        // ⚙️ RECETTE DE DÉSASSEMBLAGE ACTIVE (BOM inversée) ou repli sur la
        // nomenclature de l'espèce (config/butchery.php).
        const cuts = {{ Js::from($cuts) }};
        const speciesEntierName = {{ Js::from(($order->batch->species?->name_fr ?? 'Poulet') . ' Entier') }};

        // Table code → libellé / rendement attendu (le morceau "entier" reprend le nom de l'espèce).
        const names = {}, expectedPct = {};
        cuts.forEach(c => {
            names[c.code] = c.code === 'entier' ? speciesEntierName : c.label;
            if (c.expected_yield_percent) expectedPct[c.code] = c.expected_yield_percent;
        });

        // Lignes pré-remplies = lignes "default" de la recette (avec destination,
        // conditionnement et calibre par défaut) ou de la nomenclature.
        const defaultProducts = cuts
            .filter(c => c.default)
            .map(c => ({
                type: c.code,
                name: (c.code === 'entier' ? speciesEntierName : c.label),
                kg: 0, pieces: 0,
                destination: c.destination, price: 0,
                calibre: c.default_calibre || '',
                packaging: c.default_packaging || 'vrac',
                pack_count: 0,
            }));

        // ⚙️ INJECTION DYNAMIQUE DES SETTINGS
        const lossTolerance = {{ setting('abattoir.tolerance_cutting_loss', 10) }};
        const cuttingYieldTarget = {{ setting('abattoir.yield_cutting', 85) }};
        // Plafond physique : carcasse restante à découper sur cet ordre.
        const remainingKg = {{ Js::from(round((float) $remainingKg, 2)) }};

        return {
            inputKg: 0,
            remainingKg: remainingKg,
            get inputExceeds() { return this.inputKg > this.remainingKg + 0.001; },
            get outputExceeds() { return this.totalOutput > this.inputKg + 0.001; },
            get blocked() { return this.inputExceeds || this.outputExceeds || !(this.inputKg > 0); },
            lossTolerance: lossTolerance,
            cuttingYieldTarget: cuttingYieldTarget,
            products: defaultProducts.length ? defaultProducts : [{ type:'autre', name:'', kg:0, pieces:0, destination:'stock_frais', price:0, calibre:'', packaging:'vrac', pack_count:0 }],
            get totalOutput() { return this.products.reduce((s,p) => s + (p.kg||0), 0); },
            get loss() { return Math.max(0, this.inputKg - this.totalOutput); },
            get lossPercent() { return this.inputKg > 0 ? (this.loss / this.inputKg * 100).toFixed(1) : '0.0'; },
            // RECETTE : poids attendu d'une ligne (rendement attendu × entrée).
            expectedKg(p) {
                const pct = expectedPct[p.type];
                return (pct && this.inputKg > 0) ? this.inputKg * pct / 100 : null;
            },
            // Écart réel/attendu : vert < 10 %, orange < 25 %, rouge au-delà.
            deviationClass(p) {
                const exp = this.expectedKg(p);
                if (exp === null || !(p.kg > 0)) return 'text-slate-400';
                const dev = Math.abs(p.kg - exp) / exp;
                return dev < 0.10 ? 'text-emerald-500' : (dev < 0.25 ? 'text-amber-500' : 'text-red-500');
            },
            addProduct() { this.products.push({ type:'autre', name:'', kg:0, pieces:0, destination:'stock_frais', price:0, calibre:'', packaging:'vrac', pack_count:0 }); },
            onProductTypeChange(i) { this.products[i].name = names[this.products[i].type] || ''; },
        }
    }
    </script>
</x-app-layout>