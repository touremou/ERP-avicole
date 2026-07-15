<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Nouvelle Parcelle')" :subtitle="__('Production Végétale — Assolement')" icon="fa-map" accent="green" :back="route('plots.index')" />
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

            <form action="{{ route('plots.store') }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom *") }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <input type="text" name="code" value="{{ old('code') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic uppercase">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Superficie (ha) *") }}</label>
                        <input type="number" step="0.01" min="0" name="area_ha" value="{{ old('area_ha') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Statut") }}</label>
                        <select name="status" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" @selected(old('status', \App\Models\Plot::STATUS_DISPONIBLE) == $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type de sol") }}</label>
                        <input type="text" name="soil_type" value="{{ old('soil_type') }}" list="soil-types" placeholder="{{ __('Argileux, sableux…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        <datalist id="soil-types">
                            @foreach(\App\Models\CropSpecies::SOIL_TYPES as $st)
                                <option value="{{ $st }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    @php
                        $currentFarm = \App\Models\Farm::find(session('current_farm_id'));
                        $autoZone = \App\Models\Plot::zoneFromRegion($currentFarm?->region);
                    @endphp
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Zone agro-écologique") }}</label>
                        <select name="agro_zone" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Auto (selon la ferme) --") }}</option>
                            @foreach(\App\Models\CropSpecies::ZONES as $zKey => $zLabel)
                                <option value="{{ $zKey }}" @selected(old('agro_zone', $autoZone) == $zKey)>{{ $zLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Irrigation") }}</label>
                        <input type="text" name="irrigation_type" value="{{ old('irrigation_type') }}" placeholder="{{ __('Pluvial, goutte-à-goutte…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Localisation") }}</label>
                        <input type="text" name="location" value="{{ old('location') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Enregistrer la parcelle") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
