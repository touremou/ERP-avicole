<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Modifier le relevé')" :subtitle="$reading->reading_date?->format('d/m/Y')" icon="fa-cloud-sun-rain" accent="sky" :back="route('cultures.dashboard', ['tab' => 'meteo'])" />
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

            <form action="{{ route('weather.update', $reading) }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date du relevé *") }}</label>
                        <input type="date" name="reading_date" value="{{ old('reading_date', $reading->reading_date?->format('Y-m-d')) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle") }}</label>
                        <select name="plot_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Ferme entière --") }}</option>
                            @foreach($plots as $plot)
                                <option value="{{ $plot->id }}" @selected(old('plot_id', $reading->plot_id) == $plot->id)>{{ $plot->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Température min (°C)") }}</label>
                        <input type="number" step="0.1" name="temperature_min" value="{{ old('temperature_min', $reading->temperature_min) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Température max (°C)") }}</label>
                        <input type="number" step="0.1" name="temperature_max" value="{{ old('temperature_max', $reading->temperature_max) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Humidité (%)") }}</label>
                        <input type="number" min="0" max="100" name="humidity_pct" value="{{ old('humidity_pct', $reading->humidity_pct) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Pluviométrie (mm)") }}</label>
                        <input type="number" step="0.1" min="0" name="rainfall_mm" value="{{ old('rainfall_mm', $reading->rainfall_mm) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Vent (km/h)") }}</label>
                        <input type="number" step="0.1" min="0" name="wind_kmh" value="{{ old('wind_kmh', $reading->wind_kmh) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Ensoleillement (h)") }}</label>
                        <input type="number" step="0.5" min="0" max="24" name="sunshine_h" value="{{ old('sunshine_h', $reading->sunshine_h) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes', $reading->notes) }}</textarea>
                    </div>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-sky-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-sky-300"></i> {{ __("Enregistrer les modifications") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
