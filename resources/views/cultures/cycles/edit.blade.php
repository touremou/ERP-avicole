<x-app-layout>
    @php
        $currency = setting('general.currency', 'GNF');
        $catalogue = $species->map(fn ($sp) => [
            'name'           => $sp->name,
            'local_name'     => $sp->local_name,
            'cycle_days_min' => $sp->cycle_days_min,
            'cycle_days_max' => $sp->cycle_days_max,
            'avg_yield_tha'  => $sp->avg_yield_tha !== null ? (float) $sp->avg_yield_tha : null,
            'varieties'      => $sp->varieties->map(fn ($v) => [
                'name'          => $v->name,
                'cycle_days'    => $v->cycle_days,
                'avg_yield_tha' => $v->avg_yield_tha !== null ? (float) $v->avg_yield_tha : null,
            ])->values(),
        ])->values();
    @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-seedling text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $cycle->crop_name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Modifier le cycle") }}</p>
                </div>
            </div>
            <a href="{{ route('crop-cycles.show', $cycle) }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> {{ __("Annuler") }}
            </a>
        </div>
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
                  x-data="cropCycleForm({{ Js::from($catalogue) }})"
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
                        <input type="text" name="crop_name" list="crop-species-list" x-model="cropName" @input="onCropChange()" value="{{ old('crop_name', $cycle->crop_name) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="crop-species-list">
                            @foreach($species as $sp)<option value="{{ $sp->name }}">@endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété") }}</label>
                        <input type="text" name="variety" list="crop-variety-list" x-model="variety" @input="onVarietyChange()" value="{{ old('variety', $cycle->variety) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
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
                        <input type="number" step="0.01" min="0" name="area_used_ha" x-model="areaHa" @input="recompute()" value="{{ old('area_used_ha', $cycle->area_used_ha) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
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
                        <input type="date" name="planting_date" x-model="plantingDate" @change="recompute()" value="{{ old('planting_date', $cycle->planting_date?->format('Y-m-d')) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Récolte prévue") }}</label>
                        <input type="date" name="expected_harvest_date" x-model="expectedHarvest" value="{{ old('expected_harvest_date', $cycle->expected_harvest_date?->format('Y-m-d')) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité semence") }}</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" min="0" name="seed_quantity" value="{{ old('seed_quantity', $cycle->seed_quantity) }}" class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="seed_unit" value="{{ old('seed_unit', $cycle->seed_unit ?? 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement attendu (kg)") }}</label>
                        <input type="number" step="0.01" min="0" name="expected_yield_kg" x-model="expectedYield" value="{{ old('expected_yield_kg', $cycle->expected_yield_kg) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
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
                        <input type="number" step="1" min="0" name="total_revenue" value="{{ old('total_revenue', $cycle->total_revenue) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
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

    <script>
        function cropCycleForm(catalogue) {
            return {
                catalogue: catalogue,
                cropName: @js(old('crop_name', $cycle->crop_name)),
                variety: @js(old('variety', $cycle->variety ?? '')),
                areaHa: @js(old('area_used_ha', (string) $cycle->area_used_ha)),
                plantingDate: @js(old('planting_date', $cycle->planting_date?->format('Y-m-d'))),
                expectedHarvest: @js(old('expected_harvest_date', $cycle->expected_harvest_date?->format('Y-m-d'))),
                expectedYield: @js(old('expected_yield_kg', $cycle->expected_yield_kg ? (string) $cycle->expected_yield_kg : '')),
                match: null,
                hint: '',

                init() { this.resolveMatch(); },

                resolveMatch() {
                    const needle = (this.cropName || '').trim().toLowerCase();
                    this.match = this.catalogue.find(s => s.name.toLowerCase() === needle) || null;
                    this.buildHint();
                },
                onCropChange() { this.resolveMatch(); },
                onVarietyChange() { this.buildHint(); },

                currentVariety() {
                    if (!this.match) return null;
                    const needle = (this.variety || '').trim().toLowerCase();
                    return this.match.varieties.find(v => v.name.toLowerCase() === needle) || null;
                },
                effectiveCycleDays() {
                    const v = this.currentVariety();
                    if (v && v.cycle_days) return v.cycle_days;
                    if (this.match && this.match.cycle_days_max) return this.match.cycle_days_max;
                    if (this.match && this.match.cycle_days_min) return this.match.cycle_days_min;
                    return null;
                },
                effectiveYieldTha() {
                    const v = this.currentVariety();
                    if (v && v.avg_yield_tha) return v.avg_yield_tha;
                    if (this.match && this.match.avg_yield_tha) return this.match.avg_yield_tha;
                    return null;
                },
                buildHint() {
                    if (!this.match) { this.hint = ''; return; }
                    const parts = [];
                    if (this.match.local_name) parts.push('Nom local : ' + this.match.local_name);
                    const days = this.effectiveCycleDays();
                    if (days) parts.push('Cycle ≈ ' + days + ' j');
                    const tha = this.effectiveYieldTha();
                    if (tha) parts.push('Rdt réf. ' + tha + ' t/ha');
                    this.hint = (parts.length ? this.match.name + ' — ' + parts.join(' · ') : this.match.name)
                        + ' · cliquez pour pré-remplir';
                },
                suggestions() {
                    const out = { harvest: null, yield: null };
                    const days = this.effectiveCycleDays();
                    if (this.plantingDate && days) {
                        const d = new Date(this.plantingDate);
                        d.setDate(d.getDate() + parseInt(days, 10));
                        out.harvest = d.toISOString().slice(0, 10);
                    }
                    const tha = this.effectiveYieldTha();
                    const area = parseFloat(this.areaHa);
                    if (tha && area > 0) out.yield = Math.round(tha * area * 1000);
                    return out;
                },
                applySuggestions() {
                    const s = this.suggestions();
                    if (s.harvest) this.expectedHarvest = s.harvest;
                    if (s.yield !== null) this.expectedYield = s.yield;
                },
                recompute() {
                    if (!this.match) return;
                    const s = this.suggestions();
                    if (s.harvest && !this.expectedHarvest) this.expectedHarvest = s.harvest;
                    if (s.yield !== null && !this.expectedYield) this.expectedYield = s.yield;
                    this.buildHint();
                },
            };
        }
    </script>
</x-app-layout>
