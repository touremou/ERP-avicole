<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-sky-500 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-cloud-sun-rain text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Météo & Pluviométrie") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Relevés climatiques") }}</p>
                </div>
            </div>
            @can('cultures.C')
            <div class="flex items-center gap-3">
                <form method="POST" action="{{ route('weather.fetch') }}">
                    @csrf
                    <button type="submit" title="{{ __('Récupère automatiquement la météo du jour pour la ferme (Open-Meteo)') }}"
                            class="bg-sky-500 text-white px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-sky-600 transition-all shadow-xl italic flex items-center gap-2">
                        <i class="fa-solid fa-cloud-arrow-down"></i> {{ __("Récupérer la météo") }}
                    </button>
                </form>
                <button onclick="document.getElementById('weather-modal').classList.remove('hidden')" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-sky-600 transition-all shadow-2xl italic flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouveau relevé") }}
                </button>
            </div>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">
            <x-flash />


            {{-- FILTRES --}}
            <form method="GET" class="flex flex-wrap items-end gap-3 bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm">
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Mois") }}</label>
                    <input type="month" name="month" value="{{ $month }}" class="bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle") }}</label>
                    <select name="plot_id" class="bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] cursor-pointer">
                        <option value="">{{ __("Toutes") }}</option>
                        @foreach($plots as $p)<option value="{{ $p->id }}" @selected($plotId == $p->id)>{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black uppercase italic tracking-widest text-[9px] shadow-lg hover:bg-sky-600 transition-all">{{ __("Afficher") }}</button>
            </form>

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-sky-500 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-sky-100 uppercase tracking-widest italic mb-2">{{ __("Pluie totale") }}</p>
                    <p class="text-3xl font-black leading-none">{{ number_format($stats['rainfall_total'], 0, ',', ' ') }} <small class="text-[10px] opacity-60">mm</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-rose-500 uppercase tracking-widest italic mb-2">{{ __("T° max moy.") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['t_max_avg'] }}<small class="text-[10px] opacity-40">°C</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-sky-500 uppercase tracking-widest italic mb-2">{{ __("Pluie moy./relevé") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['rainfall_avg'] }} <small class="text-[10px] opacity-40">mm</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Relevés") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['count'] }}</p>
                </div>
            </div>

            {{-- PRÉVISIONS & ALERTES PRÉDICTIVES (Open-Meteo) --}}
            @if(!empty($forecast))
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm p-6 md:p-8">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-[11px] font-black text-slate-700 uppercase tracking-widest italic flex items-center gap-2">
                        <i class="fa-solid fa-satellite-dish text-sky-500"></i> {{ __("Prévisions (J+1 → J+3)") }}
                    </h3>
                    <span class="text-[8px] font-black uppercase tracking-widest text-slate-300 italic">{{ __("Source : Open-Meteo") }}</span>
                </div>

                {{-- Alertes prédictives --}}
                @if(!empty($forecastAlerts))
                    <div class="space-y-2 mb-6">
                        @foreach($forecastAlerts as $a)
                            <div class="flex items-start gap-3 p-4 rounded-2xl {{ $a['severity'] === 'critique' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700' }}">
                                <i class="fa-solid {{ $a['icon'] }} mt-0.5"></i>
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-wide leading-none">{{ $a['title'] }}</p>
                                    <p class="text-[10px] font-bold mt-1 normal-case leading-snug">{{ $a['message'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex items-center gap-3 p-4 rounded-2xl bg-emerald-50 text-emerald-700 mb-6">
                        <i class="fa-solid fa-circle-check"></i>
                        <p class="text-[10px] font-black uppercase tracking-wide">{{ __("Aucun risque météo majeur annoncé") }}</p>
                    </div>
                @endif

                {{-- Bandeau jours --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach($forecast as $day)
                        @php $heavyRain = ($day['rain_mm'] ?? 0) >= 20; @endphp
                        <div class="rounded-2xl p-5 border {{ $heavyRain ? 'border-sky-200 bg-sky-50' : 'border-slate-100 bg-slate-50' }}">
                            <p class="text-[8px] font-black uppercase tracking-widest text-slate-400 italic mb-2">
                                {{ $day['horizon'] == 1 ? __('Demain') : ($day['horizon'] == 2 ? __('Après-demain') : 'J+'.$day['horizon']) }}
                                · {{ \Carbon\Carbon::parse($day['date'])->isoFormat('ddd D MMM') }}
                            </p>
                            <div class="flex items-end justify-between">
                                <div>
                                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $day['t_max'] !== null ? round($day['t_max']).'°' : '—' }}<span class="text-sm text-slate-400">/{{ $day['t_min'] !== null ? round($day['t_min']).'°' : '—' }}</span></p>
                                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">{{ __("Temp.") }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-black {{ $heavyRain ? 'text-sky-600' : 'text-slate-600' }} leading-none">
                                        <i class="fa-solid fa-droplet text-[10px]"></i> {{ $day['rain_mm'] !== null ? $day['rain_mm'] : 0 }} mm
                                    </p>
                                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">
                                        @if($day['rain_prob'] !== null){{ $day['rain_prob'] }}% · @endif{{ $day['wind_kmh'] !== null ? round($day['wind_kmh']).' km/h' : '' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="p-4">{{ __("Date") }}</th>
                                <th class="p-4">{{ __("Parcelle") }}</th>
                                <th class="p-4 text-right">{{ __("T° min") }}</th>
                                <th class="p-4 text-right">{{ __("T° max") }}</th>
                                <th class="p-4 text-right">{{ __("Pluie") }}</th>
                                <th class="p-4 text-right">{{ __("Humidité") }}</th>
                                <th class="p-4 text-right">{{ __("Vent") }}</th>
                                <th class="p-4 text-right">{{ __("Soleil") }}</th>
                                @can('cultures.S')<th class="p-4"></th>@endcan
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($readings as $r)
                                <tr class="border-b border-slate-50 text-[11px] text-slate-700">
                                    <td class="p-4 font-black">{{ $r->reading_date?->format('d/m/Y') }}</td>
                                    <td class="p-4 text-slate-400">{{ $r->plot?->name ?? '—' }}</td>
                                    <td class="p-4 text-right text-sky-600">{{ $r->temperature_min !== null ? number_format($r->temperature_min, 1, ',', ' ').'°' : '—' }}</td>
                                    <td class="p-4 text-right text-rose-600">{{ $r->temperature_max !== null ? number_format($r->temperature_max, 1, ',', ' ').'°' : '—' }}</td>
                                    <td class="p-4 text-right font-black text-sky-700">{{ $r->rainfall_mm > 0 ? number_format($r->rainfall_mm, 1, ',', ' ').' mm' : '—' }}</td>
                                    <td class="p-4 text-right">{{ $r->humidity_pct !== null ? round($r->humidity_pct).'%' : '—' }}</td>
                                    <td class="p-4 text-right">{{ $r->wind_kmh !== null ? round($r->wind_kmh).' km/h' : '—' }}</td>
                                    <td class="p-4 text-right">{{ $r->sunshine_h !== null ? number_format($r->sunshine_h, 1, ',', ' ').'h' : '—' }}</td>
                                    @can('cultures.S')
                                    <td class="p-4 text-right">
                                        <form action="{{ route('weather.destroy', $r) }}" method="POST" onsubmit="return confirm('Supprimer ce relevé ?')">
                                            @csrf @method('DELETE')
                                            <button class="text-rose-300 hover:text-rose-600"><i class="fa-solid fa-trash text-xs"></i></button>
                                        </form>
                                    </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr><td colspan="9" class="p-16 text-center text-slate-300 text-[10px] font-black uppercase italic">{{ __("Aucun relevé pour ce mois") }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL NOUVEAU RELEVÉ --}}
    @can('cultures.C')
    <div id="weather-modal" class="hidden fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] p-8 max-w-2xl w-full shadow-2xl italic">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-[12px] font-black uppercase text-slate-800 tracking-widest italic"><i class="fa-solid fa-cloud-sun-rain text-sky-500 mr-2"></i>{{ __("Nouveau relevé météo") }}</h3>
                <button onclick="document.getElementById('weather-modal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <form action="{{ route('weather.store') }}" method="POST" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @csrf
                <div class="col-span-2 md:col-span-1">
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date *") }}</label>
                    <input type="date" name="reading_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-2">
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle") }}</label>
                    <select name="plot_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] cursor-pointer">
                        <option value="">{{ __("-- Ferme entière --") }}</option>
                        @foreach($plots as $p)<option value="{{ $p->id }}" @selected($plotId == $p->id)>{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("T° min (°C)") }}</label>
                    <input type="number" step="0.1" name="temperature_min" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("T° max (°C)") }}</label>
                    <input type="number" step="0.1" name="temperature_max" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Humidité (%)") }}</label>
                    <input type="number" step="1" min="0" max="100" name="humidity_pct" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Pluie (mm)") }}</label>
                    <input type="number" step="0.1" min="0" name="rainfall_mm" value="0" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Vent (km/h)") }}</label>
                    <input type="number" step="0.1" min="0" name="wind_kmh" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Soleil (h)") }}</label>
                    <input type="number" step="0.5" min="0" max="24" name="sunshine_h" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <input type="text" name="notes" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-bold text-slate-700 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-2 md:col-span-3 flex justify-end pt-2">
                    <button type="submit" class="bg-slate-900 text-white px-10 py-4 rounded-[2rem] font-black uppercase italic tracking-widest text-[10px] shadow-xl hover:bg-sky-600 transition-all"><i class="fa-solid fa-check mr-2 text-sky-400"></i>{{ __("Enregistrer") }}</button>
                </div>
            </form>
        </div>
    </div>
    @endcan
</x-app-layout>
