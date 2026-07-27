<x-app-layout>
    @php
        $currency = setting('general.currency', 'GNF');
        // Référentiel agronomique encodé pour l'auto-remplissage (cf. catalogue).
        $catalogue = $species->map(fn ($sp) => [
            'name'           => $sp->name,
            'local_name'     => $sp->local_name,
            'cycle_days_min' => $sp->cycle_days_min,
            'cycle_days_max' => $sp->cycle_days_max,
            'avg_yield_tha'  => $sp->avg_yield_tha !== null ? (float) $sp->avg_yield_tha : null,
            // Matériel de plantation : c'est lui qui adapte le libellé, l'unité et
            // la quantité suggérée. « Nombre de rejets (unité) » pour un ananas.
            'planting_material' => $sp->planting_material,
            'planting_unit'     => $sp->planting_unit,
            'planting_density'  => $sp->planting_density !== null ? (int) $sp->planting_density : null,
            'varieties'      => $sp->varieties->map(fn ($v) => [
                'name'          => $v->name,
                'cycle_days'    => $v->cycle_days,
                'avg_yield_tha' => $v->avg_yield_tha !== null ? (float) $v->avg_yield_tha : null,
            ])->values(),
        ])->values();
    @endphp
    <x-slot name="header">
        <x-page-header :title="__('Nouveau Cycle de Culture')" :subtitle="__('Démarrage d\'un semis')" icon="fa-seedling" accent="green" :back="route('crop-cycles.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            @if($plots->isEmpty())
                <div class="bg-amber-50 border border-amber-200 text-amber-700 p-6 rounded-[2rem] mb-8 text-[10px] font-black uppercase italic">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i> {{ __("Aucune parcelle disponible. Créez d'abord une parcelle.") }}
                    <a href="{{ route('plots.index') }}" class="underline ml-2">{{ __("Gérer les parcelles") }}</a>
                </div>
            @endif

            <form action="{{ route('crop-cycles.store') }}" method="POST"
                  x-data="cropCycleForm({
                      catalogue: {{ Js::from($catalogue) }},
                      plotData: {{ Js::from($plotData) }},
                      initial: {{ Js::from([
                          'cropName' => old('crop_name', ''),
                          'variety' => old('variety', ''),
                          'areaHa' => old('area_used_ha', ''),
                          'plantingDate' => old('planting_date', now()->toDateString()),
                          'expectedHarvest' => old('expected_harvest_date', ''),
                          'expectedYield' => old('expected_yield_kg', ''),
                          'seedQuantity' => old('seed_quantity', ''),
                          'seedUnit' => old('seed_unit', 'kg'),
                          'selectedPlotId' => old('plot_id', ''),
                      ]) }},
                  })"
                  class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf

                {{-- Bandeau d'auto-remplissage depuis le catalogue --}}
                <template x-if="match">
                    <div class="bg-green-50 border border-green-100 text-green-700 p-4 rounded-[1.5rem] text-[10px] font-black uppercase tracking-widest italic flex items-center justify-between gap-4">
                        <span>
                            <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>
                            <span x-text="hint"></span>
                        </span>
                        <button type="button" @click="applySuggestions()" class="shrink-0 bg-green-600 text-white px-4 py-2 rounded-full hover:bg-green-700 transition-all text-[9px]">
                            <i class="fa-solid fa-check mr-1"></i> {{ __("Pré-remplir") }}
                        </button>
                    </div>
                </template>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle *") }}</label>
                        <select name="plot_id" x-model="selectedPlotId" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Choisir --") }}</option>
                            @foreach($plots as $plot)
                                <option value="{{ $plot->id }}" @selected(old('plot_id') == $plot->id)>
                                    {{ $plot->name }} — {{ number_format($plot->remaining_ha, 2, ',', ' ') }} ha dispo / {{ number_format($plot->area_ha, 2, ',', ' ') }} ha
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Responsable") }}</label>
                        <select name="employee_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Campagne") }}</label>
                        <select name="campaign_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Hors campagne --") }}</option>
                            @foreach($campaigns as $camp)
                                <option value="{{ $camp->id }}" @selected(old('campaign_id') == $camp->id)>{{ $camp->name }} ({{ $camp->year }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Protocole / itinéraire technique") }}</label>
                        <select name="crop_protocol_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($protocols as $proto)
                                <option value="{{ $proto->id }}" @selected(old('crop_protocol_id') == $proto->id)>{{ $proto->name }}@if($proto->crop_name) ({{ $proto->crop_name }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Culture *") }}</label>
                        <input type="text" name="crop_name" list="crop-species-list" x-model="cropName" value="{{ old('crop_name') }}" required placeholder="{{ __('Maïs, manioc, tomate…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="crop-species-list">
                            @foreach($species as $sp)<option value="{{ $sp->name }}">@endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété") }}</label>
                        <input type="text" name="variety" list="crop-variety-list" x-model="variety" value="{{ old('variety') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="crop-variety-list">
                            <template x-for="v in (match ? match.varieties : [])" :key="v.name">
                                <option :value="v.name"></option>
                            </template>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Surface emblavée (ha) *") }}</label>
                        <input type="number" step="0.01" min="0" name="area_used_ha" x-model="areaHa" value="{{ old('area_used_ha') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <template x-if="maxAreaHa !== null">
                            <p class="text-[9px] font-black mt-1 ml-2 italic" :class="areaExceedsLimit() ? 'text-red-500' : 'text-slate-400'"
                               x-text="areaExceedsLimit() ? 'Surface dépasse le disponible (' + maxAreaHa.toFixed(2) + ' ha)' : 'Disponible sur cette parcelle : ' + maxAreaHa.toFixed(2) + ' ha'"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <input type="text" name="code" value="{{ old('code') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic uppercase">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de semis *") }}</label>
                        <input type="date" name="planting_date" x-model="plantingDate" value="{{ old('planting_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Récolte prévue") }}</label>
                        <input type="date" name="expected_harvest_date" x-model="expectedHarvest" value="{{ old('expected_harvest_date') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        {{-- Libellé ADAPTÉ à la culture : « Nombre de rejets (unité) »
                             pour un ananas, « Quantité de semences (kg) » pour du maïs.
                             On ne plante pas un ananas en kilos de semence. --}}
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic" x-text="plantingLabel()"></label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" min="0" name="seed_quantity" x-model="seedQuantity" value="{{ old('seed_quantity') }}" class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="seed_unit" x-model="seedUnit" value="{{ old('seed_unit', 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                        <template x-if="densityHint()">
                            <p class="text-[9px] font-black text-slate-400 mt-1 ml-2 italic" x-text="densityHint()"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement attendu (kg)") }}</label>
                        <input type="number" step="0.01" min="0" name="expected_yield_kg" x-model="expectedYield" value="{{ old('expected_yield_kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        {{-- Le rendement reste un POIDS même pour une culture comptée
                             à l'unité : « 50 000 » sous un champ « rejets » invite au
                             doute, on l'explique donc à l'écran. --}}
                        <template x-if="yieldHint()">
                            <p class="text-[9px] font-black text-slate-400 mt-1 ml-2 italic" x-text="yieldHint()"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût semences/intrants") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="total_acquisition_cost" value="{{ old('total_acquisition_cost') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coûts additionnels") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="additional_costs" value="{{ old('additional_costs') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-seedling mr-2 text-green-400"></i> {{ __("Démarrer le Cycle") }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    @include('cultures.cycles.partials.form-script')
</x-app-layout>
