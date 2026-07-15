<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <x-page-header :title="__('Modifier la transformation')" :subtitle="$transformation->batch_number" icon="fa-industry" accent="green" :back="route('crop-transformations.show', $transformation)" />
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
            <x-flash />

            <form action="{{ route('crop-transformations.update', $transformation) }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Produit entrant *") }}</label>
                        <input type="text" name="input_product" value="{{ old('input_product', $transformation->input_product) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Produit fini *") }}</label>
                        <input type="text" name="output_product" value="{{ old('output_product', $transformation->output_product) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="transformation_type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected(old('transformation_type', $transformation->transformation_type) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle d'origine") }}</label>
                        <select name="crop_cycle_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($cycles as $c)
                                <option value="{{ $c->id }}" @selected(old('crop_cycle_id', $transformation->crop_cycle_id) == $c->id)>{{ $c->crop_name }} {{ $c->code ? "($c->code)" : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité entrée *") }}</label>
                            <input type="number" step="0.001" min="0.001" name="input_quantity" value="{{ old('input_quantity', $transformation->input_quantity) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        </div>
                        <div class="w-1/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                            <input type="text" name="input_unit" value="{{ old('input_unit', $transformation->input_unit ?? 'kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité sortie *") }}</label>
                            <input type="number" step="0.001" min="0" name="output_quantity" value="{{ old('output_quantity', $transformation->output_quantity) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic text-right">
                        </div>
                        <div class="w-1/3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                            <input type="text" name="output_unit" value="{{ old('output_unit', $transformation->output_unit ?? 'kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de production *") }}</label>
                        <input type="date" name="production_date" value="{{ old('production_date', $transformation->production_date?->format('Y-m-d')) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de péremption") }}</label>
                        <input type="date" name="expiry_date" value="{{ old('expiry_date', $transformation->expiry_date?->format('Y-m-d')) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût de production") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="production_cost" value="{{ old('production_cost', $transformation->production_cost) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Prix produit fini") }} ({{ $currency }}/u)</label>
                        <input type="number" step="1" min="0" name="output_unit_price" value="{{ old('output_unit_price', $transformation->output_unit_price) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Responsable") }}</label>
                        <select name="employee_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected(old('employee_id', $transformation->employee_id) == $emp->id)>{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes', $transformation->notes) }}</textarea>
                    </div>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Enregistrer les modifications") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
