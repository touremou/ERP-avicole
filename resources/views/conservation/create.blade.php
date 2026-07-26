@php
    /**
     * Ouverture d'un lot de conservation (T2) — la formalisation d'un pari.
     *
     * Les trois champs qui font la différence entre une décision et un oubli :
     * prix-cible, échéance de détention, fréquence de contrôle. Pré-remplis
     * depuis la source (récolte ou lot transformé) pour ne pas ressaisir le coût.
     */
    $currency = setting('general.currency', 'GNF');
    $stockId = old('stock_id', request('stock_id'));
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Mettre un stock en conservation')"
                       :subtitle="__('Prix-cible, échéance et contrôle périodique')"
                       icon="fa-boxes-stacked" accent="amber" :back="route('stored-lots.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif
            @if(session('error'))
                <div class="bg-rose-600 text-white p-5 rounded-[2rem] mb-8 text-[10px] font-black uppercase tracking-widest">{{ session('error') }}</div>
            @endif

            <form action="{{ route('stored-lots.store') }}" method="POST"
                  x-data="{ qty: {{ (float) old('quantity_initial', $prefill['quantity'] ?? 0) }},
                            cost: {{ (float) old('unit_cost', $prefill['unit_cost'] ?? 0) }},
                            target: {{ (float) old('target_unit_price', $prefill['target'] ?? 0) }},
                            get engaged() { return this.qty * this.cost },
                            get expected() { return this.qty * this.target },
                            get gain() { return this.expected - this.engaged },
                            money(v) { return new Intl.NumberFormat('fr-FR').format(Math.round(v || 0)) } }"
                  class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf

                @if($transformation)
                    <p class="text-[9px] font-black text-green-600 uppercase italic bg-green-50 rounded-2xl p-4">
                        <i class="fa-solid fa-industry mr-1"></i>
                        {{ __("Depuis le lot transformé") }} {{ $transformation->batch_number }}
                        — {{ __("coût de revient") }} {{ number_format((float) $transformation->output_unit_cost, 0, ',', ' ') }} {{ $currency }}/{{ $transformation->output_unit }}
                    </p>
                    <input type="hidden" name="crop_transformation_id" value="{{ $transformation->id }}">
                @elseif($harvest)
                    <p class="text-[9px] font-black text-green-600 uppercase italic bg-green-50 rounded-2xl p-4">
                        <i class="fa-solid fa-wheat-awn mr-1"></i>
                        {{ __("Depuis la récolte du") }} {{ $harvest->harvest_date->format('d/m/Y') }}
                        — {{ $harvest->cropCycle?->crop_name }}
                    </p>
                    <input type="hidden" name="harvest_id" value="{{ $harvest->id }}">
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Article en stock *") }}</label>
                        <select name="stock_id" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Choisir --") }}</option>
                            @foreach($stocks as $stock)
                                <option value="{{ $stock->id }}" @selected($stockId == $stock->id)>
                                    {{ $stock->item_name }} — {{ number_format((float) $stock->current_quantity, 1, ',', ' ') }} {{ $stock->unit }}
                                    @if($stock->last_unit_price) ({{ number_format((float) $stock->last_unit_price, 0, ',', ' ') }} {{ $currency }}/{{ $stock->unit }}) @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Libellé du lot *") }}</label>
                        <input type="text" name="label" required value="{{ old('label', $prefill['label']) }}"
                               placeholder="{{ __('Gombo séché — récolte du 12/08') }}"
                               class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>

                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité mise de côté *") }}</label>
                            <input type="number" step="0.001" min="0.001" name="quantity_initial" x-model.number="qty"
                                   value="{{ old('quantity_initial', $prefill['quantity']) }}" required
                                   class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        </div>
                        <div class="w-1/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                            <input type="text" name="unit" value="{{ old('unit', $prefill['unit']) }}"
                                   class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût de revient") }} ({{ $currency }}/u)</label>
                        <input type="number" step="1" min="0" name="unit_cost" x-model.number="cost"
                               value="{{ old('unit_cost', $prefill['unit_cost']) }}"
                               class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Figé à l'ouverture — le CMP du stock bougera, la rentabilité de CE pari se juge ici") }}</p>
                    </div>

                    <div>
                        <label class="block text-[9px] font-black text-amber-500 uppercase ml-2 mb-1 italic">{{ __("Prix-cible de vente *") }} ({{ $currency }}/u)</label>
                        <input type="number" step="1" min="0" name="target_unit_price" x-model.number="target"
                               value="{{ old('target_unit_price', $prefill['target']) }}"
                               class="w-full bg-amber-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Sans objectif, « plus tard » n'a pas de critère d'arrêt") }}</p>
                    </div>

                    <div>
                        <label class="block text-[9px] font-black text-amber-500 uppercase ml-2 mb-1 italic">{{ __("Échéance de détention") }}</label>
                        <input type="date" name="hold_until" value="{{ old('hold_until') }}"
                               class="w-full bg-amber-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Au-delà : vendre ou déclasser. Sans date, le lot se garde jusqu'au rebut") }}</p>
                    </div>

                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Contrôle tous les (jours)") }}</label>
                        <input type="number" min="1" max="180" name="check_interval_days" value="{{ old('check_interval_days', 14) }}"
                               class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Génère une tâche de pesée au calendrier") }}</p>
                    </div>
                </div>

                {{-- LE PARI, CHIFFRÉ. Ce bloc répond à « est-ce que ça vaut le coup
                     d'attendre ? » AVANT d'immobiliser la marchandise. --}}
                <div class="bg-slate-900 rounded-[2rem] p-6 shadow-inner" x-show="qty > 0 && cost > 0" x-cloak>
                    <p class="text-[9px] font-black text-white/40 uppercase italic mb-4">
                        <i class="fa-solid fa-scale-balanced mr-1"></i> {{ __("Le pari, chiffré") }}
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-[8px] font-black text-white/30 uppercase italic">{{ __("Capital immobilisé") }}</p>
                            <p class="text-sm font-black text-white italic" x-text="money(engaged) + ' {{ $currency }}'"></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black text-white/30 uppercase italic">{{ __("Recette au prix-cible") }}</p>
                            <p class="text-sm font-black text-white italic" x-text="money(expected) + ' {{ $currency }}'"></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black text-amber-300 uppercase italic">{{ __("Gain espéré (hors freinte)") }}</p>
                            <p class="text-xl font-black italic" :class="gain >= 0 ? 'text-green-400' : 'text-rose-400'"
                               x-text="money(gain) + ' {{ $currency }}'"></p>
                        </div>
                    </div>
                    <p x-show="target > 0 && gain <= 0" x-cloak class="text-[9px] font-black text-rose-300 uppercase italic mt-4 leading-relaxed">
                        <i class="fa-solid fa-circle-exclamation mr-1"></i>
                        {{ __("Au prix-cible visé, attendre ne rapporte rien : mieux vaut vendre maintenant.") }}
                    </p>
                    <p x-show="gain > 0" x-cloak class="text-[9px] font-bold text-white/40 uppercase italic mt-4 leading-relaxed">
                        {{ __("Gain avant freinte : chaque kilo perdu en conservation en retire une part. Les contrôles périodiques la mesureront.") }}
                    </p>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>

                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-amber-600 transition-all">
                        <i class="fa-solid fa-boxes-stacked mr-2 text-amber-400"></i> {{ __("Ouvrir le lot") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
