<x-app-layout>
    <div class="max-w-3xl mx-auto py-12 px-6 italic font-bold text-left">
        <a href="{{ route('utilities.energy.sources') }}" class="text-xs text-slate-400 uppercase tracking-widest hover:text-slate-900 mb-6 inline-block"><i class="fa-solid fa-arrow-left mr-2"></i>Retour</a>
        
        <div class="bg-amber-50 p-10 rounded-[3rem] border border-amber-200">
            <h2 class="text-2xl font-black text-amber-700 uppercase tracking-tighter mb-8"><i class="fa-solid fa-pen mr-2"></i> Modifier la source d'énergie</h2>
            <form method="POST" action="{{ route('utilities.energy.sources.update', $source->id) }}" class="space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Nom *</label>
                        <input type="text" name="name" value="{{ $source->name }}" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Type *</label>
                        <select name="type" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                            <option value="edg" {{ $source->type === 'edg' ? 'selected' : '' }}>EDG</option>
                            <option value="groupe" {{ $source->type === 'groupe' ? 'selected' : '' }}>Groupe</option>
                            <option value="solaire" {{ $source->type === 'solaire' ? 'selected' : '' }}>Solaire</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Capacité kVA</label>
                        <input type="number" step="0.1" name="capacity_kva" value="{{ $source->capacity_kva }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Carburant</label>
                        <select name="fuel_type" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                            <option value="">N/A</option>
                            <option value="gasoil" {{ $source->fuel_type === 'gasoil' ? 'selected' : '' }}>Gasoil</option>
                            <option value="essence" {{ $source->fuel_type === 'essence' ? 'selected' : '' }}>Essence</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Capacité Cuve (L)</label>
                        <input type="number" name="fuel_tank_capacity" value="{{ $source->fuel_tank_capacity }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Interv. Maint. (H)</label>
                        <input type="number" name="maintenance_interval_hours" value="{{ $source->maintenance_interval_hours }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                </div>
                <button type="submit" class="w-full bg-amber-500 text-white py-4 rounded-2xl font-black uppercase hover:bg-amber-600 shadow-lg mt-4">Enregistrer les modifications</button>
            </form>
        </div>
    </div>
</x-app-layout>