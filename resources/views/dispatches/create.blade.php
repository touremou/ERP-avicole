<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('dispatches.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Nouvelle Expédition") }}</h2>
                <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Transfert de garde — Chargement à la ferme") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="dispatchForm()" x-cloak>

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('dispatches.store') }}">
                @csrf

                {{-- TRANSPORT --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2"><i class="fa-solid fa-truck text-orange-500"></i> {{ __("Transport") }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Chauffeur *") }}</label>
                            <input type="text" name="driver_name" required placeholder="{{ __('Nom complet') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Téléphone") }}</label>
                            <input type="text" name="driver_phone" placeholder="+224 6XX..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Immatriculation") }}</label>
                            <input type="text" name="vehicle_plate" placeholder="RC XXXX XX" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none uppercase">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Destination *") }}</label>
                            <input type="text" name="destination" required placeholder="{{ __('Magasin Conakry...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date *") }}</label>
                            <input type="date" name="dispatch_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Heure départ") }}</label>
                            <input type="time" name="dispatch_time" value="{{ now()->format('H:i') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>

                    {{-- RÉCEPTEUR DÉSIGNÉ : notifié à l'expédition et habilité à
                         valider la réception (responsable logistique.M en secours). --}}
                    <div class="mt-6 space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">
                            <i class="fa-solid fa-user-check text-emerald-500 mr-1"></i> {{ __("Récepteur désigné") }}
                        </label>
                        <select name="intended_receiver_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none appearance-none cursor-pointer">
                            <option value="">— {{ __("Aucun (validation par un responsable logistique)") }} —</option>
                            @foreach($receivers as $receiver)
                                <option value="{{ $receiver->user_id }}" {{ (string) old('intended_receiver_id') === (string) $receiver->user_id ? 'selected' : '' }}>
                                    {{ $receiver->first_name }} {{ $receiver->last_name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-[8px] text-slate-400 ml-2 italic">{{ __("Il sera notifié et pourra valider la réception. Un responsable logistique reste habilité en secours.") }}</p>
                    </div>
                </div>

                {{-- MARCHANDISE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"><i class="fa-solid fa-boxes-stacked text-orange-500"></i> {{ __("Marchandise") }}</h3>
                        <button type="button" @click="addLine()" class="bg-slate-900 text-white px-5 py-2 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-orange-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                        </button>
                    </div>

                    <template x-for="(line, index) in lines" :key="index">
                        <div class="mb-4 p-5 bg-slate-50 rounded-2xl border" :class="line.quantity > line.max_qty && line.max_qty > 0 ? 'border-red-300 bg-red-50/30' : 'border-slate-100'">
                            <div class="grid grid-cols-12 gap-3 items-end">
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
                                <div class="col-span-4">
                                    <label class="text-[8px] font-black uppercase text-orange-600 tracking-widest">
                                        <span x-text="isBatchType(line.product_type) ? {{ Js::from(__('Lot source')) }} : (isManualType(line.product_type) ? {{ Js::from(__('Désignation')) }} : {{ Js::from(__('Article en stock')) }})"></span>
                                    </label>
                                    {{-- STOCK : œufs, aliment, matériel --}}
                                    <template x-if="isStockType(line.product_type)">
                                        <select x-model="line.selected_stock" @change="onStockSelected(index)" class="w-full bg-white border-2 border-orange-200 rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
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
                                <div class="col-span-2">
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Quantité") }}</label>
                                    <input type="number" x-model.number="line.quantity" step="0.01" min="0.01" :max="line.max_qty||99999" required class="w-full bg-white border-none rounded-xl p-3 text-sm font-black shadow-sm outline-none text-center">
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
                                <div class="col-span-1">
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("État") }}</label>
                                    <select x-model="line.condition" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                        <option value="bon">{{ __("Bon") }}</option>
                                        <option value="moyen">{{ __("Moyen") }}</option>
                                        <option value="fragile">{{ __("Fragile") }}</option>
                                    </select>
                                </div>
                                <div class="col-span-1 text-center">
                                    <button type="button" @click="removeLine(index)" x-show="lines.length > 1" class="text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer mt-4"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                            <input type="hidden" :name="'items['+index+'][product_type]'" :value="line.product_type">
                            <input type="hidden" :name="'items['+index+'][product_name]'" :value="line.product_name">
                            <input type="hidden" :name="'items['+index+'][product_id]'" :value="line.product_id">
                            <input type="hidden" :name="'items['+index+'][batch_id]'" :value="line.batch_id">
                            <input type="hidden" :name="'items['+index+'][quantity]'" :value="line.quantity">
                            <input type="hidden" :name="'items['+index+'][unit]'" :value="line.unit">
                            <input type="hidden" :name="'items['+index+'][condition]'" :value="line.condition">
                        </div>
                    </template>
                </div>

                <button type="submit" class="w-full bg-orange-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none">
                    <i class="fa-solid fa-truck-ramp-box mr-2"></i> {{ __("Valider l'Expédition & Déstocker") }}
                </button>
            </form>
        </div>
    </div>

    @php
        $formattedStocks = $stocks->map(fn($s) => [
            'id' => $s->id,
            'item_name' => $s->item_name,
            'category' => $s->category,
            'unit' => $s->unit,
            'current_quantity' => (float) $s->current_quantity
        ]);

        $formattedBatches = $batches->map(fn($b) => [
            'id' => $b->id,
            'code' => $b->code,
            'building' => $b->building->name ?? '—',
            'species' => $b->species?->name_fr ?? 'Animaux',
            'icon' => $b->species?->icon ?? '🐾',
            'qty' => (int) $b->current_quantity
        ]);
    @endphp

    <script>
    function dispatchForm() {
        const stocks = @json($formattedStocks);
        const batchList = @json($formattedBatches);
        const catMap = @json(\App\Models\Stock::PRODUCT_TYPE_TO_CATEGORY);

        return {
            lines: [{ product_type:'', product_name:'', quantity:1, unit:'', product_id:'', batch_id:'', selected_stock:'', max_qty:0, condition:'bon' }],
            batches: batchList.filter(b => b.qty > 0),
            getStocks(type) { return stocks.filter(s => s.category === (catMap[type]||type) && s.current_quantity > 0); },
            // ─── Catégories de lignes (multiespèces) ───
            // 'lait' et 'produits_finis' sont des articles physiques réels
            // (Stock::CAT_LAIT, Stock::CAT_PRODUITS_FINIS) : sélection depuis
            // le stock, comme oeufs/aliment/materiel (cf. sales/create).
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
            addLine() { this.lines.push({ product_type:'', product_name:'', quantity:1, unit:'', product_id:'', batch_id:'', selected_stock:'', max_qty:0, condition:'bon' }); },
            removeLine(i) { if(this.lines.length>1) this.lines.splice(i,1); },
            onTypeChange(i) {
                let l=this.lines[i]; l.product_name=''; l.product_id=''; l.batch_id=''; l.selected_stock=''; l.max_qty=0;
                const defaults = { animal_vif:'tete', carcasse:'kg', fumier:'voyage', autre:'unite' };
                l.unit = defaults[l.product_type] || '';
            },
            onUnitChange(i) {
                let l=this.lines[i];
                if(this.isBatchType(l.product_type) && l.batch_id){
                    const b=batchList.find(x=>x.id==l.batch_id);
                    l.max_qty = this.isCountUnit(l.unit) ? (b?b.qty:0) : 0;
                }
            },
            onStockSelected(i) {
                let l=this.lines[i]; const s=stocks.find(x=>x.id==l.selected_stock); if(!s)return;
                l.product_id=s.id; l.product_name=s.item_name; l.unit=s.unit.toLowerCase()==='alvéole'?'alveole':s.unit.toLowerCase();
                l.max_qty=s.current_quantity; l.quantity=1;
            },
            onBatchSelected(i) {
                let l=this.lines[i]; const b=batchList.find(x=>x.id==l.batch_id); if(!b)return;
                l.product_name=b.code+' — '+b.species+' ('+b.building+')';
                l.max_qty = this.isCountUnit(l.unit) ? b.qty : 0;
                l.quantity=1;
            }
        }
    }
    </script>
</x-app-layout>