<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-cyan-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-bolt text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Eau & Énergie</h2>
                    <p class="text-[10px] font-black text-cyan-600 uppercase tracking-[0.2em] mt-2 italic">
                        Suivi des ressources — Période : {{ $period }} jours
                    </p>
                </div>
            </div>
            <form method="GET" class="flex gap-2 items-center">
                <select name="period" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="7" {{ $period == 7 ? 'selected' : '' }}>7 jours</option>
                    <option value="30" {{ $period == 30 ? 'selected' : '' }}>30 jours</option>
                    <option value="90" {{ $period == 90 ? 'selected' : '' }}>90 jours</option>
                </select>
            </form>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if(session('success'))
                <div class="mb-8 p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- ALERTES CRITIQUES --}}
            @if(count($data['alerts']) > 0)
            <div class="mb-8 space-y-3">
                @foreach($data['alerts'] as $alert)
                <div @class([
                    'p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-widest flex items-center gap-4',
                    'bg-red-500 text-white shadow-xl animate-pulse' => $alert['severity'] === 'critique',
                    'bg-amber-500 text-white shadow-lg' => $alert['severity'] === 'attention',
                ])>
                    <i class="fa-solid {{ $alert['icon'] }} text-lg"></i>
                    <div>
                        <p class="text-xs font-black">{{ $alert['title'] }}</p>
                        <p class="text-[9px] opacity-80 normal-case mt-0.5">{{ $alert['message'] }}</p>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- ═══════════ SECTION EAU ═══════════ --}}
            <div class="mb-10">
                <h3 class="text-[11px] font-black uppercase text-cyan-600 tracking-widest mb-6 flex items-center gap-3">
                    <div class="w-8 h-8 bg-cyan-500 rounded-lg flex items-center justify-center text-white"><i class="fa-solid fa-droplet text-sm"></i></div>
                    Eau
                </h3>

                {{-- KPI EAU --}}
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Conso période</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['water']['total_consumed']) }}</p>
                        <p class="text-[8px] text-slate-400">litres</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Moyenne / jour</p>
                        <p class="text-xl font-black text-cyan-600">{{ number_format($data['water']['daily_avg']) }}</p>
                        <p class="text-[8px] text-slate-400">litres</p>
                    </div>
                    <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                        'bg-red-50 border-red-200' => $data['water']['per_bird_per_day'] < 0.15 || $data['water']['per_bird_per_day'] > 0.5,
                        'bg-white border-slate-100' => $data['water']['per_bird_per_day'] >= 0.15 && $data['water']['per_bird_per_day'] <= 0.5,
                    ])>
                        <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $data['water']['per_bird_per_day'] < 0.15 ? 'text-red-500' : 'text-slate-400' }}">L / volaille / jour</p>
                        <p class="text-xl font-black {{ $data['water']['per_bird_per_day'] < 0.15 ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($data['water']['per_bird_per_day'], 3) }}</p>
                        <p class="text-[8px] text-slate-400">norme : 0.2-0.4L</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Coût / 1000 têtes</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['water']['cost_per_1000']) }}</p>
                        <p class="text-[8px] text-slate-400">GNF</p>
                    </div>
                    <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                        'bg-red-50 border-red-200' => $data['water']['ph_status'] === 'hors_norme',
                        'bg-emerald-50 border-emerald-200' => $data['water']['ph_status'] === 'optimal',
                        'bg-white border-slate-100' => !in_array($data['water']['ph_status'], ['hors_norme', 'optimal']),
                    ])>
                        <p class="text-[8px] font-black uppercase tracking-widest mb-1 text-slate-400">Dernier pH</p>
                        <p class="text-xl font-black {{ $data['water']['ph_status'] === 'hors_norme' ? 'text-red-600' : 'text-emerald-600' }}">
                            {{ $data['water']['last_ph'] ?? '—' }}
                        </p>
                        <p class="text-[8px] text-slate-400">norme : 6.5-8.5</p>
                    </div>
                </div>

                {{-- JAUGES CITERNES --}}
                @if($data['water']['critical_sources']->count() > 0 || $waterSources->where('type', 'citerne')->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    @foreach($waterSources->where('type', 'citerne') as $citerne)
                    <div @class(['p-5 rounded-[2rem] border shadow-sm',
                        'bg-red-50 border-red-200' => ($citerne->current_level_percent ?? 0) < 30,
                        'bg-amber-50 border-amber-200' => ($citerne->current_level_percent ?? 0) >= 30 && ($citerne->current_level_percent ?? 0) < 50,
                        'bg-white border-slate-100' => ($citerne->current_level_percent ?? 0) >= 50,
                    ])>
                        <div class="flex justify-between items-center mb-3">
                            <p class="text-[9px] font-black text-slate-700 uppercase">{{ $citerne->name }}</p>
                            <span class="text-sm font-black {{ ($citerne->current_level_percent ?? 0) < 30 ? 'text-red-600' : 'text-slate-900' }}">
                                {{ round($citerne->current_level_percent ?? 0) }}%
                            </span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-4 overflow-hidden">
                            @php $pct = $citerne->current_level_percent ?? 0; @endphp
                            <div @class(['h-4 rounded-full transition-all',
                                'bg-red-500' => $pct < 30,
                                'bg-amber-500' => $pct >= 30 && $pct < 50,
                                'bg-cyan-500' => $pct >= 50,
                            ]) style="width: {{ $pct }}%"></div>
                        </div>
                        <p class="text-[8px] text-slate-400 mt-1">{{ number_format($citerne->current_level_liters ?? 0) }} / {{ number_format($citerne->capacity_liters ?? 0) }} L</p>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- ═══════════ SECTION ÉNERGIE ═══════════ --}}
            <div class="mb-10">
                <h3 class="text-[11px] font-black uppercase text-amber-600 tracking-widest mb-6 flex items-center gap-3">
                    <div class="w-8 h-8 bg-amber-500 rounded-lg flex items-center justify-center text-white"><i class="fa-solid fa-bolt text-sm"></i></div>
                    Énergie
                </h3>

                {{-- KPI ÉNERGIE --}}
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Coût total</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['energy']['total_cost']) }}</p>
                        <p class="text-[8px] text-slate-400">GNF</p>
                    </div>
                    <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                        'bg-red-50 border-red-200' => $data['energy']['edg_ratio'] < 30,
                        'bg-white border-slate-100' => $data['energy']['edg_ratio'] >= 30,
                    ])>
                        <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $data['energy']['edg_ratio'] < 30 ? 'text-red-500' : 'text-slate-400' }}">Ratio EDG</p>
                        <p class="text-xl font-black {{ $data['energy']['edg_ratio'] < 30 ? 'text-red-600' : 'text-emerald-600' }}">{{ $data['energy']['edg_ratio'] }}%</p>
                        <p class="text-[8px] text-slate-400">> 50% = économique</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Gasoil consommé</p>
                        <p class="text-xl font-black text-amber-600">{{ number_format($data['energy']['total_fuel_liters']) }}</p>
                        <p class="text-[8px] text-slate-400">litres</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-1">Coupures EDG / jour</p>
                        <p class="text-xl font-black text-slate-900">{{ $data['energy']['daily_outage_avg'] }}h</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Heures groupe</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['energy']['groupe_hours']) }}</p>
                        <p class="text-[8px] text-slate-400">heures</p>
                    </div>
                </div>

                {{-- GROUPES ÉLECTROGÈNES --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    @foreach($data['fuel']['groupes'] as $groupe)
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-sm font-black text-slate-900 uppercase italic">{{ $groupe->name }}</p>
                                <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ $groupe->brand }} {{ $groupe->model }} — {{ $groupe->capacity_kva }} kVA</p>
                            </div>
                            <span @class([
                                'text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                'bg-emerald-50 text-emerald-600' => $groupe->status === 'operationnel',
                                'bg-amber-50 text-amber-600' => $groupe->status === 'maintenance',
                                'bg-red-50 text-red-600' => $groupe->status === 'panne',
                            ])>{{ $groupe->status }}</span>
                        </div>

                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <div class="text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">Gasoil</p>
                                <p class="text-lg font-black {{ $groupe->is_fuel_low ? 'text-red-600' : 'text-slate-900' }}">
                                    {{ number_format($groupe->current_fuel_level ?? 0) }}L
                                </p>
                            </div>
                            <div class="text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">Autonomie</p>
                                <p class="text-lg font-black {{ ($groupe->fuel_autonomy_days ?? 99) <= 3 ? 'text-red-600 animate-pulse' : 'text-slate-900' }}">
                                    {{ $groupe->fuel_autonomy_days ?? '—' }}j
                                </p>
                            </div>
                            <div class="text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">Avant vidange</p>
                                <p class="text-lg font-black {{ $groupe->needs_maintenance ? 'text-amber-600' : 'text-slate-900' }}">
                                    {{ round($groupe->hours_before_maintenance) }}h
                                </p>
                            </div>
                        </div>

                        {{-- Jauge gasoil --}}
                        @php $fuelPct = ($groupe->fuel_tank_capacity > 0) ? min(100, ($groupe->current_fuel_level / $groupe->fuel_tank_capacity) * 100) : 0; @endphp
                        <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                            <div @class(['h-3 rounded-full transition-all', $fuelPct < 20 ? 'bg-red-500' : ($fuelPct < 40 ? 'bg-amber-500' : 'bg-emerald-500')]) style="width: {{ $fuelPct }}%"></div>
                        </div>
                        <p class="text-[8px] text-slate-400 mt-1">Cuve : {{ round($fuelPct) }}% — {{ number_format($groupe->current_fuel_level ?? 0) }} / {{ number_format($groupe->fuel_tank_capacity ?? 0) }} L</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- ═══════════ FORMULAIRES RAPIDES ═══════════ --}}
            @can('ressources.C')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Relevé eau --}}
                <div class="bg-cyan-50 p-6 rounded-[2.5rem] border border-cyan-200">
                    <h3 class="text-[10px] font-black text-cyan-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-droplet"></i> Nouveau relevé eau
                    </h3>
                    <form method="POST" action="{{ route('utilities.water.readings.store') }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <select name="water_source_id" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                <option value="">Source...</option>
                                @foreach($waterSources as $ws)
                                    <option value="{{ $ws->id }}">{{ $ws->name }}</option>
                                @endforeach
                            </select>
                            <input type="date" name="reading_date" value="{{ now()->toDateString() }}" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <input type="number" name="volume_consumed_liters" step="0.1" min="0" required placeholder="Conso (L)" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="volume_added_liters" step="0.1" min="0" placeholder="Ajout citerne (L)" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <input type="number" name="quality_ph" step="0.1" min="0" max="14" placeholder="pH" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="chlorine_level" step="0.01" min="0" placeholder="Chlore mg/L" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="cost" step="100" min="0" placeholder="Coût GNF" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <button type="submit" class="w-full bg-cyan-500 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-cyan-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-check mr-1"></i> Enregistrer
                        </button>
                    </form>
                </div>

                {{-- Relevé énergie --}}
                <div class="bg-amber-50 p-6 rounded-[2.5rem] border border-amber-200">
                    <h3 class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-bolt"></i> Nouveau relevé énergie
                    </h3>
                    <form method="POST" action="{{ route('utilities.energy.readings.store') }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <select name="energy_source_id" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                <option value="">Source...</option>
                                @foreach($energySources as $es)
                                    <option value="{{ $es->id }}">{{ $es->name }} ({{ $es->type_label }})</option>
                                @endforeach
                            </select>
                            <input type="date" name="reading_date" value="{{ now()->toDateString() }}" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <input type="number" name="hours_run" step="0.5" min="0" max="24" required placeholder="Heures" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="fuel_consumed_liters" step="0.1" min="0" placeholder="Gasoil (L)" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="outage_hours" step="0.5" min="0" max="24" placeholder="Coupures (h)" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <input type="number" name="cost" step="100" min="0" placeholder="Coût journalier GNF" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        <button type="submit" class="w-full bg-amber-500 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-check mr-1"></i> Enregistrer
                        </button>
                    </form>
                </div>
            </div>
            @endcan

            {{-- LIENS RAPIDES --}}
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('utilities.water.sources') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-cyan-50 hover:text-cyan-600 transition-all no-underline">
                    <i class="fa-solid fa-faucet-drip mr-1"></i> Gérer les sources d'eau
                </a>
                <a href="{{ route('utilities.energy.sources') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-amber-50 hover:text-amber-600 transition-all no-underline">
                    <i class="fa-solid fa-plug mr-1"></i> Gérer les sources d'énergie
                </a>
                <a href="{{ route('utilities.fuel.index') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-orange-50 hover:text-orange-600 transition-all no-underline">
                    <i class="fa-solid fa-gas-pump mr-1"></i> Achats carburant
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
