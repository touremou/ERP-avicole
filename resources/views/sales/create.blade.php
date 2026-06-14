<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('sales.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Nouvelle Vente") }}</h2>
                <p class="text-[10px] font-black text-teal-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Saisie du bon de livraison ou facture") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="saleForm()" x-cloak>
        @can('commerce.C')
            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('sales.store') }}" @submit="onSubmit($event)">
                @csrf

                {{-- ENTÊTE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Client") }} *</label>
                            <select name="client_id" x-model="clientId" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                <option value="">{{ __("Sélectionner...") }}</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}" {{ ($selectedClient?->id ?? old('client_id')) == $client->id ? 'selected' : '' }}>{{ $client->name }} ({{ $client->client_id }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type") }}</label>
                            <select name="type" x-model="saleType" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                <option value="bon_livraison">{{ __("Bon de Livraison") }}</option>
                                <option value="facture">{{ __("Facture (TVA :rate%)", ['rate' => setting('general.tva_rate', 18)]) }}</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date") }}</label>
                            <input type="date" name="sale_date" value="{{ old('sale_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <input type="hidden" name="tax_rate" :value="saleType === 'facture' ? 18 : 0">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                        <select name="delivery_mode" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="sur_place">{{ __("Sur place") }}</option>
                            <option value="livraison">{{ __("Livraison") }}</option>
                        </select>
                        <input type="text" name="notes" placeholder="{{ __('Observations...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                </div>

                {{-- LIGNES — STOCK RÉEL --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-list text-teal-500"></i> {{ __("Produits") }}
                        </h3>
                        <button type="button" @click="addLine()" class="bg-slate-900 text-white px-5 py-2 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-teal-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                        </button>
                    </div>

                    <template x-for="(line, index) in lines" :key="index">
                        <div class="mb-4 p-5 bg-slate-50 rounded-2xl border" :class="line.quantity > line.max_qty && line.max_qty > 0 ? 'border-red-300 bg-red-50/30' : 'border-slate-100'">
                            <div class="grid grid-cols-12 gap-3 items-end">
                                {{-- TYPE --}}
                                <div class="col-span-3">
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Type") }}</label>
                                    <select x-model="line.product_type" @change="onTypeChange(index)" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        <option value="">— {{ __("Choisir") }} —</option>
                                        <option value="animal_vif">{{ __("Animal vivant") }}</option>
                                        <option value="carcasse">{{ __("Carcasse / Viande") }}</option>
                                        <option value="oeufs">{{ __("Œufs") }}</option>
                                        <option value="lait">{{ __("Lait") }}</option>
                                        <option value="aliment">{{ __("Aliment") }}</option>
                                        <option value="produits_finis">{{ __("Produits Finis (découpe, poussins...)") }}</option>
                                        <option value="fumier">{{ __("Fumier") }}</option>
                                        <option value="materiel">{{ __("Matériel") }}</option>
                                        <option value="autre">{{ __("Autre") }}</option>
                                    </select>
                                </div>

                                {{-- ARTICLE (dropdown dynamique) --}}
                                <div class="col-span-4">
                                    <label class="text-[8px] font-black uppercase text-teal-600 tracking-widest">
                                        <template x-if="isBatchType(line.product_type)">
                                            <span>{{ __("Lot source") }}</span>
                                        </template>
                                        <template x-if="isStockType(line.product_type)">
                                            <span>{{ __("Article en stock") }}</span>
                                        </template>
                                        <template x-if="isManualType(line.product_type)">
                                            <span>{{ __("Désignation") }}</span>
                                        </template>
                                    </label>

                                    {{-- STOCK : œufs, aliment, matériel --}}
                                    <template x-if="isStockType(line.product_type)">
                                        <select x-model="line.selected_stock" @change="onStockSelected(index)" class="w-full bg-white border-2 border-teal-200 rounded-xl p-3 text-[10px] font-black shadow-sm outline-none focus:border-teal-500">
                                            <option value="">— {{ __("Sélectionner") }} —</option>
                                            <template x-for="s in getStocks(line.product_type)" :key="s.id">
                                                <option :value="s.id" x-text="s.item_name + ' (' + s.current_quantity + ' ' + s.unit + ')'"></option>
                                            </template>
                                        </select>
                                    </template>

                                    {{-- LOT : animal vif / carcasse — toutes espèces --}}
                                    <template x-if="isBatchType(line.product_type)">
                                        <select x-model="line.batch_id" @change="onBatchSelected(index)" class="w-full bg-amber-50 border-2 border-amber-200 rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                                            <option value="">— {{ __("Sélectionner le lot") }} —</option>
                                            <template x-for="b in batches" :key="b.id">
                                                <option :value="b.id" x-text="b.icon + ' ' + b.code + ' — ' + b.species + ' (' + b.qty + ')'"></option>
                                            </template>
                                        </select>
                                    </template>

                                    {{-- MANUEL : lait, fumier, autre --}}
                                    <template x-if="isManualType(line.product_type)">
                                        <input type="text" x-model="line.product_name" placeholder="{{ __('Désignation...') }}" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                                    </template>
                                </div>

                                <div class="col-span-1">
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Qté") }}</label>
                                    <input type="number" x-model.number="line.quantity" step="0.01" min="0.01" :max="line.max_qty || 99999" required class="w-full bg-white border-none rounded-xl p-3 text-sm font-black shadow-sm outline-none text-center">
                                </div>
                                <div class="col-span-1">
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Unité") }}</label>
                                    <template x-if="unitChoices(line.product_type).length > 1">
                                        <select x-model="line.unit" @change="onUnitChange(index)" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-center uppercase">
                                            <template x-for="u in unitChoices(line.product_type)" :key="u">
                                                <option :value="u" x-text="u"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="unitChoices(line.product_type).length <= 1">
                                        <input type="text" x-model="line.unit" readonly class="w-full bg-slate-100 border-none rounded-xl p-3 text-[10px] font-black text-slate-500 outline-none text-center uppercase">
                                    </template>
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("P.U. (GNF)") }}</label>
                                    <input type="number" x-model.number="line.unit_price" min="0" required class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-right">
                                </div>
                                <div class="col-span-1 text-center">
                                    <button type="button" @click="removeLine(index)" x-show="lines.length > 1" class="text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer mt-4"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>

                            {{-- Indicateur stock --}}
                            <div class="mt-3 flex justify-between items-center" x-show="line.max_qty > 0">
                                <p class="text-[8px] font-black uppercase tracking-widest" :class="line.quantity > line.max_qty ? 'text-red-600' : 'text-emerald-600'">
                                    <i class="fa-solid" :class="line.quantity > line.max_qty ? 'fa-circle-xmark' : 'fa-circle-check'"></i>
                                    {{ __("Disponible") }} : <span x-text="line.max_qty + ' ' + line.unit"></span>
                                </p>
                                <p class="text-sm font-black text-slate-900" x-text="formatGNF(line.quantity * line.unit_price)"></p>
                            </div>

                            {{-- Hidden --}}
                            <input type="hidden" :name="'items['+index+'][product_type]'" :value="line.product_type">
                            <input type="hidden" :name="'items['+index+'][product_name]'" :value="line.product_name">
                            <input type="hidden" :name="'items['+index+'][product_id]'" :value="line.product_id">
                            <input type="hidden" :name="'items['+index+'][batch_id]'" :value="line.batch_id">
                            <input type="hidden" :name="'items['+index+'][quantity]'" :value="line.quantity">
                            <input type="hidden" :name="'items['+index+'][unit]'" :value="line.unit">
                            <input type="hidden" :name="'items['+index+'][unit_price]'" :value="line.unit_price">
                        </div>
                    </template>
                </div>

                {{-- TOTAUX & PAIEMENT --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4 flex items-center gap-2"><i class="fa-solid fa-money-bill-wave text-emerald-500"></i> {{ __("Paiement Immédiat") }}</h3>
                        <input type="number" name="immediate_payment" x-model.number="immediatePayment" min="0" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-emerald-600 shadow-inner outline-none text-right mb-3" placeholder="0">
                        <select name="payment_method" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="especes">{{ __("Espèces") }}</option>
                            <option value="orange_money">{{ __("Orange Money") }}</option>
                            <option value="virement">{{ __("Virement") }}</option>
                            <option value="cheque">{{ __("Chèque") }}</option>
                        </select>
                    </div>
                    <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl">
                        <h3 class="text-[10px] font-black uppercase text-emerald-400 tracking-widest mb-6">{{ __("Récapitulatif") }}</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between"><span class="text-slate-400 font-black text-[10px] uppercase">{{ __("HT") }}</span><span class="font-black text-lg" x-text="formatGNF(subtotal)"></span></div>
                            <div class="flex justify-between" x-show="saleType === 'facture'"><span class="text-slate-400 font-black text-[10px] uppercase">{{ __("TVA :rate%", ['rate' => setting('general.tva_rate', 18)]) }}</span><span class="font-black" x-text="formatGNF(taxAmount)"></span></div>
                            <div class="border-t border-slate-700 pt-3 flex justify-between"><span class="text-emerald-400 font-black text-[10px] uppercase">{{ __("Total TTC") }}</span><span class="font-black text-2xl" x-text="formatGNF(totalTTC)"></span></div>
                            <div class="border-t border-slate-700 pt-3 flex justify-between" x-show="immediatePayment > 0"><span class="text-amber-400 font-black text-[10px] uppercase">{{ __("Reste dû") }}</span><span class="font-black text-lg text-amber-400" x-text="formatGNF(Math.max(0, totalTTC - immediatePayment))"></span></div>
                        </div>
                        <div x-show="hasStockError" class="mt-4 p-3 bg-red-500/20 rounded-xl">
                            <p class="text-[9px] font-black text-red-300"><i class="fa-solid fa-ban mr-1"></i> {{ __("Stock insuffisant sur une ou plusieurs lignes.") }}</p>
                        </div>
                    </div>
                </div>

                <button type="submit" :disabled="hasStockError || lines.every(l => !l.product_name)"
                    :class="(hasStockError || lines.every(l => !l.product_name)) ? 'bg-slate-300 cursor-not-allowed' : 'bg-teal-500 hover:bg-teal-600 cursor-pointer'"
                    class="w-full text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none">
                    <i class="fa-solid fa-file-circle-check mr-2"></i> {{ __("Enregistrer la Vente") }}
                </button>
            </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas la permission de créer une vente.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    {{-- 💡 PRÉPARATION PROPRE DES DONNÉES EN PHP POUR ÉVITER LE BUG DU PARSER BLADE --}}
    @php
        $formattedStocks = $stocks->map(fn($s) => [
            'id' => $s->id,
            'item_name' => $s->item_name,
            'category' => $s->category,
            'unit' => $s->unit,
            'current_quantity' => (float) $s->current_quantity,
            'unit_price' => (float) ($s->unit_price ?? $s->last_unit_price ?? 0) 
        ]);

        $formattedBatches = $batches->map(fn($b) => [
            'id' => $b->id,
            'code' => $b->code,
            'building' => $b->building->name ?? '—',
            'species' => $b->species?->name_fr ?? 'Animaux',
            'icon' => $b->species?->icon ?? '🐾',
            'qty' => (int) $b->current_quantity
        ]);

        $formattedPrices = $prices->map(fn($p) => [
            'product_type' => $p->product_type,
            'product_name' => $p->product_name,
            'unit_price' => (float) $p->unit_price
        ]);
    @endphp

    <script>
    function saleForm() {
        // On récupère les données proprement formatées
        const stocks = @json($formattedStocks);
        const batchList = @json($formattedBatches);
        const prices = @json($formattedPrices);
        const catMap = @json(\App\Models\Stock::PRODUCT_TYPE_TO_CATEGORY);

        return {
            clientId: '{{ $selectedClient?->id ?? "" }}', saleType: 'bon_livraison', immediatePayment: 0,
            lines: [{ product_type:'', product_name:'', quantity:1, unit:'', unit_price:0, product_id:'', batch_id:'', selected_stock:'', max_qty:0 }],
            batches: batchList.filter(b => b.qty > 0),
            get subtotal() { return this.lines.reduce((s,l) => s + (l.quantity*l.unit_price), 0); },
            get taxAmount() { return this.saleType==='facture' ? this.subtotal*0.18 : 0; },
            get totalTTC() { return this.subtotal + this.taxAmount; },
            get hasStockError() { return this.lines.some(l => l.max_qty > 0 && l.quantity > l.max_qty); },
            getStocks(type) { return stocks.filter(s => s.category === (catMap[type]||type) && s.current_quantity > 0); },
            // ─── Catégories de lignes (multiespèces) ───
            // 'lait' et 'produits_finis' sont des articles physiques réels
            // (Stock::CAT_LAIT alimenté par les collectes de lait,
            // Stock::CAT_PRODUITS_FINIS par l'abattoir/découpe et les
            // poussins d'un jour) : ils se sélectionnent depuis le stock,
            // au même titre que oeufs/aliment/materiel.
            isStockType(t) { return ['oeufs','lait','aliment','produits_finis','materiel'].includes(t); },
            isBatchType(t) { return ['animal_vif','carcasse'].includes(t); },
            isManualType(t) { return ['fumier','autre'].includes(t); },
            unitChoices(t) {
                return ({
                    animal_vif: ['tete','piece','kg'],
                    carcasse:   ['kg'],
                    fumier:     ['sac','voyage'],
                    autre:      ['unite','kg','piece','litre','sac'],
                })[t] || [];
            },
            isCountUnit(u) { return ['tete','piece','unite'].includes(u); },
            addLine() { this.lines.push({ product_type:'', product_name:'', quantity:1, unit:'', unit_price:0, product_id:'', batch_id:'', selected_stock:'', max_qty:0 }); },
            removeLine(i) { if(this.lines.length>1) this.lines.splice(i,1); },
            onTypeChange(i) {
                let l=this.lines[i]; l.product_name=''; l.product_id=''; l.batch_id=''; l.selected_stock=''; l.max_qty=0; l.unit_price=0;
                // Unité par défaut selon le type ; les types stock prennent
                // l'unité de l'article sélectionné (renseignée plus tard).
                const defaults = { animal_vif:'tete', carcasse:'kg', fumier:'voyage', autre:'unite' };
                l.unit = defaults[l.product_type] || '';
            },
            onUnitChange(i) {
                // Pour un animal vif, le plafond (effectif du lot) ne s'applique
                // qu'aux ventes à la tête ; les ventes au poids ne sont pas capées.
                let l=this.lines[i];
                if(this.isBatchType(l.product_type) && l.batch_id){
                    const b=batchList.find(x=>x.id==l.batch_id);
                    l.max_qty = this.isCountUnit(l.unit) ? (b?b.qty:0) : 0;
                }
            },
            /* version initiale avant correction du bug de parser blade sur les prix spécifiques de vente
            onStockSelected(i) {
                let l=this.lines[i]; const s=stocks.find(x=>x.id==l.selected_stock); if(!s)return;
                l.product_id=s.id; l.product_name=s.item_name; l.unit=s.unit.toLowerCase()==='alvéole'?'alveole':s.unit.toLowerCase();
                l.max_qty=s.current_quantity; l.quantity=1;
                const p=prices.find(x=>x.product_name===s.item_name); l.unit_price=p?p.unit_price:(s.last_unit_price||0);
            },*/

            onStockSelected(i) {
                let l=this.lines[i]; const s=stocks.find(x=>x.id==l.selected_stock); if(!s)return;
                
                l.product_id=s.id; 
                l.product_name=s.item_name; 
                l.unit=s.unit.toLowerCase()==='alvéole'?'alveole':s.unit.toLowerCase();
                l.max_qty=s.current_quantity; 
                l.quantity=1;
                
                // On cherche si un prix spécifique de vente existe dans la grille
                const p = prices.find(x => x.product_name === s.item_name); 
                
                // S'il existe on le prend, SINON on prend le prix unitaire du stock
                l.unit_price = p ? p.unit_price : s.unit_price;
            },
            onBatchSelected(i) {
                let l=this.lines[i]; const b=batchList.find(x=>x.id==l.batch_id); if(!b)return;
                l.product_name=b.code+' — '+b.species+' ('+b.building+')';
                // Plafond uniquement si vente à la tête (sinon poids → non capé).
                l.max_qty = this.isCountUnit(l.unit) ? b.qty : 0;
                l.quantity=1;
                const p=prices.find(x=>x.product_type===l.product_type); l.unit_price=p?p.unit_price:0;
            },
            formatGNF(v) { return new Intl.NumberFormat('fr-GN',{maximumFractionDigits:0}).format(v||0)+' GNF'; },

            /**
             * Interception de la soumission : si hors-ligne (ou base injoignable),
             * on enregistre la vente en file d'attente IndexedDB (synchronisée en
             * brouillon dès le retour du réseau via sync-engine.js). Sinon, on
             * laisse le POST serveur classique se faire.
             */
            async onSubmit(e) {
                const offline = !navigator.onLine || {{ config('app.database_down', false) ? 'true' : 'false' }};
                if (!offline) return; // En ligne : soumission serveur normale.

                e.preventDefault();

                if (!this.clientId) { alert(@json(__("Veuillez sélectionner un client."))); return; }

                const validLines = this.lines.filter(l => l.product_type && l.quantity > 0 && l.unit);
                if (validLines.length === 0) { alert(@json(__("Ajoutez au moins une ligne valide."))); return; }

                const form = e.target;
                const saleDate = form.querySelector('[name=sale_date]')?.value || new Date().toISOString().slice(0,10);
                const notes = form.querySelector('[name=notes]')?.value || null;

                const sale = {
                    uuid: (crypto.randomUUID ? crypto.randomUUID() : 'sale-' + Date.now() + '-' + Math.random().toString(16).slice(2)),
                    client_id: parseInt(this.clientId, 10),
                    sale_date: saleDate,
                    type: this.saleType,
                    tax_rate: this.saleType === 'facture' ? {{ (int) setting('general.tva_rate', 18) }} : 0,
                    notes: notes,
                    immediate_payment: parseFloat(this.immediatePayment) || 0,
                    payment_method: 'especes',
                    items: validLines.map(l => ({
                        product_type: l.product_type,
                        product_name: l.product_name || l.product_type,
                        product_id: l.product_id ? parseInt(l.product_id, 10) : null,
                        batch_id: l.batch_id ? parseInt(l.batch_id, 10) : null,
                        quantity: parseFloat(l.quantity),
                        unit: l.unit,
                        unit_price: parseFloat(l.unit_price) || 0,
                    })),
                    is_synced: 0,
                };

                try {
                    await window.db.sales.add(sale);
                    alert("📴 " + @json(__("Hors-ligne : vente enregistrée localement. Elle sera synchronisée (en brouillon) au retour du réseau.")));
                    window.location.href = "{{ route('sales.index') }}";
                } catch (err) {
                    console.error("Échec de l'enregistrement hors-ligne de la vente :", err);
                    alert(@json(__("Erreur lors de l'enregistrement hors-ligne. Réessayez.")));
                }
            },
        }
    }
    </script>
</x-app-layout>