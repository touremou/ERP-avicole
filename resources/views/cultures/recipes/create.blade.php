@php $currency = setting('general.currency', 'GNF'); @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <x-page-header :title="__('Nouvelle recette')" :subtitle="__('Standard d\'agro-transformation')" icon="fa-book" accent="green" :back="route('crop-recipes.index')" />
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

            <form action="{{ route('crop-recipes.store') }}" method="POST" x-data="{ items: [{ input_product: '', quantity: '', unit: 'kg' }] }" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de la recette *") }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" required placeholder="{{ __('Gari de manioc') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="transformation_type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($types as $key => $label)<option value="{{ $key }}" @selected(old('transformation_type') == $key)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <input type="text" name="code" value="{{ old('code') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic uppercase">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Produit fini *") }}</label>
                        <input type="text" name="output_product" value="{{ old('output_product') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité de sortie") }}</label>
                        <input type="text" name="output_unit" value="{{ old('output_unit', 'kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement attendu (%)") }}</label>
                        <input type="number" step="0.1" min="0" name="expected_yield_percent" value="{{ old('expected_yield_percent') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Conservation (jours)") }}</label>
                        <input type="number" min="0" name="shelf_life_days" value="{{ old('shelf_life_days') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût de transfo. réf.") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="estimated_cost" value="{{ old('estimated_cost') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                </div>

                {{-- INTRANTS (lignes dynamiques) --}}
                <div class="pt-4 border-t border-slate-50">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase text-slate-700 tracking-widest italic">{{ __("Intrants de la recette") }}</h3>
                        <button type="button" @click="items.push({ input_product: '', quantity: '', unit: 'kg' })" class="text-[9px] font-black uppercase text-green-600 italic"><i class="fa-solid fa-plus mr-1"></i>{{ __("Ajouter") }}</button>
                    </div>
                    <template x-for="(item, i) in items" :key="i">
                        <div class="grid grid-cols-12 gap-2 mb-2 items-center">
                            <input type="text" :name="`items[${i}][input_product]`" x-model="item.input_product" placeholder="{{ __('Matière première') }}" class="col-span-6 bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                            <input type="number" step="0.001" min="0" :name="`items[${i}][quantity]`" x-model="item.quantity" placeholder="{{ __('Qté') }}" class="col-span-3 bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                            <input type="text" :name="`items[${i}][unit]`" x-model="item.unit" class="col-span-2 bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-center">
                            <button type="button" @click="items.splice(i, 1)" class="col-span-1 text-rose-300 hover:text-rose-600"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    </template>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>

                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Créer la recette") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
