<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('dispatches.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Nouvelle Expédition</h2>
                <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mt-2 italic">Transfert de garde — Chargement à la ferme</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left"
             x-data="dispatchForm()" x-cloak>

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('dispatches.store') }}">
                @csrf

                {{-- TRANSPORT --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-truck text-orange-500"></i> Transport
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Chauffeur *</label>
                            <input type="text" name="driver_name" value="{{ old('driver_name') }}" required placeholder="Nom complet"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Téléphone</label>
                            <input type="text" name="driver_phone" value="{{ old('driver_phone') }}" placeholder="+224 6XX..."
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Immatriculation</label>
                            <input type="text" name="vehicle_plate" value="{{ old('vehicle_plate') }}" placeholder="RC XXXX XX"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none uppercase">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Destination *</label>
                            <input type="text" name="destination" value="{{ old('destination') }}" required placeholder="Magasin Conakry, Dépôt Kindia..."
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Date *</label>
                            <input type="date" name="dispatch_date" value="{{ old('dispatch_date', now()->toDateString()) }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Heure départ</label>
                            <input type="time" name="dispatch_time" value="{{ old('dispatch_time', now()->format('H:i')) }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                </div>

                {{-- MARCHANDISE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-boxes-stacked text-orange-500"></i> Marchandise à charger
                        </h3>
                        <button type="button" @click="addLine()" class="bg-slate-900 text-white px-5 py-2 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-orange-600 transition-all border-none cursor-pointer">
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
                            <div class="col-span-2">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Quantité</label>
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
                                    <option value="voyage">Voyage</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">État</label>
                                <select :name="'items['+index+'][condition]'" x-model="line.condition"
                                    class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                    <option value="bon">Bon</option>
                                    <option value="moyen">Moyen</option>
                                    <option value="fragile">Fragile</option>
                                </select>
                            </div>
                            <div class="col-span-1 text-center">
                                <button type="button" @click="removeLine(index)" x-show="lines.length > 1"
                                    class="text-red-400 hover:text-red-600 transition-colors border-none bg-transparent cursor-pointer mt-4">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                            <input type="hidden" :name="'items['+index+'][product_id]'" :value="line.product_id">
                            <input type="hidden" :name="'items['+index+'][batch_id]'" :value="line.batch_id">

                            {{-- Sélection du lot pour la volaille --}}
                            <div class="col-span-12 mt-2" x-show="line.product_type === 'volaille_vivante' || line.product_type === 'volaille_abattue'">
                                <label class="text-[8px] font-black uppercase text-amber-600 tracking-widest">Lot source (traçabilité)</label>
                                <select :name="'items['+index+'][batch_id]'" x-model="line.batch_id"
                                    class="w-full bg-amber-50 border border-amber-200 rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                    <option value="">Sélectionner le lot...</option>
                                    @foreach($batches as $batch)
                                        <option value="{{ $batch->id }}">
                                            {{ $batch->code }} — {{ $batch->building->name ?? '' }} ({{ $batch->current_quantity }} sujets)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- NOTES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Notes / Instructions au chauffeur</label>
                        <textarea name="notes" rows="2" placeholder="Précautions, itinéraire, horaire d'arrivée attendu..."
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('notes') }}</textarea>
                    </div>
                </div>

                {{-- ALERTE ANTI-FRAUDE --}}
                <div class="bg-amber-50 p-6 rounded-[2.5rem] border border-amber-200 mb-6">
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 bg-amber-500 rounded-xl flex items-center justify-center text-white shrink-0 mt-1">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest mb-1">Système Anti-Fraude Activé</p>
                            <p class="text-[9px] text-amber-600">
                                Les quantités chargées seront comparées automatiquement avec les quantités reçues au point de livraison.
                                Tout écart au-delà des seuils de tolérance générera un rapport d'investigation.
                                Prenez une photo du chargement comme preuve.
                            </p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-orange-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-orange-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-truck-ramp-box mr-2"></i> Valider l'Expédition & Déstocker
                </button>
            </form>
        </div>
    </div>

    <script>
    function dispatchForm() {
        return {
            lines: [{ product_type: 'oeufs', product_name: '', quantity: 1, unit: 'alveole', condition: 'bon', product_id: '', batch_id: '' }],
            addLine() {
                this.lines.push({ product_type: 'oeufs', product_name: '', quantity: 1, unit: 'alveole', condition: 'bon', product_id: '', batch_id: '' });
            },
            removeLine(i) { if (this.lines.length > 1) this.lines.splice(i, 1); },
            onTypeChange(i) {
                const units = { oeufs: 'alveole', volaille_vivante: 'piece', volaille_abattue: 'kg', aliment: 'sac', fumier: 'voyage', materiel: 'piece', autre: 'piece' };
                this.lines[i].unit = units[this.lines[i].product_type] || 'piece';
                if (this.lines[i].product_type !== 'volaille_vivante' && this.lines[i].product_type !== 'volaille_abattue') {
                    this.lines[i].batch_id = '';
                }
            },
        }
    }
    </script>
</x-app-layout>
