<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('sales.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Nouvelle Vente</h2>
                <p class="text-[10px] font-black text-teal-600 uppercase tracking-[0.2em] mt-2 italic">Saisie du bon de livraison ou facture</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left"
             x-data="saleForm()" x-cloak>

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i>
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('sales.store') }}">
                @csrf

                {{-- ENTÊTE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Client</label>
                            <select name="client_id" x-model="clientId" @change="onClientChange()" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                <option value="">Sélectionner...</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}" data-category="{{ $client->category }}"
                                        {{ ($selectedClient?->id ?? old('client_id')) == $client->id ? 'selected' : '' }}>
                                        {{ $client->name }} ({{ $client->client_id }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Type</label>
                            <select name="type" x-model="saleType" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                <option value="bon_livraison">Bon de Livraison</option>
                                <option value="facture">Facture (TVA 18%)</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Date</label>
                            <input type="date" name="sale_date" value="{{ old('sale_date', now()->toDateString()) }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>

                    <input type="hidden" name="tax_rate" :value="saleType === 'facture' ? 18 : 0">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Mode de livraison</label>
                            <select name="delivery_mode" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                <option value="sur_place">Sur place</option>
                                <option value="livraison">Livraison</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Notes</label>
                            <input type="text" name="notes" placeholder="Observations..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                </div>

                {{-- LIGNES DE VENTE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-list text-teal-500"></i> Produits
                        </h3>
                        <button type="button" @click="addLine()" class="bg-slate-900 text-white px-5 py-2 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-teal-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-plus mr-1"></i> Ajouter
                        </button>
                    </div>

                    <template x-for="(line, index) in lines" :key="index">
                        <div class="grid grid-cols-12 gap-3 mb-4 items-end p-4 bg-slate-50 rounded-2xl">
                            <div class="col-span-3">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Type</label>
                                <select :name="'items['+index+'][product_type]'" x-model="line.product_type" @change="onTypeChange(index)"
                                    class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                    <option value="oeufs">Œufs</option>
                                    <option value="volaille_vivante">Volaille Vivante</option>
                                    <option value="volaille_abattue">Volaille Abattue</option>
                                    <option value="aliment">Aliment</option>
                                    <option value="fumier">Fumier</option>
                                    <option value="materiel">Matériel</option>
                                    <option value="autre">Autre</option>
                                </select>
                            </div>
                            <div class="col-span-3">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Désignation</label>
                                <input type="text" :name="'items['+index+'][product_name]'" x-model="line.product_name" required
                                    class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none" placeholder="Ex: Œufs calibre L">
                            </div>
                            <div class="col-span-1">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Qté</label>
                                <input type="number" :name="'items['+index+'][quantity]'" x-model.number="line.quantity" step="0.01" min="0.01" required
                                    class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-center">
                            </div>
                            <div class="col-span-1">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Unité</label>
                                <select :name="'items['+index+'][unit]'" x-model="line.unit"
                                    class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                    <option value="alveole">Alv.</option>
                                    <option value="piece">Pce</option>
                                    <option value="kg">Kg</option>
                                    <option value="sac">Sac</option>
                                    <option value="unite">Unité</option>
                                    <option value="voyage">Voyage</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Prix Unit. (GNF)</label>
                                <input type="number" :name="'items['+index+'][unit_price]'" x-model.number="line.unit_price" min="0" required
                                    class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none text-right">
                            </div>
                            <div class="col-span-1 text-right">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Total</label>
                                <p class="text-sm font-black text-slate-900 mt-1" x-text="formatGNF(line.quantity * line.unit_price)"></p>
                            </div>
                            <div class="col-span-1 text-center">
                                <button type="button" @click="removeLine(index)" x-show="lines.length > 1"
                                    class="text-red-400 hover:text-red-600 transition-colors border-none bg-transparent cursor-pointer mt-4">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>

                            <input type="hidden" :name="'items['+index+'][product_id]'" :value="line.product_id">
                            <input type="hidden" :name="'items['+index+'][batch_id]'" :value="line.batch_id">
                        </div>
                    </template>
                </div>

                {{-- TOTAUX & PAIEMENT --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    {{-- Paiement immédiat --}}
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-money-bill-wave text-emerald-500"></i> Paiement Immédiat
                        </h3>
                        <div class="space-y-4">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Montant reçu (GNF)</label>
                                <input type="number" name="immediate_payment" x-model.number="immediatePayment" min="0"
                                    class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-emerald-600 shadow-inner outline-none text-right" placeholder="0">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Mode</label>
                                <select name="payment_method" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                    <option value="especes">Espèces</option>
                                    <option value="orange_money">Orange Money</option>
                                    <option value="virement">Virement</option>
                                    <option value="cheque">Chèque</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- Récapitulatif --}}
                    <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl">
                        <h3 class="text-[10px] font-black uppercase text-emerald-400 tracking-widest mb-6">Récapitulatif</h3>
                        <div class="space-y-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-slate-400 font-black uppercase text-[10px]">Sous-total HT</span>
                                <span class="font-black text-lg" x-text="formatGNF(subtotal)"></span>
                            </div>
                            <div class="flex justify-between text-sm" x-show="saleType === 'facture'">
                                <span class="text-slate-400 font-black uppercase text-[10px]">TVA (18%)</span>
                                <span class="font-black" x-text="formatGNF(taxAmount)"></span>
                            </div>
                            <div class="border-t border-slate-700 pt-4 flex justify-between">
                                <span class="text-emerald-400 font-black uppercase text-[10px]">Total TTC</span>
                                <span class="font-black text-2xl tracking-tighter" x-text="formatGNF(totalTTC)"></span>
                            </div>
                            <div class="border-t border-slate-700 pt-4 flex justify-between" x-show="immediatePayment > 0">
                                <span class="text-amber-400 font-black uppercase text-[10px]">Reste à payer</span>
                                <span class="font-black text-lg text-amber-400" x-text="formatGNF(Math.max(0, totalTTC - immediatePayment))"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-teal-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-teal-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-file-circle-check mr-2"></i> Enregistrer la Vente
                </button>
            </form>
        </div>
    </div>

    <script>
    function saleForm() {
        return {
            clientId: '{{ $selectedClient?->id ?? '' }}',
            saleType: 'bon_livraison',
            immediatePayment: 0,
            lines: [{ product_type: 'oeufs', product_name: '', quantity: 1, unit: 'alveole', unit_price: 0, product_id: '', batch_id: '' }],

            get subtotal() { return this.lines.reduce((s, l) => s + (l.quantity * l.unit_price), 0); },
            get taxAmount() { return this.saleType === 'facture' ? this.subtotal * 0.18 : 0; },
            get totalTTC() { return this.subtotal + this.taxAmount; },

            addLine() {
                this.lines.push({ product_type: 'oeufs', product_name: '', quantity: 1, unit: 'alveole', unit_price: 0, product_id: '', batch_id: '' });
            },
            removeLine(i) { if (this.lines.length > 1) this.lines.splice(i, 1); },
            onClientChange() {},
            onTypeChange(i) {
                const units = { oeufs: 'alveole', volaille_vivante: 'piece', volaille_abattue: 'kg', aliment: 'sac', fumier: 'voyage', materiel: 'piece', autre: 'unite' };
                this.lines[i].unit = units[this.lines[i].product_type] || 'piece';
            },
            formatGNF(v) { return new Intl.NumberFormat('fr-GN', { maximumFractionDigits: 0 }).format(v || 0) + ' GNF'; },
        }
    }
    </script>
</x-app-layout>
