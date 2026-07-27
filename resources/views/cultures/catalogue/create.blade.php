<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Nouvelle culture')" :subtitle="__('Fiche du catalogue agronomique')" icon="fa-book-open" accent="green" :back="route('crop-catalogue.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('crop-catalogue.store') }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($types as $key => $meta)
                                <option value="{{ $key }}" @selected(old('type') == $key)>{{ $meta['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom *") }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" required placeholder="{{ __('Maïs') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom local") }}</label>
                        <input type="text" name="local_name" value="{{ old('local_name') }}" placeholder="{{ __('Nom vernaculaire') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Famille botanique") }}</label>
                        <input type="text" name="family" value="{{ old('family') }}" placeholder="{{ __('Poaceae…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle min (j)") }}</label>
                        <input type="number" min="1" name="cycle_days_min" value="{{ old('cycle_days_min') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle max (j)") }}</label>
                        <input type="number" min="1" name="cycle_days_max" value="{{ old('cycle_days_max') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement moyen (t/ha)") }}</label>
                        <input type="number" step="0.01" min="0" name="avg_yield_tha" value="{{ old('avg_yield_tha') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Matériel de plantation") }}</label>
                        <select name="planting_material" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Semence (défaut) --") }}</option>
                            @foreach(\App\Models\CropSpecies::PLANTING_MATERIALS as $key => $label)
                                <option value="{{ $key }}" @selected(old('planting_material', null) == $key)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("On ne plante pas un ananas en kilos de semence : ce choix adapte le formulaire de cycle.") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité de comptage") }}</label>
                        <select name="planting_unit" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- kg (défaut) --") }}</option>
                            @foreach(\App\Models\CropSpecies::PLANTING_UNITS as $unit)
                                <option value="{{ $unit }}" @selected(old('planting_unit', null) == $unit)>{{ $unit }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Densité de référence (par hectare)") }}</label>
                        <input type="number" min="1" name="planting_density" value="{{ old('planting_density', null) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("Sert à PROPOSER la quantité selon la surface. Jamais une contrainte : l'écartement réel varie.") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Poids moyen d'une unité récoltée (kg)") }}</label>
                        <input type="number" step="0.001" min="0.001" name="avg_unit_weight_kg" value="{{ old('avg_unit_weight_kg', null) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("Ex. 1,5 pour un ananas, 15 pour un régime de bananes. Laissez vide pour une culture qui ne se compte pas (riz, maïs grain).") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de l'unité récoltée") }}</label>
                        <input type="text" name="harvest_unit_label" list="harvest-unit-list" maxlength="30" value="{{ old('harvest_unit_label', null) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="harvest-unit-list">
                            @foreach(\App\Models\CropSpecies::HARVEST_UNIT_LABELS as $label)<option value="{{ $label }}">@endforeach
                        </datalist>
                        <p class="text-[8px] font-black text-slate-400 uppercase mt-1 ml-2 italic">{{ __("« ≈ 1 200 régimes » se lit ; « ≈ 1 200 unités » ne se lit pas.") }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Description") }}</label>
                        <textarea name="description" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('description') }}</textarea>
                    </div>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-plus mr-2 text-green-400"></i> {{ __("Ajouter au catalogue") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
