<x-app-layout>
    <div class="max-w-3xl mx-auto py-12 px-6 italic font-bold text-left">
        <a href="{{ route('utilities.fuel.index') }}" class="text-xs text-slate-400 uppercase tracking-widest hover:text-slate-900 mb-6 inline-block"><i class="fa-solid fa-arrow-left mr-2"></i>Retour</a>
        
        <div class="bg-orange-50 p-10 rounded-[3rem] border border-orange-200">
            <h2 class="text-2xl font-black text-orange-700 uppercase tracking-tighter mb-8"><i class="fa-solid fa-pen mr-2"></i> Modifier l'achat</h2>
            <form method="POST" action="{{ route('utilities.fuel.update', $purchase->id) }}" class="space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Groupe *</label>
                        <select name="energy_source_id" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                            @foreach($groupes as $g)
                                <option value="{{ $g->id }}" {{ $purchase->energy_source_id == $g->id ? 'selected' : '' }}>{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Date *</label>
                        <input type="date" name="purchase_date" value="{{ $purchase->purchase_date->format('Y-m-d') }}" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Quantité (L) *</label>
                        <input type="number" step="0.1" name="quantity_liters" value="{{ $purchase->quantity_liters }}" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Prix Unitaire *</label>
                        <input type="number" name="unit_price" value="{{ $purchase->unit_price }}" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                </div>
                <button type="submit" class="w-full bg-orange-500 text-white py-4 rounded-2xl font-black uppercase hover:bg-orange-600 shadow-lg mt-4">Enregistrer les modifications</button>
            </form>
        </div>
    </div>
</x-app-layout>