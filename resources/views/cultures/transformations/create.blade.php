<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <x-page-header :title="__('Nouvelle Transformation')" :subtitle="__('Récolte → produit fini')" icon="fa-industry" accent="green" :back="route('crop-transformations.index')" />
        </div>
    </x-slot>

    <div class="py-12" x-data="{
            input: 0, output: 0, prodCost: 0, salePrice: 0, inputCostPerUnit: 0,
            get yield() { return this.input > 0 ? (this.output / this.input * 100) : 0 },
            /* Coût de revient (T1) : matière engagée + coût de l'opération, rapporté
               à la sortie. C'est CE chiffre qui valorise le stock du produit fini —
               jamais le prix de vente visé, qui écraserait la marge à zéro. */
            get matterCost() { return this.input * this.inputCostPerUnit },
            get totalCost() { return this.matterCost + (this.prodCost || 0) },
            get unitCost() { return this.output > 0 ? this.totalCost / this.output : 0 },
            get expectedMargin() { return (this.output * (this.salePrice || 0)) - this.totalCost },
            money(v) { return new Intl.NumberFormat('fr-FR').format(Math.round(v || 0)) },
        }">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('crop-transformations.store') }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf

                @if($recipes->isNotEmpty())
                {{-- PRÉ-REMPLISSAGE PAR RECETTE --}}
                <div class="bg-green-50 border border-green-100 p-5 rounded-[2rem]">
                    <label class="block text-[9px] font-black text-green-600 uppercase ml-2 mb-1 italic"><i class="fa-solid fa-book mr-1"></i> {{ __("Partir d'une recette") }}</label>
                    <select name="crop_recipe_id" onchange="applyRecipe(this)" class="w-full bg-white border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                        <option value="">{{ __("-- Aucune (saisie libre) --") }}</option>
                        @foreach($recipes as $r)
                            <option value="{{ $r->id }}"
                                data-type="{{ $r->transformation_type }}"
                                data-output="{{ $r->output_product }}"
                                data-unit="{{ $r->output_unit }}"
                                data-yield="{{ $r->expected_yield_percent }}"
                                data-shelf="{{ $r->shelf_life_days }}"
                                data-input="{{ optional($r->items->first())->input_product }}"
                                @selected(old('crop_recipe_id') == $r->id)>{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <script>
                    function applyRecipe(sel) {
                        const o = sel.options[sel.selectedIndex];
                        if (!o.value) return;
                        const f = sel.form;
                        if (o.dataset.output) f.output_product.value = o.dataset.output;
                        if (o.dataset.input) f.input_product.value = o.dataset.input;
                        if (o.dataset.unit) f.output_unit.value = o.dataset.unit;
                        if (o.dataset.type) f.transformation_type.value = o.dataset.type;
                        if (o.dataset.shelf) {
                            const d = new Date(f.production_date.value || Date.now());
                            d.setDate(d.getDate() + parseInt(o.dataset.shelf));
                            f.expiry_date.value = d.toISOString().slice(0, 10);
                        }
                    }
                </script>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Produit entrant *") }}</label>
                        <input type="text" name="input_product" value="{{ old('input_product') }}" required placeholder="{{ __('Manioc, mangue, maïs…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Produit fini *") }}</label>
                        <input type="text" name="output_product" value="{{ old('output_product') }}" required placeholder="{{ __('Gari, jus, farine…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="transformation_type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected(old('transformation_type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- RÉCOLTE ENGAGÉE (T1) — le meilleur choix : elle porte la
                         traçabilité au lot ET le coût matière réel (coût de
                         production de son cycle). data-cost = coût/kg du cycle. --}}
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-amber-500 uppercase ml-2 mb-1 italic">
                            <i class="fa-solid fa-wheat-awn mr-1"></i> {{ __("Récolte engagée — recommandé") }}
                        </label>
                        <select name="harvest_id" onchange="applyHarvest(this)" class="w-full bg-amber-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="" data-cost="0">{{ __("-- Aucune (coût matière à estimer) --") }}</option>
                            @foreach($pendingHarvests as $h)
                                <option value="{{ $h->id }}"
                                    data-cost="{{ $h->cropCycle?->productionCostPerKg() ?? 0 }}"
                                    data-kg="{{ $h->effective_weight_kg }}"
                                    data-crop="{{ $h->cropCycle?->crop_name }}"
                                    data-cycle="{{ $h->crop_cycle_id }}"
                                    data-item="{{ $h->stock_item_name }}"
                                    @selected(old('harvest_id') == $h->id)>
                                    {{ $h->harvest_date->format('d/m/Y') }} — {{ $h->cropCycle?->crop_name }}
                                    ({{ number_format($h->effective_weight_kg, 1, ',', ' ') }} kg
                                    @ {{ number_format($h->cropCycle?->productionCostPerKg() ?? 0, 0, ',', ' ') }} {{ $currency }}/kg)
                                </option>
                            @endforeach
                        </select>
                        @if($pendingHarvests->isEmpty())
                            <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">
                                {{ __("Aucune récolte marquée « à transformer ». Marquez la destination à la saisie de récolte pour la voir ici.") }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle d'origine") }}</label>
                        <select name="crop_cycle_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun (traçabilité) --") }}</option>
                            @foreach($cycles as $c)
                                <option value="{{ $c->id }}" @selected(old('crop_cycle_id') == $c->id)>{{ $c->crop_name }} {{ $c->code ? "($c->code)" : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité entrée *") }}</label>
                            <input type="number" step="0.001" min="0.001" name="input_quantity" x-model.number="input" value="{{ old('input_quantity') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        </div>
                        <div class="w-1/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                            <input type="text" name="input_unit" value="{{ old('input_unit', 'kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité sortie *") }}</label>
                            <input type="number" step="0.001" min="0" name="output_quantity" x-model.number="output" value="{{ old('output_quantity') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic text-right">
                        </div>
                        <div class="w-1/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                            <input type="text" name="output_unit" value="{{ old('output_unit', 'kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de production *") }}</label>
                        <input type="date" name="production_date" value="{{ old('production_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de péremption") }}</label>
                        <input type="date" name="expiry_date" value="{{ old('expiry_date') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût de production") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="production_cost" x-model.number="prodCost" value="{{ old('production_cost') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Main d'œuvre, bois/gaz, emballage — hors matière première") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Prix de vente VISÉ") }} ({{ $currency }}/u)</label>
                        <input type="number" step="1" min="0" name="output_unit_price" x-model.number="salePrice" value="{{ old('output_unit_price') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Objectif de vente — ne valorise PAS le stock (c'est le coût de revient qui le fait)") }}</p>
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
                </div>

                {{-- INTÉGRATION STOCK --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 p-5 rounded-[2rem]" x-data="{ consume: false, sync: false }">
                    <div>
                        <label class="flex items-center gap-2 cursor-pointer mb-2">
                            <input type="hidden" name="consumed_from_stock" value="0">
                            <input type="checkbox" name="consumed_from_stock" value="1" x-model="consume" class="rounded">
                            <span class="text-[9px] font-black text-slate-500 uppercase italic">{{ __("Déstocker l'intrant (Récoltes)") }}</span>
                        </label>
                        <input type="text" name="input_stock_item" x-show="consume" x-cloak placeholder="{{ __('Nom article stock entrant') }}" class="w-full bg-white border-none rounded-xl p-3 font-black text-blue-800 shadow-inner italic text-[11px]">
                    </div>
                    <div>
                        <label class="flex items-center gap-2 cursor-pointer mb-2">
                            <input type="hidden" name="synced_to_stock" value="0">
                            <input type="checkbox" name="synced_to_stock" value="1" x-model="sync" class="rounded">
                            <span class="text-[9px] font-black text-slate-500 uppercase italic">{{ __("Stocker le produit fini") }}</span>
                        </label>
                        <input type="text" name="output_stock_item" x-show="sync" x-cloak placeholder="{{ __('Nom article produit fini') }}" class="w-full bg-white border-none rounded-xl p-3 font-black text-green-800 shadow-inner italic text-[11px]">
                    </div>
                </div>

                {{-- COÛT DE REVIENT (T1) — la question à laquelle il faut répondre
                     AVANT de sécher : transformer 10 kg de frais pour 1 kg de sec
                     multiplie le coût au kilo par dix. Un prix de vente plus élevé
                     au kilo ne suffit donc pas : c'est la marge attendue, ici, qui
                     dit si l'opération crée ou détruit de la valeur. --}}
                <div class="bg-slate-900 rounded-[2rem] p-6 shadow-inner" x-show="input > 0 && output > 0" x-cloak>
                    <p class="text-[9px] font-black text-white/40 uppercase italic mb-4">
                        <i class="fa-solid fa-calculator mr-1"></i> {{ __("Coût de revient du lot") }}
                    </p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <p class="text-[8px] font-black text-white/30 uppercase italic">{{ __("Matière engagée") }}</p>
                            <p class="text-sm font-black text-white italic" x-text="money(matterCost) + ' {{ $currency }}'"></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black text-white/30 uppercase italic">{{ __("Coût total du lot") }}</p>
                            <p class="text-sm font-black text-white italic" x-text="money(totalCost) + ' {{ $currency }}'"></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black text-amber-300 uppercase italic">{{ __("Coût de revient / unité") }}</p>
                            <p class="text-xl font-black text-amber-300 italic" x-text="money(unitCost)"></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black text-white/30 uppercase italic">{{ __("Marge attendue au prix visé") }}</p>
                            <p class="text-xl font-black italic"
                               :class="expectedMargin >= 0 ? 'text-green-400' : 'text-rose-400'"
                               x-text="money(expectedMargin) + ' {{ $currency }}'"></p>
                        </div>
                    </div>
                    <p x-show="inputCostPerUnit <= 0" x-cloak class="text-[9px] font-bold text-amber-300 uppercase italic mt-4 leading-relaxed">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        {{ __("Coût matière inconnu : liez une récolte, ou cochez le déstockage d'un article valorisé. Sans lui, le coût de revient est incomplet et la marge de vente sera surévaluée.") }}
                    </p>
                    <p x-show="salePrice > 0 && expectedMargin < 0" x-cloak class="text-[9px] font-black text-rose-300 uppercase italic mt-4 leading-relaxed">
                        <i class="fa-solid fa-circle-exclamation mr-1"></i>
                        {{ __("Au prix visé, ce lot perd de l'argent : le rendement de transformation ne compense pas l'écart de prix. Vendre frais serait plus rentable.") }}
                    </p>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-slate-50">
                    <div class="text-left">
                        <p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Rendement calculé") }}</p>
                        <p class="text-2xl font-black text-green-600 italic" x-text="yield.toFixed(1) + '%'">0%</p>
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-industry mr-2 text-green-400"></i> {{ __("Enregistrer") }}
                    </button>
                </div>

                {{-- Choisir une récolte pré-remplit la quantité engagée, l'article
                     et le coût matière au kilo : trois saisies évitées, et le coût
                     vient du cycle réel plutôt que de la mémoire de l'opérateur. --}}
                <script>
                    function applyHarvest(sel) {
                        const o = sel.options[sel.selectedIndex];
                        const f = sel.form;
                        const root = sel.closest('[x-data]');
                        const cost = parseFloat(o.dataset.cost || 0) || 0;
                        if (root && root._x_dataStack) root._x_dataStack[0].inputCostPerUnit = cost;
                        if (!o.value) return;
                        if (o.dataset.kg && !f.input_quantity.value) {
                            f.input_quantity.value = o.dataset.kg;
                            if (root && root._x_dataStack) root._x_dataStack[0].input = parseFloat(o.dataset.kg) || 0;
                        }
                        if (o.dataset.crop && !f.input_product.value) f.input_product.value = o.dataset.crop;
                        if (o.dataset.cycle) f.crop_cycle_id.value = o.dataset.cycle;
                        if (o.dataset.item && f.input_stock_item) f.input_stock_item.value = o.dataset.item;
                    }
                </script>
            </form>
        </div>
    </div>
</x-app-layout>
