{{-- MODAL ACHAT DIRECT — Aligné sur le formulaire stocks.create --}}
@can('elevage.C')
<div id="feedModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-[110] hidden flex items-center justify-center p-4 sm:p-6"
    x-data="{
        cat: 'conso',
        consoType: 'Aliment',
        poultryType: '{{ in_array($batch->type, ['chair', 'poussiniere']) ? 'Chair' : 'Ponte' }}',
        unit: 'Sac',
        inputQty: 0,
        unitPrice: 0,
        get availableUnits() {
            if (this.cat === 'conso') {
                if (this.consoType === 'Aliment') return ['KG', 'Sac'];
                /* ALIGNEMENT STRICT AVEC LE STOCK GLOBAL */
                return ['Unité', 'Litre', 'Boîte', 'Flacon']; 
            }
            if (this.cat === 'litieres') return ['Sac'];
            if (this.cat === 'materiels') return ['Pcs', 'Unité', 'Boîte', 'Paquet'];
            return ['Unité'];
        },
        get finalQuantity() {
            return (this.unit === 'Sac' && this.consoType === 'Aliment') ? (this.inputQty * {{ setting('general.feed_bag_weight', 50) }}) : this.inputQty;
        },
        get finalUnit() {
            return (this.unit === 'Sac' && this.consoType === 'Aliment') ? 'KG' : this.unit;
        },
        get computedUnitPrice() {
            return this.finalQuantity > 0 ? Math.round(this.unitPrice / this.finalQuantity) : 0;
        }
    }"
    x-init="$watch('consoType', value => {
        if(value === 'Aliment') unit = 'Sac';
        else if(value === 'Santé') unit = 'Boîte';
        else if(value === 'Hygiène') unit = 'Litre';
    })">

    <div class="bg-white w-full max-w-2xl rounded-[3rem] shadow-2xl overflow-hidden relative" @click.away="closeFeedModal()">

        {{-- EN-TÊTE --}}
        <div class="px-10 py-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <div>
                <h3 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none flex items-center gap-2">
                    <i class="fa-solid fa-truck-ramp-box text-orange-500"></i> Achat Direct
                </h3>
                <p class="text-[9px] text-slate-400 uppercase mt-1 font-black tracking-widest italic">Lot {{ $batch->code }} — {{ ucfirst($batch->type) }}</p>
            </div>
            <button type="button" onclick="closeFeedModal()" class="text-slate-300 hover:text-red-500 transition border-none bg-transparent cursor-pointer outline-none"><i class="fa-solid fa-xmark text-2xl"></i></button>
        </div>

        <div class="p-8 text-left font-bold italic max-h-[80vh] overflow-y-auto">
            <form action="{{ route('feed-purchases.store') }}" method="POST" class="space-y-5">
                @csrf
                <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                <input type="hidden" name="purchase_date" value="{{ date('Y-m-d') }}">
                <input type="hidden" name="unit" :value="finalUnit">
                <input type="hidden" name="quantity" :value="finalQuantity">
                
                {{-- NOTE : Les name="metadata..." ont été retirés pour éviter le crash SQL --}}

                {{-- 00. CATÉGORIE --}}
                <div class="bg-slate-50 p-5 rounded-[2rem] border border-slate-100">
                    <label class="text-[9px] uppercase text-slate-500 ml-4 mb-2 block tracking-widest font-black">Catégorie</label>
                    <select x-model="cat" class="w-full bg-white border-none rounded-xl p-4 font-black text-xs uppercase shadow-sm italic cursor-pointer outline-none">
                        <option value="conso">🌾 Aliment & Santé</option>
                        <option value="litieres">🍂 Litières</option>
                        <option value="materiels">🛠️ Matériels</option>
                    </select>
                </div>

                {{-- 01. NATURE (Conso uniquement) --}}
                <div x-show="cat === 'conso'" class="bg-orange-50/50 p-5 rounded-[2rem] border border-orange-100">
                    <label class="text-[9px] uppercase text-orange-500 ml-4 mb-2 block tracking-widest font-black">Nature de l'achat</label>
                    <select x-model="consoType" class="w-full bg-white border-none rounded-xl p-4 font-black text-xs uppercase shadow-sm italic cursor-pointer outline-none">
                        <option value="Aliment">🌾 Alimentation</option>
                        <option value="Santé">💉 Santé / Médicaments</option>
                        <option value="Hygiène">🧼 Hygiène & Entretien</option>
                    </select>
                </div>

                {{-- SECTEUR Chair / Ponte (Aliment uniquement) --}}
                <div x-show="cat === 'conso' && consoType === 'Aliment'" class="grid grid-cols-2 gap-3">
                    <button type="button" @click="poultryType = 'Chair'"
                        :class="poultryType === 'Chair' ? 'bg-slate-900 text-white shadow-lg' : 'bg-slate-100 text-slate-400'"
                        class="py-3 rounded-xl text-[9px] font-black uppercase italic tracking-widest transition-all border-none cursor-pointer">
                        <i class="fa-solid fa-feather mr-1"></i> Chair
                    </button>
                    <button type="button" @click="poultryType = 'Ponte'"
                        :class="poultryType === 'Ponte' ? 'bg-emerald-600 text-white shadow-lg' : 'bg-slate-100 text-slate-400'"
                        class="py-3 rounded-xl text-[9px] font-black uppercase italic tracking-widest transition-all border-none cursor-pointer">
                        <i class="fa-solid fa-egg mr-1"></i> Ponte
                    </button>
                </div>

                {{-- 02. DÉSIGNATION --}}
                <div>
                    <label class="text-[9px] uppercase text-slate-400 ml-4 mb-2 block tracking-widest font-black">Désignation</label>

                    {{-- Aliment Chair --}}
                    <template x-if="cat === 'conso' && consoType === 'Aliment' && poultryType === 'Chair'">
                        <select name="feed_type" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-black uppercase text-xs shadow-inner italic border-l-4 border-slate-900 outline-none">
                            <option value="">-- Aliments Chair --</option>
                            <option value="Chair Démarrage">Chair Démarrage</option>
                            <option value="Chair Croissance">Chair Croissance</option>
                            <option value="Chair Finition">Chair Finition</option>
                        </select>
                    </template>

                    {{-- Aliment Ponte --}}
                    <template x-if="cat === 'conso' && consoType === 'Aliment' && poultryType === 'Ponte'">
                        <select name="feed_type" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-black uppercase text-xs shadow-inner italic border-l-4 border-emerald-500 outline-none">
                            <option value="">-- Aliments Ponte --</option>
                            <option value="Ponte Démarrage (Poussin)">Ponte Démarrage (Poussin)</option>
                            <option value="Ponte Croissance (Poulette)">Ponte Croissance (Poulette)</option>
                            <option value="Ponte 1 (Pic de ponte)">Ponte 1 (Pic de ponte)</option>
                            <option value="Ponte 2 (Entretien)">Ponte 2 (Entretien)</option>
                        </select>
                    </template>

                    {{-- Santé / Hygiène / Litières / Matériel --}}
                    <template x-if="cat !== 'conso' || consoType !== 'Aliment'">
                        <input type="text" name="feed_type" required placeholder="Ex: VACCIN HB1, LITIÈRE COPEAUX..."
                            class="w-full bg-slate-50 border-none rounded-xl p-4 font-black uppercase text-xs shadow-inner italic outline-none">
                    </template>
                </div>

                {{-- 03. FOURNISSEUR --}}
                <div>
                    <label class="text-[9px] uppercase text-slate-400 ml-4 mb-2 block tracking-widest font-black">Fournisseur</label>
                    <select name="supplier" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs uppercase shadow-inner italic outline-none">
                        <option value="">-- Sélectionner --</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->name }}">{{ $provider->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 04. QUANTITÉ + COÛT --}}
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-50 p-5 rounded-[2rem] border border-slate-100">
                        <label class="text-[8px] uppercase text-slate-400 mb-2 block font-black italic tracking-widest text-center" x-text="'Quantité en ' + unit"></label>
                        <input type="number" x-model.number="inputQty" step="0.01" min="0.01" required
                            class="w-full bg-white border-none rounded-xl p-3 text-center font-black text-xl text-blue-600 shadow-sm outline-none">

                        {{-- Sélecteur d'unité --}}
                        <div class="flex bg-white rounded-lg p-1 shadow-sm mt-3 border border-slate-100">
                            <template x-for="u in availableUnits" :key="u">
                                <button type="button" @click="unit = u"
                                    :class="unit === u ? 'bg-slate-900 text-white shadow-md' : 'text-slate-400 hover:bg-slate-100'"
                                    class="flex-1 py-1.5 rounded-md text-[8px] font-black uppercase transition-all mx-0.5 border-none cursor-pointer" x-text="u"></button>
                            </template>
                        </div>

                        {{-- Conversion Sac → KG --}}
                        <template x-if="unit === 'Sac' && consoType === 'Aliment' && inputQty > 0">
                            <div class="text-center mt-3 bg-emerald-50 p-2 rounded-lg">
                                <p class="text-[8px] font-black text-emerald-600">
                                    <i class="fa-solid fa-scale-balanced mr-1"></i> = <span x-text="finalQuantity"></span> KG
                                </p>
                            </div>
                        </template>
                    </div>

                    <div class="bg-slate-900 p-5 rounded-[2rem] shadow-xl text-white flex flex-col justify-center">
                        <label class="text-[8px] uppercase text-emerald-400 mb-2 block font-black tracking-widest text-center">Coût total (GNF)</label>
                        <input type="number" min="0" step="1" name="unit_price" x-model.number="unitPrice" required
                            class="w-full bg-white/10 border-none rounded-xl p-3 text-center font-black text-white text-xl shadow-inner outline-none focus:ring-2 focus:ring-emerald-500" placeholder="0">
                        <template x-if="computedUnitPrice > 0">
                            <p class="text-[8px] text-emerald-400 text-center mt-2 font-black">
                                = <span x-text="computedUnitPrice.toLocaleString('fr-FR')"></span> GNF/<span x-text="finalUnit"></span>
                            </p>
                        </template>
                    </div>
                </div>

                {{-- RÉSUMÉ --}}
                <div x-show="inputQty > 0 && unitPrice > 0" class="p-4 bg-blue-50 rounded-2xl border border-blue-200 text-center">
                    <p class="text-[8px] font-black text-blue-600 uppercase tracking-widest">
                        <span x-text="inputQty"></span> <span x-text="unit"></span>
                        <template x-if="unit === 'Sac'"><span> (<span x-text="finalQuantity"></span> KG)</span></template>
                        →
                        <span x-text="unitPrice.toLocaleString('fr-FR')"></span> GNF
                        → Imputé au lot {{ $batch->code }}
                    </p>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white py-5 rounded-[2rem] font-black uppercase italic shadow-2xl hover:bg-emerald-600 transition-all flex items-center justify-center gap-2 tracking-[0.2em] text-[10px] border-none cursor-pointer">
                    <i class="fa-solid fa-check-circle text-emerald-400"></i> Enregistrer l'achat
                </button>
            </form>
        </div>
    </div>
</div>
@endcan