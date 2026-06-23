<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-industry text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Nouvelle Transformation") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Récolte → produit fini") }}</p>
                </div>
            </div>
            <a href="{{ route('crop-transformations.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12" x-data="{ input: 0, output: 0, get yield() { return this.input > 0 ? (this.output / this.input * 100) : 0 } }">
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
                        <input type="number" step="1" min="0" name="production_cost" value="{{ old('production_cost') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Prix produit fini") }} ({{ $currency }}/u)</label>
                        <input type="number" step="1" min="0" name="output_unit_price" value="{{ old('output_unit_price') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
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

                <div class="flex items-center justify-between pt-4 border-t border-slate-50">
                    <div class="text-left">
                        <p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Rendement calculé") }}</p>
                        <p class="text-2xl font-black text-green-600 italic" x-text="yield.toFixed(1) + '%'">0%</p>
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-industry mr-2 text-green-400"></i> {{ __("Enregistrer") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
