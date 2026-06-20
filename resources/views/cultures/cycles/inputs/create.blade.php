<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-lime-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-flask text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Ajouter un intrant") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ $cycle->crop_name }}</p>
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

            <form action="{{ route('crop-cycles.inputs.store', $cycle) }}" method="POST" x-data="{ q: 0, uc: 0, sync: false, get total() { return this.q * this.uc } }" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                        <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($inputTypes as $key => $label)
                                <option value="{{ $key }}" @selected(old('type') == $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom *") }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" required placeholder="{{ __('NPK 15-15-15…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité") }}</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.001" min="0" name="quantity" value="{{ old('quantity') }}" x-model.number="q" class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="unit" value="{{ old('unit', 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût unitaire (GNF)") }}</label>
                        <input type="number" step="1" min="0" name="unit_cost" value="{{ old('unit_cost') }}" x-model.number="uc" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">
                            {{ __("Coût total (GNF)") }} <span class="text-lime-600 ml-1" x-text="total ? '≈ '+total.toLocaleString() : ''"></span>
                        </label>
                        <input type="number" step="1" min="0" name="total_cost" value="{{ old('total_cost') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date d'application *") }}</label>
                        <input type="date" name="input_date" value="{{ old('input_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Fournisseur") }}</label>
                        <select name="provider_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" @selected(old('provider_id') == $provider->id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="bg-slate-50 rounded-[2rem] p-6 shadow-inner space-y-4">
                    <div class="flex items-center gap-3">
                        <input type="hidden" name="synced_to_stock" value="0">
                        <input type="checkbox" name="synced_to_stock" value="1" id="synced_to_stock" x-model="sync" class="rounded">
                        <label for="synced_to_stock" class="text-[9px] font-black text-slate-500 uppercase italic cursor-pointer">{{ __("Intégrer au stock (Intrants)") }}</label>
                    </div>
                    <div x-show="sync" x-cloak>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de l'article en stock") }}</label>
                        <input type="text" name="stock_item_name" value="{{ old('stock_item_name') }}" class="w-full bg-white border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-flask mr-2 text-lime-400"></i> {{ __("Enregistrer l'intrant") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
