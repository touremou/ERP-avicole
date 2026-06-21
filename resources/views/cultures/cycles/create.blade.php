<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-seedling text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Nouveau Cycle de Culture") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Démarrage d'un semis") }}</p>
                </div>
            </div>
            <a href="{{ route('crop-cycles.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
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

            @if($plots->isEmpty())
                <div class="bg-amber-50 border border-amber-200 text-amber-700 p-6 rounded-[2rem] mb-8 text-[10px] font-black uppercase italic">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i> {{ __("Aucune parcelle disponible. Créez d'abord une parcelle.") }}
                    <a href="{{ route('plots.index') }}" class="underline ml-2">{{ __("Gérer les parcelles") }}</a>
                </div>
            @endif

            <form action="{{ route('crop-cycles.store') }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle *") }}</label>
                        <select name="plot_id" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Choisir --") }}</option>
                            @foreach($plots as $plot)
                                <option value="{{ $plot->id }}" @selected(old('plot_id') == $plot->id)>{{ $plot->name }} ({{ number_format($plot->area_ha, 2, ',', ' ') }} ha)</option>
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
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Culture *") }}</label>
                        <input type="text" name="crop_name" list="crop-species-list" value="{{ old('crop_name') }}" required placeholder="{{ __('Maïs, manioc, tomate…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="crop-species-list">
                            @foreach($species as $sp)<option value="{{ $sp->name }}">@endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété") }}</label>
                        <input type="text" name="variety" value="{{ old('variety') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Surface emblavée (ha) *") }}</label>
                        <input type="number" step="0.01" min="0" name="area_used_ha" value="{{ old('area_used_ha') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <input type="text" name="code" value="{{ old('code') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic uppercase">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de semis *") }}</label>
                        <input type="date" name="planting_date" value="{{ old('planting_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Récolte prévue") }}</label>
                        <input type="date" name="expected_harvest_date" value="{{ old('expected_harvest_date') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité semence") }}</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" min="0" name="seed_quantity" value="{{ old('seed_quantity') }}" class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="seed_unit" value="{{ old('seed_unit', 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rendement attendu (kg)") }}</label>
                        <input type="number" step="0.01" min="0" name="expected_yield_kg" value="{{ old('expected_yield_kg') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
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
</x-app-layout>
