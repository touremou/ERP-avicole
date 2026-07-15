<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Eau & Énergie')" :subtitle="__('Suivi des ressources — Période :') . ' ' . $period . ' ' . __('jours')" icon="fa-bolt" accent="cyan">
            <x-slot name="actions">
                <form method="GET" class="flex gap-2 items-center">
                    <select name="period" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                        <option value="7" {{ $period == 7 ? 'selected' : '' }}>{{ __("7 jours") }}</option>
                        <option value="30" {{ $period == 30 ? 'selected' : '' }}>{{ __("30 jours") }}</option>
                        <option value="90" {{ $period == 90 ? 'selected' : '' }}>{{ __("90 jours") }}</option>
                    </select>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- ONBOARDING : aucune source configurée → on explique la valeur --}}
            @if($waterSources->isEmpty() && $energySources->isEmpty())
            <div class="mb-8 bg-gradient-to-br from-cyan-50 to-amber-50 border border-cyan-100 rounded-[2.5rem] p-8 not-italic">
                <h3 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none mb-3">
                    <i class="fa-solid fa-bolt text-cyan-500 mr-2"></i> {{ __("Pilotez l'eau, l'énergie et le carburant") }}
                </h3>
                <p class="text-[11px] font-bold text-slate-500 normal-case mb-5 max-w-2xl">
                    {{ __("Ce module sécurise deux enjeux vitaux de la ferme :") }}
                </p>
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-white/70 rounded-2xl p-4">
                        <p class="text-[10px] font-black text-cyan-600 uppercase tracking-widest mb-1"><i class="fa-solid fa-shield-heart mr-1"></i> {{ __("Continuité de service") }}</p>
                        <p class="text-[10px] font-bold text-slate-500 normal-case">{{ __("Autonomie carburant, niveaux de citerne et maintenance des groupes : éviter la coupure qui met un lot en danger.") }}</p>
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
                        <p class="text-[8px] text-slate-400">{{ currency() }}</p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Coût / m³") }}</p>
                        <p class="text-xl font-black text-cyan-600">{{ number_format($data['water']['cost_per_m3']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ currency() }}</p>
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
                        <p class="text-[8px] text-slate-400">{{ currency() }}</p>
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
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Carburant consommé") }}</p>
                        <p class="text-xl font-black text-amber-600">{{ number_format($data['energy']['total_fuel_liters']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ __("litres") }}</p>
                    </div>
                    @if(($data['energy']['cost_per_kwh'] ?? 0) > 0)
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Coût / kWh") }}</p>
                        <p class="text-xl font-black text-amber-600">{{ number_format($data['energy']['cost_per_kwh']) }}</p>
                        <p class="text-[8px] text-slate-400">{{ currency() }}</p>
                    </div>
                    @endif
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
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Carburant") }}</p>
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

            {{-- La saisie des relevés a été déplacée sur les pages dédiées
                 (Eau / Énergie / Carburant) pour garder ce tableau de bord en
                 vue d'ensemble. Les liens rapides ci-dessous y mènent. --}}

            {{-- LIENS RAPIDES --}}
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('utilities.water.sources') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-cyan-50 hover:text-cyan-600 transition-all no-underline">
                    <i class="fa-solid fa-faucet-drip mr-1"></i> {{ __("Gérer les sources d'eau") }}
                </a>
                <a href="{{ route('utilities.energy.sources') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-amber-50 hover:text-amber-600 transition-all no-underline">
                    <i class="fa-solid fa-plug mr-1"></i> {{ __("Gérer les sources d'énergie") }}
                </a>
                <a href="{{ route('utilities.fuel.index') }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-orange-50 hover:text-orange-600 transition-all no-underline">
                    <i class="fa-solid fa-gas-pump mr-1"></i> {{ __("Achats carburant") }}
                </a>
            </div>
        </div>
    </div>

</x-app-layout>
