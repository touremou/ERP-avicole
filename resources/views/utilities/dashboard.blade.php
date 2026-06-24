<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-cyan-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-bolt text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Eau & Énergie") }}</h2>
                    <p class="text-[10px] font-black text-cyan-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ __("Suivi des ressources — Période :") }} {{ $period }} {{ __("jours") }}
                    </p>
                </div>
            </div>
            <form method="GET" class="flex gap-2 items-center">
                <select name="period" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="7" {{ $period == 7 ? 'selected' : '' }}>{{ __("7 jours") }}</option>
                    <option value="30" {{ $period == 30 ? 'selected' : '' }}>{{ __("30 jours") }}</option>
                    <option value="90" {{ $period == 90 ? 'selected' : '' }}>{{ __("90 jours") }}</option>
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

            {{-- ONBOARDING : aucune source configurée → on explique la valeur --}}
            @if($waterSources->isEmpty() && $energySources->isEmpty())
            <div class="mb-8 bg-gradient-to-br from-cyan-50 to-amber-50 border border-cyan-100 rounded-[2.5rem] p-8 not-italic">
                <h3 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none mb-3">
                    <i class="fa-solid fa-bolt text-cyan-500 mr-2"></i> {{ __("Pilotez l'eau, l'énergie et le gasoil") }}
                </h3>
                <p class="text-[11px] font-bold text-slate-500 normal-case mb-5 max-w-2xl">
                    {{ __("Ce module sécurise deux enjeux vitaux de la ferme :") }}
                </p>
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-white/70 rounded-2xl p-4">
                        <p class="text-[10px] font-black text-cyan-600 uppercase tracking-widest mb-1"><i class="fa-solid fa-shield-heart mr-1"></i> {{ __("Continuité de service") }}</p>
                        <p class="text-[10px] font-bold text-slate-500 normal-case">{{ __("Autonomie gasoil, niveaux de citerne et maintenance des groupes : éviter la coupure qui met un lot en danger.") }}</p>
                    </div>
                    <div class="bg-white/70 rounded-2xl p-4">
                        <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1"><i class="fa-solid fa-coins mr-1"></i> {{ __("Maîtrise des coûts") }}</p>
                        <p class="text-[10px] font-bold text-slate-500 normal-case">{{ __("Coût eau/énergie par sujet et par bâtiment, imputé automatiquement à la marge de chaque lot.") }}</p>
                    </div>
                </div>
                @can('ressources.C')
                <div class="flex flex-wrap gap-3 not-italic">
                    <a href="{{ route('utilities.water.sources') }}" class="bg-cyan-500 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-cyan-600 transition-all no-underline"><i class="fa-solid fa-droplet mr-1"></i> {{ __("1. Ajouter une source d'eau") }}</a>
                    <a href="{{ route('utilities.energy.sources') }}" class="bg-amber-500 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-600 transition-all no-underline"><i class="fa-solid fa-bolt mr-1"></i> {{ __("2. Ajouter une source d'énergie") }}</a>
                </div>
                <p class="text-[9px] font-bold text-slate-400 normal-case mt-4">{{ __("Ensuite, les tâches quotidiennes « Relevé eau » et « Relevé énergie » guideront la saisie — elles se cochent toutes seules dès qu'un relevé est enregistré.") }}</p>
                @endcan
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
                    {{ __("Eau") }}
                </h3>

                {{-- KPI EAU --}}
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Conso période") }}</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['water']['total_consumed']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ __("litres") }}</p>
                        @if(($data['water']['from_daily_checks'] ?? 0) > 0)
                            <p class="text-[7px] font-black text-cyan-500 uppercase tracking-wide mt-1" title="{{ __('Consommation saisie aux pointages journaliers (pas de double saisie)') }}">
                                {{ __("dont :n L pointages", ['n' => number_format($data['water']['from_daily_checks'])]) }}
                            </p>
                        @endif
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Moyenne / jour") }}</p>
                        <p class="text-xl font-black text-cyan-600">{{ number_format($data['water']['daily_avg']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ __("litres") }}</p>
                    </div>
                    <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                        'bg-red-50 border-red-200' => $data['water']['per_bird_per_day'] < 0.15 || $data['water']['per_bird_per_day'] > 0.5,
                        'bg-white border-slate-100' => $data['water']['per_bird_per_day'] >= 0.15 && $data['water']['per_bird_per_day'] <= 0.5,
                    ])>
                        <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $data['water']['per_bird_per_day'] < 0.15 ? 'text-red-500' : 'text-slate-400' }}">{{ __("L / sujet / jour") }}</p>
                        <p class="text-xl font-black {{ $data['water']['per_bird_per_day'] < 0.15 ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($data['water']['per_bird_per_day'], 3) }}</p>
                        <p class="text-[8px] text-slate-400">{{ __("norme : 0.2-0.4L") }}</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Coût / 1000 têtes") }}</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['water']['cost_per_1000']) }}</p>
                        <p class="text-[8px] text-slate-400">GNF</p>
                    </div>
                    <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                        'bg-red-50 border-red-200' => $data['water']['ph_status'] === 'hors_norme',
                        'bg-emerald-50 border-emerald-200' => $data['water']['ph_status'] === 'optimal',
                        'bg-white border-slate-100' => !in_array($data['water']['ph_status'], ['hors_norme', 'optimal']),
                    ])>
                        <p class="text-[8px] font-black uppercase tracking-widest mb-1 text-slate-400">{{ __("Dernier pH") }}</p>
                        <p class="text-xl font-black {{ $data['water']['ph_status'] === 'hors_norme' ? 'text-red-600' : 'text-emerald-600' }}">
                            {{ $data['water']['last_ph'] ?? '—' }}
                        </p>
                        <p class="text-[8px] text-slate-400">{{ __("norme : 6.5-8.5") }}</p>
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
                    {{ __("Énergie") }}
                </h3>

                {{-- KPI ÉNERGIE --}}
                <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Coût total") }}</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['energy']['total_cost']) }}</p>
                        <p class="text-[8px] text-slate-400">GNF</p>
                    </div>
                    @if(setting('energie.kwh_price_edg', 0) > 0)
                    <div class="bg-emerald-50 p-5 rounded-[2rem] border border-emerald-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Valeur produite (éq. EDG)") }}</p>
                        <p class="text-xl font-black text-emerald-600">{{ number_format($data['energy']['edg_value']) }}</p>
                        <p class="text-[8px] text-emerald-400">{{ number_format($data['energy']['total_kwh']) }} kWh × {{ number_format(setting('energie.kwh_price_edg')) }}</p>
                    </div>
                    @endif
                    <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                        'bg-red-50 border-red-200' => $data['energy']['edg_ratio'] < 30,
                        'bg-white border-slate-100' => $data['energy']['edg_ratio'] >= 30,
                    ])>
                        <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $data['energy']['edg_ratio'] < 30 ? 'text-red-500' : 'text-slate-400' }}">{{ __("Ratio EDG") }}</p>
                        <p class="text-xl font-black {{ $data['energy']['edg_ratio'] < 30 ? 'text-red-600' : 'text-emerald-600' }}">{{ $data['energy']['edg_ratio'] }}%</p>
                        <p class="text-[8px] text-slate-400">{{ __("> 50% = économique") }}</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Gasoil consommé") }}</p>
                        <p class="text-xl font-black text-amber-600">{{ number_format($data['energy']['total_fuel_liters']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ __("litres") }}</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-1">{{ __("Coupures EDG / jour") }}</p>
                        <p class="text-xl font-black text-slate-900">{{ $data['energy']['daily_outage_avg'] }}h</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Heures groupe") }}</p>
                        <p class="text-xl font-black text-slate-900">{{ number_format($data['energy']['groupe_hours']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ __("heures") }}</p>
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
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Gasoil") }}</p>
                                <p class="text-lg font-black {{ $groupe->is_fuel_low ? 'text-red-600' : 'text-slate-900' }}">
                                    {{ number_format($groupe->current_fuel_level ?? 0) }}L
                                </p>
                            </div>
                            <div class="text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Autonomie") }}</p>
                                <p class="text-lg font-black {{ $groupe->is_fuel_low ? 'text-red-600 animate-pulse' : 'text-slate-900' }}">
                                    {{ $groupe->fuel_autonomy_days ?? '—' }}j
                                    @if($groupe->fuel_autonomy_hours !== null)
                                        <span class="text-[9px] font-black opacity-50">({{ $groupe->fuel_autonomy_hours }}h)</span>
                                    @endif
                                </p>
                                <p class="text-[7px] text-slate-300 font-black uppercase tracking-widest mt-0.5">{{ __("Seuil :") }} {{ setting('energie.autonomy_alert_hours', 24) }}h</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Avant vidange") }}</p>
                                <p class="text-lg font-black {{ $groupe->needs_maintenance ? 'text-amber-600' : 'text-slate-900' }}">
                                    {{ round($groupe->hours_before_maintenance) }}h
                                </p>
                            </div>
                        </div>

                        {{-- Jauge carburant --}}
                        @php $fuelPct = ($groupe->fuel_tank_capacity > 0) ? min(100, ($groupe->current_fuel_level / $groupe->fuel_tank_capacity) * 100) : 0; @endphp
                        <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                            <div @class(['h-3 rounded-full transition-all', $fuelPct < 20 ? 'bg-red-500' : ($fuelPct < 40 ? 'bg-amber-500' : 'bg-emerald-500')]) style="width: {{ $fuelPct }}%"></div>
                        </div>
                        <p class="text-[8px] text-slate-400 mt-1">{{ __("Cuve :") }} {{ round($fuelPct) }}% — {{ number_format($groupe->current_fuel_level ?? 0) }} / {{ number_format($groupe->fuel_tank_capacity ?? 0) }} L</p>
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
                        <i class="fa-solid fa-droplet"></i> {{ __("Nouveau relevé eau") }}
                    </h3>
                    <form method="POST" action="{{ route('utilities.water.readings.store') }}" class="space-y-3" data-prefill-form="water">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <select name="water_source_id" required data-prefill-source class="bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                <option value="">{{ __("Source...") }}</option>
                                @foreach($waterSources as $ws)
                                    <option value="{{ $ws->id }}">{{ $ws->name }}</option>
                                @endforeach
                            </select>
                            <input type="date" name="reading_date" value="{{ now()->toDateString() }}" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        @if($buildings->count())
                        <select name="building_id" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                            <option value="">{{ __("Bâtiment consommateur (optionnel)") }}</option>
                            @foreach($buildings as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                        @endif
                        <div class="grid grid-cols-2 gap-3">
                            <input type="number" name="volume_consumed_liters" step="0.1" min="0" required placeholder="{{ __('Conso (L)') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="volume_added_liters" step="0.1" min="0" placeholder="{{ __('Ajout citerne (L)') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <input type="number" name="quality_ph" step="0.1" min="0" max="14" placeholder="{{ __('pH') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="chlorine_level" step="0.01" min="0" placeholder="{{ __('Chlore mg/L') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="cost" step="100" min="0" placeholder="{{ __('Coût GNF') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <button type="submit" class="w-full bg-cyan-500 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-cyan-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-check mr-1"></i> {{ __("Enregistrer") }}
                        </button>
                    </form>
                </div>

                {{-- Relevé énergie --}}
                <div class="bg-amber-50 p-6 rounded-[2.5rem] border border-amber-200">
                    <h3 class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1 flex items-center gap-2">
                        <i class="fa-solid fa-bolt"></i> {{ __("Nouveau relevé énergie") }}
                    </h3>
                    <p class="text-[9px] font-bold text-amber-500/80 normal-case mb-4">{{ __("Saisissez simplement les heures : le gasoil et le coût sont estimés automatiquement.") }}</p>
                    <form method="POST" action="{{ route('utilities.energy.readings.store') }}" class="space-y-3" data-prefill-form="energy">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <select name="energy_source_id" required data-prefill-source class="bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                <option value="">{{ __("Source...") }}</option>
                                @foreach($energySources as $es)
                                    <option value="{{ $es->id }}">{{ $es->name }} ({{ $es->type_label }})</option>
                                @endforeach
                            </select>
                            <input type="date" name="reading_date" value="{{ now()->toDateString() }}" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        @if($buildings->count())
                        <select name="building_id" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                            <option value="">{{ __("Bâtiment desservi (optionnel)") }}</option>
                            @foreach($buildings as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                        @endif
                        <div class="grid grid-cols-3 gap-3">
                            <input type="number" name="hours_run" step="0.5" min="0" max="24" required placeholder="{{ __('Heures *') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="fuel_consumed_liters" step="0.1" min="0" placeholder="{{ __('Gasoil L (auto)') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                            <input type="number" name="outage_hours" step="0.5" min="0" max="24" placeholder="{{ __('Coupures (h)') }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <input type="number" name="cost" step="100" min="0" placeholder="{{ __('Coût GNF (auto si vide)') }}" class="w-full bg-white border-none rounded-xl p-3 text-[10px] font-black shadow-sm outline-none">
                        <button type="submit" class="w-full bg-amber-500 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-check mr-1"></i> {{ __("Enregistrer") }}
                        </button>
                    </form>
                </div>
            </div>
            @endcan

            {{-- LIENS RAPIDES --}}
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('utilities.water.sources') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-cyan-50 hover:text-cyan-600 transition-all no-underline">
                    <i class="fa-solid fa-faucet-drip mr-1"></i> {{ __("Gérer les sources d'eau") }}
                </a>
                <a href="{{ route('utilities.energy.sources') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-amber-50 hover:text-amber-600 transition-all no-underline">
                    <i class="fa-solid fa-plug mr-1"></i> {{ __("Gérer les sources d'énergie") }}
                </a>
                <a href="{{ route('utilities.fuel.index') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-orange-50 hover:text-orange-600 transition-all no-underline">
                    <i class="fa-solid fa-gas-pump mr-1"></i> {{ __("Achats gasoil") }}
                </a>
            </div>
        </div>
    </div>

    {{-- Saisie « comme hier » : pré-remplit le formulaire avec le dernier relevé
         de la source sélectionnée. Les champs vides ne sont jamais imposés. --}}
    @can('ressources.C')
    <script>
        const RELEVE_LAST = {
            water:  @json($lastWater ?? []),
            energy: @json($lastEnergy ?? []),
        };

        document.querySelectorAll('[data-prefill-form]').forEach(form => {
            const kind   = form.dataset.prefillForm;
            const select = form.querySelector('[data-prefill-source]');
            if (! select) return;

            const applyPrefill = () => {
                const last = (RELEVE_LAST[kind] || {})[select.value];
                if (! last) return;
                Object.entries(last).forEach(([field, value]) => {
                    if (value === null || value === '') return;
                    const input = form.querySelector(`[name="${field}"]`);
                    // Ne pas écraser une valeur déjà saisie par l'opérateur.
                    if (input && (input.value === '' || input.value === '0')) {
                        input.value = value;
                    }
                });
            };

            select.addEventListener('change', applyPrefill);

            // Moins de clics au quotidien : si une seule source existe, on la
            // présélectionne ; et on pré-remplit dès le chargement « comme hier ».
            const realOptions = [...select.options].filter(o => o.value !== '');
            if (realOptions.length === 1) {
                select.value = realOptions[0].value;
            }
            if (select.value) applyPrefill();
        });
    </script>
    @endcan
</x-app-layout>
