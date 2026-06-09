<x-app-layout>
    <div class="max-w-3xl mx-auto py-12 px-6 italic font-bold text-left">
        <a href="{{ route('utilities.water.sources') }}" class="text-xs text-slate-400 uppercase tracking-widest hover:text-slate-900 mb-6 inline-block"><i class="fa-solid fa-arrow-left mr-2"></i>Retour</a>
        
        <div class="bg-cyan-50 p-10 rounded-[3rem] border border-cyan-200">
            <h2 class="text-2xl font-black text-cyan-700 uppercase tracking-tighter mb-8"><i class="fa-solid fa-pen mr-2"></i> Modifier la source d'eau</h2>
            <form method="POST" action="{{ route('utilities.water.sources.update', $source->id) }}" class="space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Nom *</label>
                        <input type="text" name="name" value="{{ $source->name }}" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Type *</label>
                        <select name="type" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                            <option value="seeg" {{ $source->type === 'seeg' ? 'selected' : '' }}>SEEG</option>
                            <option value="forage" {{ $source->type === 'forage' ? 'selected' : '' }}>Forage</option>
                            <option value="citerne" {{ $source->type === 'citerne' ? 'selected' : '' }}>Citerne</option>
                            <option value="camion" {{ $source->type === 'camion' ? 'selected' : '' }}>Camion-citerne</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-slate-400 ml-2">Capacité (L)</label>
                        <input type="number" name="capacity_liters" value="{{ $source->capacity_liters }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                    </div>
                </div>
                <button type="submit" class="w-full bg-cyan-500 text-white py-4 rounded-2xl font-black uppercase hover:bg-cyan-600 shadow-lg mt-4">Enregistrer les modifications</button>
            </form>
        </div>
    </div>
</x-app-layout>