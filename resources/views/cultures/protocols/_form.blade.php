{{-- Champs partagés création/édition d'un itinéraire technique. Attend $protocol (nullable), $zones, $itemTypes, $species. --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="md:col-span-2">
        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de l'itinéraire *") }}</label>
        <input type="text" name="name" value="{{ old('name', $protocol->name ?? '') }}" required placeholder="{{ __('Itinéraire maïs pluvial — IRAG') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
    </div>
    <div>
        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Culture cible") }}</label>
        <input type="text" name="crop_name" list="protocol-crop-list" value="{{ old('crop_name', $protocol->crop_name ?? '') }}" placeholder="{{ __('Maïs, Riz, Tomate… (vide = générique)') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
        <datalist id="protocol-crop-list">
            @foreach($species as $sp)<option value="{{ $sp->name }}">@endforeach
        </datalist>
    </div>
    <div>
        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Zone agro-écologique") }}</label>
        <select name="agro_zone" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
            <option value="">{{ __("-- Toutes zones --") }}</option>
            @foreach($zones as $key => $label)<option value="{{ $key }}" @selected(old('agro_zone', $protocol->agro_zone ?? '') == $key)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Source") }}</label>
        <input type="text" name="source" value="{{ old('source', $protocol->source ?? 'IRAG/FAO (indicatif)') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
    </div>
    <div class="flex items-center gap-3 pt-6">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $protocol->is_active ?? true)) class="rounded">
        <label for="is_active" class="text-[9px] font-black text-slate-500 uppercase italic cursor-pointer">{{ __("Itinéraire actif") }}</label>
    </div>
    <div class="md:col-span-2">
        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Description") }}</label>
        <textarea name="description" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('description', $protocol->description ?? 'Itinéraire indicatif — à adapter aux conditions locales (sol, variété, météo).') }}</textarea>
    </div>
</div>

{{-- ÉTAPES (lignes dynamiques, triées par DAP) --}}
<div class="pt-4 border-t border-slate-50">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-[10px] font-black uppercase text-slate-700 tracking-widest italic">{{ __("Étapes de l'itinéraire (jours après semis)") }}</h3>
        <button type="button" @click="items.push({ day_number: '', stage: '', action_name: '', type: 'autre', product_suggested: '', dose: '', method: '', notes: '' })" class="text-[9px] font-black uppercase text-green-600 italic"><i class="fa-solid fa-plus mr-1"></i>{{ __("Ajouter une étape") }}</button>
    </div>

    <template x-for="(item, i) in items" :key="i">
        <div class="bg-slate-50 rounded-[1.5rem] p-4 mb-3">
            <div class="grid grid-cols-12 gap-2 items-center">
                <div class="col-span-2">
                    <label class="block text-[7px] font-black text-slate-400 uppercase italic mb-1">{{ __("J+ (DAP)") }}</label>
                    <input type="number" min="0" :name="`items[${i}][day_number]`" x-model="item.day_number" placeholder="0" class="w-full bg-white border-none rounded-xl p-2.5 font-black text-slate-800 shadow-inner italic text-[11px] text-center">
                </div>
                <div class="col-span-3">
                    <label class="block text-[7px] font-black text-slate-400 uppercase italic mb-1">{{ __("Stade") }}</label>
                    <input type="text" :name="`items[${i}][stage]`" x-model="item.stage" placeholder="{{ __('Levée…') }}" class="w-full bg-white border-none rounded-xl p-2.5 font-black text-slate-800 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-4">
                    <label class="block text-[7px] font-black text-slate-400 uppercase italic mb-1">{{ __("Action *") }}</label>
                    <input type="text" :name="`items[${i}][action_name]`" x-model="item.action_name" placeholder="{{ __('Apport NPK de fond') }}" class="w-full bg-white border-none rounded-xl p-2.5 font-black text-slate-800 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-2">
                    <label class="block text-[7px] font-black text-slate-400 uppercase italic mb-1">{{ __("Type") }}</label>
                    <select :name="`items[${i}][type]`" x-model="item.type" class="w-full bg-white border-none rounded-xl p-2.5 font-black text-green-700 shadow-inner italic text-[10px] appearance-none cursor-pointer">
                        @foreach($itemTypes as $key => $meta)<option value="{{ $key }}">{{ $meta['label'] }}</option>@endforeach
                    </select>
                </div>
                <div class="col-span-1 flex items-end justify-center h-full pb-1">
                    <button type="button" @click="items.splice(i, 1)" class="text-rose-300 hover:text-rose-600"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <div class="grid grid-cols-12 gap-2 items-center mt-2">
                <div class="col-span-4">
                    <input type="text" :name="`items[${i}][product_suggested]`" x-model="item.product_suggested" placeholder="{{ __('Produit suggéré') }}" class="w-full bg-white border-none rounded-xl p-2.5 font-bold text-slate-700 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-3">
                    <input type="text" :name="`items[${i}][dose]`" x-model="item.dose" placeholder="{{ __('Dose (200 kg/ha)') }}" class="w-full bg-white border-none rounded-xl p-2.5 font-bold text-slate-700 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-5">
                    <input type="text" :name="`items[${i}][method]`" x-model="item.method" placeholder="{{ __('Méthode (épandage…)') }}" class="w-full bg-white border-none rounded-xl p-2.5 font-bold text-slate-700 shadow-inner italic text-[11px]">
                </div>
            </div>
        </div>
    </template>
</div>
