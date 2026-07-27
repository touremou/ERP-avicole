<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$species->name" :subtitle="__('Modifier la culture')" icon="fa-book-open" accent="green" :back="route('crop-catalogue.show', $species)" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif
            <x-flash />

            <form action="{{ route('crop-catalogue.update', $species) }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($types as $key => $t)
                                <option value="{{ $key }}" @selected(old('type', $species->type) == $key)>{{ $t['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom *") }}</label>
                        <input type="text" name="name" value="{{ old('name', $species->name) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom local") }}</label>
                        <input type="text" name="local_name" value="{{ old('local_name', $species->local_name) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Famille") }}</label>
                        <input type="text" name="family" value="{{ old('family', $species->family) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle min (jours)") }}</label>
                        <input type="number" min="0" name="cycle_days_min" value="{{ old('cycle_days_min', $species->cycle_days_min) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle max (jours)") }}</label>
                        <input type="number" min="0" name="cycle_days_max" value="{{ old('cycle_days_max', $species->cycle_days_max) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement moyen (t/ha)") }}</label>
                        <input type="number" step="0.01" min="0" name="avg_yield_tha" value="{{ old('avg_yield_tha', $species->avg_yield_tha) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Matériel de plantation") }}</label>
                        <select name="planting_material" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Semence (défaut) --") }}</option>
                            @foreach(\App\Models\CropSpecies::PLANTING_MATERIALS as $key => $label)
                                <option value="{{ $key }}" @selected(old('planting_material', $species->planting_material) == $key)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("On ne plante pas un ananas en kilos de semence : ce choix adapte le formulaire de cycle.") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité de comptage") }}</label>
                        <select name="planting_unit" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- kg (défaut) --") }}</option>
                            @foreach(\App\Models\CropSpecies::PLANTING_UNITS as $unit)
                                <option value="{{ $unit }}" @selected(old('planting_unit', $species->planting_unit) == $unit)>{{ $unit }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Densité de référence (par hectare)") }}</label>
                        <input type="number" min="1" name="planting_density" value="{{ old('planting_density', $species->planting_density) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("Sert à PROPOSER la quantité selon la surface. Jamais une contrainte : l'écartement réel varie.") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Poids moyen d'une unité récoltée (kg)") }}</label>
                        <input type="number" step="0.001" min="0.001" name="avg_unit_weight_kg" value="{{ old('avg_unit_weight_kg', $species->avg_unit_weight_kg) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("Ex. 1,5 pour un ananas, 15 pour un régime de bananes. Laissez vide pour une culture qui ne se compte pas (riz, maïs grain).") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de l'unité récoltée") }}</label>
                        <input type="text" name="harvest_unit_label" list="harvest-unit-list" maxlength="30" value="{{ old('harvest_unit_label', $species->harvest_unit_label) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="harvest-unit-list">
                            @foreach(\App\Models\CropSpecies::HARVEST_UNIT_LABELS as $label)<option value="{{ $label }}">@endforeach
                        </datalist>
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("« ≈ 1 200 régimes » se lit ; « ≈ 1 200 unités » ne se lit pas.") }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Description") }}</label>
                        <textarea name="description" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('description', $species->description) }}</textarea>
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $species->is_active)) class="rounded">
                        <label for="is_active" class="text-[9px] font-black text-slate-500 uppercase italic cursor-pointer">{{ __("Culture active") }}</label>
                    </div>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Enregistrer les modifications") }}
                    </button>
                </div>
            </form>

            {{-- VARIÉTÉS --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-700 tracking-widest italic mb-6">{{ __("Variétés") }}</h3>

                @forelse($species->varieties as $variety)
                    <div class="flex flex-wrap items-end gap-3 p-4 mb-2 bg-slate-50 rounded-[1.5rem]">
                        @can('cultures.M')
                        <form action="{{ route('crop-catalogue.varieties.update', $variety) }}" method="POST" class="flex flex-wrap items-end gap-3 grow">
                            @csrf @method('PUT')
                            <div class="grow min-w-[140px]">
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété *") }}</label>
                                <input type="text" name="name" value="{{ $variety->name }}" required class="w-full bg-white border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                            </div>
                            <div class="w-24">
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle (j)") }}</label>
                                <input type="number" min="1" name="cycle_days" value="{{ $variety->cycle_days }}" class="w-full bg-white border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                            </div>
                            <div class="w-28">
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rdt (t/ha)") }}</label>
                                <input type="number" step="0.01" min="0" name="avg_yield_tha" value="{{ $variety->avg_yield_tha }}" class="w-full bg-white border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                            </div>
                            <button type="submit" class="bg-slate-900 text-white px-4 py-3 rounded-2xl font-black uppercase italic tracking-widest text-[9px] shadow-lg hover:bg-green-600 transition-all">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </form>
                        @else
                        <div class="grow">
                            <p class="text-[12px] font-black uppercase text-slate-800 italic leading-none">{{ $variety->name }}</p>
                            <p class="text-[9px] text-slate-400 uppercase mt-1">
                                @if($variety->cycle_days){{ $variety->cycle_days }} j @endif
                                @if($variety->avg_yield_tha) · {{ number_format($variety->avg_yield_tha, 2, ',', ' ') }} t/ha @endif
                            </p>
                        </div>
                        @endcan
                        @can('cultures.S')
                        <form action="{{ route('crop-catalogue.varieties.destroy', $variety) }}" method="POST" onsubmit="return confirm('Supprimer cette variété ?')">
                            @csrf @method('DELETE')
                            <button class="text-rose-400 hover:text-rose-600 text-xs py-3"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-6">{{ __("Aucune variété") }}</p>
                @endforelse

                @can('cultures.M')
                <form action="{{ route('crop-catalogue.varieties.store', $species) }}" method="POST" class="mt-6 pt-6 border-t border-slate-50 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété *") }}</label>
                        <input type="text" name="name" required class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                    </div>
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle (j)") }}</label>
                        <input type="number" min="1" name="cycle_days" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                    </div>
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rdt (t/ha)") }}</label>
                        <input type="number" step="0.01" min="0" name="avg_yield_tha" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-4 py-3 rounded-2xl font-black uppercase italic tracking-widest text-[9px] shadow-lg hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                    </button>
                </form>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
