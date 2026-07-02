<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <x-page-header :title="__('Modifier l\'intrant')" :subtitle="$cycle->crop_name" icon="fa-flask" accent="green" :back="route('crop-cycles.show', $cycle)" />
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

            <form action="{{ route('crop-cycles.inputs.update', [$cycle, $input]) }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($inputTypes as $key => $label)
                                <option value="{{ $key }}" @selected(old('type', $input->type) == $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom *") }}</label>
                        <input type="text" name="name" value="{{ old('name', $input->name) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité") }}</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.001" min="0" name="quantity" value="{{ old('quantity', $input->quantity) }}" class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="unit" value="{{ old('unit', $input->unit) }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût unitaire") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="unit_cost" value="{{ old('unit_cost', $input->unit_cost) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût total") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="total_cost" value="{{ old('total_cost', $input->total_cost) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date d'application *") }}</label>
                        <input type="date" name="input_date" value="{{ old('input_date', $input->input_date?->format('Y-m-d')) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Fournisseur") }}</label>
                        <select name="provider_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" @selected(old('provider_id', $input->provider_id) == $provider->id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes', $input->notes) }}</textarea>
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
