<x-app-layout>
    @php
        $currency = setting('general.currency', 'GNF');
        // Fabrique UNIQUE (CropSpecies::formCatalogue) : ce tableau vivait en deux
        // copies, et l'une avait déjà divergé de l'autre.
        $catalogue = \App\Models\CropSpecies::formCatalogue($species);
    @endphp
    <x-slot name="header">
        <x-page-header :title="$cycle->crop_name" :subtitle="__('Modifier le cycle')" icon="fa-seedling" accent="green" :back="route('crop-cycles.show', $cycle)" />
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

            <form action="{{ route('crop-cycles.update', $cycle) }}" method="POST"
                  x-data="cropCycleForm({
                      catalogue: {{ Js::from($catalogue) }},
                      maxAreaHa: {{ Js::from($maxAreaHa) }},
                      initial: {{ Js::from([
                          'cropName' => old('crop_name', $cycle->crop_name),
                          'variety' => old('variety', $cycle->variety ?? ''),
                          'areaHa' => old('area_used_ha', (string) $cycle->area_used_ha),
                          'plantingDate' => old('planting_date', $cycle->planting_date?->format('Y-m-d')),
                          'expectedHarvest' => old('expected_harvest_date', $cycle->expected_harvest_date?->format('Y-m-d')),
                          'expectedYield' => old('expected_yield_kg', $cycle->expected_yield_kg ? (string) $cycle->expected_yield_kg : ''),
                          'seedQuantity' => old('seed_quantity', $cycle->seed_quantity ? (string) $cycle->seed_quantity : ''),
                          'seedUnit' => old('seed_unit', $cycle->seed_unit ?? 'kg'),
                      ]) }},
                  })"
                  class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')

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
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Culture *") }}</label>
                        <input type="text" name="crop_name" list="crop-species-list" x-model="cropName" value="{{ old('crop_name', $cycle->crop_name) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="crop-species-list">
                            @foreach($species as $sp)<option value="{{ $sp->name }}">@endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété") }}</label>
                        <input type="text" name="variety" list="crop-variety-list" x-model="variety" value="{{ old('variety', $cycle->variety) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="crop-variety-list">
                            <template x-for="v in (match ? match.varieties : [])" :key="v.name">
                                <option :value="v.name"></option>
                            </template>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Campagne") }}</label>
                        <select name="campaign_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Hors campagne --") }}</option>
                            @foreach($campaigns as $camp)
                                <option value="{{ $camp->id }}" @selected(old('campaign_id', $cycle->campaign_id) == $camp->id)>{{ $camp->name }} ({{ $camp->year }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Protocole / itinéraire technique") }}</label>
                        <select name="crop_protocol_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($protocols as $proto)
                                <option value="{{ $proto->id }}" @selected(old('crop_protocol_id', $cycle->crop_protocol_id) == $proto->id)>{{ $proto->name }}@if($proto->crop_name) ({{ $proto->crop_name }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Responsable") }}</label>
                        <select name="employee_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected(old('employee_id', $cycle->employee_id) == $emp->id)>{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Surface emblavée (ha) *") }}</label>
                        <input type="number" step="0.01" min="0" name="area_used_ha" x-model="areaHa" value="{{ old('area_used_ha', $cycle->area_used_ha) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <template x-if="maxAreaHa !== null">
                            <p class="text-[9px] font-black mt-1 ml-2 italic" :class="areaExceedsLimit() ? 'text-red-500' : 'text-slate-400'"
                               x-text="areaExceedsLimit() ? 'Surface dépasse le disponible (' + maxAreaHa.toFixed(2) + ' ha)' : 'Disponible sur cette parcelle : ' + maxAreaHa.toFixed(2) + ' ha'"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Statut *") }}</label>
                        <select name="status" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $cycle->status) == $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de semis *") }}</label>
                        <input type="date" name="planting_date" x-model="plantingDate" value="{{ old('planting_date', $cycle->planting_date?->format('Y-m-d')) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Récolte prévue") }}</label>
                        <input type="date" name="expected_harvest_date" x-model="expectedHarvest" value="{{ old('expected_harvest_date', $cycle->expected_harvest_date?->format('Y-m-d')) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        {{-- Libellé ADAPTÉ à la culture, comme à la création : les deux
                             écrans partagent désormais la même logique. --}}
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic" x-text="plantingLabel()"></label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" min="0" name="seed_quantity" x-model="seedQuantity" value="{{ old('seed_quantity', $cycle->seed_quantity) }}" class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="seed_unit" x-model="seedUnit" value="{{ old('seed_unit', $cycle->seed_unit ?? 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                        <template x-if="densityHint()">
                            <p class="text-[9px] font-black text-slate-400 mt-1 ml-2 italic" x-text="densityHint()"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement attendu (kg)") }}</label>
                        <input type="number" step="0.01" min="0" name="expected_yield_kg" x-model="expectedYield" value="{{ old('expected_yield_kg', $cycle->expected_yield_kg) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        {{-- Le rendement reste un POIDS même pour une culture comptée
                             à l'unité : on l'explique là où le doute naît. --}}
                        <template x-if="yieldHint()">
                            <p class="text-[9px] font-black text-slate-400 mt-1 ml-2 italic" x-text="yieldHint()"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût semences/intrants") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="total_acquisition_cost" value="{{ old('total_acquisition_cost', $cycle->total_acquisition_cost) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coûts additionnels") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="additional_costs" value="{{ old('additional_costs', $cycle->additional_costs) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Revenu total") }} ({{ $currency }})</label>
                        <p class="w-full bg-slate-50 rounded-2xl p-4 font-black text-green-700 shadow-inner italic text-right text-[11px]">
                            {{ number_format((float) $cycle->total_revenue, 0, ',', ' ') }}
                            <span class="text-slate-400 text-[9px] ml-1">{{ __("calculé depuis les récoltes") }}</span>
                        </p>
                    </div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes', $cycle->notes) }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Enregistrer les modifications") }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    @include('cultures.cycles.partials.form-script')
</x-app-layout>
