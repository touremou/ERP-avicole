<x-app-layout>
    <x-slot name="header">
        @php
            // ✅ ALERTES ECLOSION (J-21)
            $hatchingToday = $incubations->where('status', 'mirage_fait')
                                         ->where('hatch_date_expected', '<=', now()->endOfDay());
            $countAlerts = $hatchingToday->count();

            // ✅ LOGIQUE DE TRI DYNAMIQUE
            $sort = request('sort', 'date');
            $activeIncubations = $incubations->where('status', '!=', 'clos');

            if($sort == 'progress'){
                $sortedIncubations = $activeIncubations->sortByDesc(fn($i) => (int) \Carbon\Carbon::parse($i->start_date)->startOfDay()->diffInDays(now()->startOfDay()));
            } elseif($sort == 'eggs'){
                $sortedIncubations = $activeIncubations->sortByDesc('eggs_count');
            } else {
                $sortedIncubations = $activeIncubations->sortBy('hatch_date_expected');
            }

            // ✅ NOUVEAUX CALCULS INDUSTRIELS
            $freeIncubatorsCount = $incubators->where('status', 'Disponible')
                                              ->filter(fn($inc) => !$inc->incubations->contains(fn($i) => $i->status !== 'clos'))
                                              ->count();
            $maintenanceCount = $incubators->where('status', 'Maintenance')->count();
            
            // Calcul de l'encours et du taux d'occupation
            $totalEggsIncubating = $activeIncubations->sum('eggs_count');
            $totalCapacity = $incubators->where('status', '!=', 'Maintenance')->sum('capacity');
            $occupationRate = $totalCapacity > 0 ? round(($totalEggsIncubating / $totalCapacity) * 100, 1) : 0;
        @endphp

        <div class="space-y-6 text-left italic font-bold" x-data>
            {{-- 1. ALERTES CRITIQUES --}}
            @if($countAlerts > 0)
                <div class="bg-rose-600 text-white p-5 rounded-[2rem] shadow-xl flex items-center justify-between animate-pulse italic border-b-8 border-rose-800 transition-all">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.3em] leading-none mb-1">{{ __("Alerte Couvoir") }}</p>
                        <p class="text-xl font-black uppercase italic leading-none">⚠️ {{ $countAlerts }} {{ __("Éclosion(s) en attente de clôture") }}</p>
                    </div>
                    <span class="bg-white text-rose-600 px-5 py-2 rounded-2xl text-[10px] font-black uppercase italic shadow-lg">{{ __("Action requise") }}</span>
                </div>
            @endif

            {{-- 2. STATUT DYNAMIQUE DU PARC --}}
            <div class="flex flex-col md:flex-row items-center justify-between bg-white border border-slate-100 p-5 rounded-[3rem] shadow-sm gap-4">
                <div class="flex items-center gap-8 ml-6 w-full md:w-auto">
                    <div class="text-left">
                        <span class="text-[9px] text-slate-400 uppercase block leading-none mb-2 tracking-widest italic font-black">{{ __("Unités Libres") }}</span>
                        <span class="text-2xl font-black text-slate-800 italic leading-none">{{ $freeIncubatorsCount }}</span>
                    </div>
                    <div class="text-left border-l border-slate-100 pl-8">
                        <span class="text-[9px] text-rose-400 uppercase block leading-none mb-2 tracking-widest italic font-black">{{ __("En Maintenance") }}</span>
                        <span class="text-2xl font-black text-rose-600 italic leading-none">{{ $maintenanceCount }}</span>
                    </div>
                    {{-- NOUVEAU : Indicateur d'occupation du parc --}}
                    <div class="text-left border-l border-slate-100 pl-8 hidden md:block">
                        <span class="text-[9px] text-blue-400 uppercase block leading-none mb-2 tracking-widest italic font-black">{{ __("Charge Globale") }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-black text-blue-600 italic leading-none">{{ $occupationRate }}%</span>
                            <div class="w-16 h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500" style="width: {{ $occupationRate }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <a href="{{ route('incubators.index') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[1.5rem] text-[10px] uppercase font-black tracking-widest hover:bg-blue-600 transition-all shadow-xl italic no-underline text-center w-full md:w-auto">
                    ⚙️ {{ __("Configuration Parc") }}
                </a>
            </div>

            {{-- 3. HEADER ACTIONS --}}
            <div class="bg-slate-900 p-10 rounded-[3.5rem] text-white flex flex-col md:flex-row justify-between items-center shadow-2xl relative overflow-hidden group">
                <div class="relative z-10 text-left">
                    <h2 class="text-3xl font-black uppercase italic tracking-tighter leading-none mb-4">{{ __("Flux d'Incubation") }}</h2>
                    <form method="GET" class="flex items-center gap-3">
                        <label class="text-[9px] uppercase opacity-40 tracking-widest italic">{{ __("Trier par :") }}</label>
                        <select name="sort" onchange="this.form.submit()" class="text-[10px] font-black uppercase italic bg-white/10 border-none rounded-xl px-4 py-2 text-blue-400 focus:ring-0 cursor-pointer hover:bg-white/20 transition-all">
                            <option value="date" {{ $sort=='date'?'selected':'' }}>📅 {{ __("Échéance Éclosion") }}</option>
                            <option value="progress" {{ $sort=='progress'?'selected':'' }}>📊 {{ __("État d'avancement") }}</option>
                            <option value="eggs" {{ $sort=='eggs'?'selected':'' }}>🥚 {{ __("Volume de charge") }}</option>
                        </select>
                    </form>
                </div>

                <button @click.stop="$dispatch('open-launch-modal')" class="relative z-10 bg-blue-600 hover:bg-blue-500 px-10 py-5 rounded-[2rem] text-[11px] uppercase font-black shadow-2xl italic flex items-center gap-4 transition-all hover:scale-105 active:scale-95 border-none cursor-pointer mt-6 md:mt-0">
                    <i class="fa-solid fa-plus-circle text-lg"></i> {{ __("Nouveau Lancement") }}
                </button>

                <i class="fa-solid fa-dna absolute -right-10 -bottom-10 text-[15rem] opacity-5 rotate-12 group-hover:rotate-45 transition-transform duration-1000"></i>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-12">
            
            {{-- SECTION KPI MONITORING (ENRICHIE) --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 md:gap-6 font-bold italic">
                
                {{-- NOUVEAU KPI : Encours Actuel --}}
                <div class="bg-indigo-50 p-6 md:p-8 rounded-[2.5rem] border border-indigo-100 shadow-sm hover:shadow-md transition-shadow col-span-2 md:col-span-1">
                    <span class="text-[9px] text-indigo-400 uppercase font-black tracking-[0.2em] block mb-3">{{ __("Encours Actuel") }}</span>
                    <div class="flex items-end justify-between">
                        <span class="text-3xl md:text-4xl font-black text-indigo-900 leading-none tracking-tighter">{{ number_format($totalEggsIncubating, 0, ',', ' ') }}</span>
                    </div>
                </div>

                @php
                    $fertilityTarget = (float) setting('couvoir.fertility_target', 0);
                    $hatchTarget     = (float) setting('couvoir.hatchability_target', 0);
                @endphp
                <div class="bg-white p-6 md:p-8 rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-md transition-shadow col-span-2 md:col-span-1">
                    <span class="text-[9px] text-orange-400 uppercase font-black tracking-[0.2em] block mb-3">{{ __("Taux Fertilité (30j)") }}</span>
                    <div class="flex items-end justify-between">
                        <span class="text-3xl md:text-4xl font-black leading-none tracking-tighter {{ $fertilityTarget > 0 && $stats['avg_fertility'] < $fertilityTarget ? 'text-red-500' : 'text-slate-800' }}">{{ number_format($stats['avg_fertility'], 1) }}%</span>
                        @if($fertilityTarget > 0)
                            <span class="text-[9px] text-slate-400 uppercase font-black italic">{{ __("Cible") }} {{ (int) $fertilityTarget }}%</span>
                        @endif
                    </div>
                </div>

                <div class="bg-white p-6 md:p-8 rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-md transition-shadow col-span-2 md:col-span-1">
                    <span class="text-[9px] text-emerald-400 uppercase font-black tracking-[0.2em] block mb-3">{{ __("Taux Éclosion (30j)") }}</span>
                    <div class="flex items-end justify-between">
                        <span class="text-3xl md:text-4xl font-black leading-none tracking-tighter {{ $hatchTarget > 0 && $stats['avg_reussite'] < $hatchTarget ? 'text-red-500' : 'text-slate-800' }}">{{ number_format($stats['avg_reussite'], 1) }}%</span>
                        @if($hatchTarget > 0)
                            <span class="text-[9px] text-slate-400 uppercase font-black italic">{{ __("Cible") }} {{ (int) $hatchTarget }}%</span>
                        @endif
                    </div>
                </div>

                <div class="bg-slate-900 p-6 md:p-8 rounded-[2.5rem] shadow-2xl text-white relative overflow-hidden col-span-2 md:col-span-1">
                    <div class="relative z-10">
                        <span class="text-[9px] text-blue-400 uppercase font-black tracking-[0.2em] block mb-3 italic">{{ __("Poussins (30j)") }}</span>
                        <span class="text-3xl md:text-4xl font-black leading-none italic tracking-tighter">+{{ number_format($stats['total_poussins'], 0, ',', ' ') }}</span>
                    </div>
                    <i class="fa-solid fa-dove opacity-10 text-7xl absolute -right-4 -bottom-4 -rotate-12"></i>
                </div>

                @php $topMachine = $stats['machine_performance']->sortByDesc('avg_hatch')->first(); @endphp
                <div class="bg-blue-600 p-6 md:p-8 rounded-[2.5rem] shadow-2xl text-white text-left col-span-2 md:col-span-1">
                    <span class="text-[9px] text-blue-200 uppercase font-black tracking-[0.2em] block mb-3">{{ __("Top Unité") }}</span>
                    <p class="text-base font-black uppercase truncate leading-none mb-2 italic tracking-tighter">{{ $topMachine['name'] ?? __("N/A") }}</p>
                    <span class="text-[11px] opacity-90 font-black italic">{{ number_format($topMachine['avg_hatch'] ?? 0, 1) }}% {{ __("de réussite") }}</span>
                </div>
            </div>
            
            {{-- LISTE DES INCUBATIONS ACTIVES --}}
            <div class="space-y-4 font-bold italic">
                @forelse($sortedIncubations as $inc)
                    @php 
                        $start = \Carbon\Carbon::parse($inc->start_date)->startOfDay();
                        $now = now()->startOfDay();
                        
                        $daysElapsed = $now->greaterThanOrEqualTo($start) ? (int) $start->diffInDays($now) : 0;
                        $duration = (int) ($inc->incubation_duration ?: setting('couvoir.incubation_days', 21));
                        $progress = $duration > 0 ? min(round(($daysElapsed / $duration) * 100), 100) : 0;
                        
                        $statusColor = match($inc->status){
                            'incubation' => 'blue',
                            'mirage_fait' => 'orange',
                            default => 'emerald'
                        };

                        // Date de mirage (candling) pilotée par le paramètre couvoir.mirage_day.
                        $mirageDay  = (int) setting('couvoir.mirage_day', 10);
                        $mirageDate = $start->copy()->addDays($mirageDay);
                        $mirageDue  = $inc->status === 'incubation' && $now->greaterThanOrEqualTo($mirageDate);
                    @endphp

                    <div class="bg-white px-8 py-6 rounded-[2.5rem] border border-slate-100 flex flex-wrap md:flex-nowrap items-center gap-8 transition-all hover:shadow-2xl relative group">
                        <div class="w-full md:w-56 text-left border-r border-slate-50 pr-4">
                            <span class="text-[9px] font-black text-blue-500 uppercase tracking-widest opacity-60 italic leading-none block mb-2">
                                {{ $inc->code_incubation }} • {{ $inc->incubator->name ?? 'N/A' }}
                            </span>
                            <div class="text-lg font-black text-slate-800 uppercase italic truncate leading-tight tracking-tighter">
                                {{ $inc->batch->code ?? $inc->external_source_name }}
                            </div>
                        </div>

                        <div class="flex-1 min-w-[250px] text-left">
                            <div class="flex justify-between text-[10px] font-black uppercase italic text-slate-400 mb-3 leading-none tracking-widest">
                                <span>{{ __("Jour") }} {{ $daysElapsed }} <span class="text-[8px] opacity-40">/ {{ $duration }}</span></span>
                                <span class="font-black text-{{ $statusColor }}-500">{{ $progress }}%</span>
                            </div>
                            <div class="w-full bg-slate-50 h-3 rounded-full overflow-hidden shadow-inner p-[2px] border border-slate-100">
                                <div class="h-full rounded-full transition-all duration-1000 ease-out bg-{{ $statusColor }}-500 shadow-sm" style="width: {{ $progress }}%"></div>
                            </div>
                            <p class="text-[8px] text-slate-300 mt-2 uppercase font-black">{{ __("Éclosion prévue :") }} {{ \Carbon\Carbon::parse($inc->hatch_date_expected)->translatedFormat('d F Y') }}</p>
                            @if($inc->status === 'incubation')
                                <p class="text-[8px] mt-1 uppercase font-black {{ $mirageDue ? 'text-orange-500' : 'text-slate-300' }}">
                                    <i class="fa-solid fa-lightbulb mr-1"></i>{{ __("Mirage (J") }}{{ $mirageDay }}) : {{ $mirageDate->translatedFormat('d F Y') }}{{ $mirageDue ? ' — '.__("à faire") : '' }}
                                </p>
                            @endif
                        </div>

                        <div class="flex gap-8 px-6 text-center">
                            <div>
                                <span class="text-[8px] text-slate-400 uppercase block leading-none mb-2 font-black italic tracking-widest">{{ __("Volume Mis") }}</span>
                                <span class="text-xl font-black text-slate-800 leading-none italic tracking-tighter">{{ number_format($inc->eggs_count, 0, ',', ' ') }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 ml-auto" x-data="{ openAction: false }">
                            @if($inc->status == 'incubation')
                                <button @click.stop="openAction = true" class="px-6 py-3 bg-orange-500 hover:bg-orange-600 text-white text-[10px] font-black uppercase rounded-2xl transition-all flex items-center gap-3 shadow-xl border-none cursor-pointer italic">
                                    <i class="fa-solid fa-magnifying-glass"></i> {{ __("Mirage J-10") }}
                                </button>
                                {{-- Modal Mirage --}}
                                <div x-show="openAction" x-cloak x-transition.opacity class="fixed inset-0 z-[150] flex items-center justify-center bg-slate-900/90 backdrop-blur-md p-4">
                                    <div @click.outside="openAction = false" class="bg-white p-12 rounded-[4rem] w-full max-w-sm text-center relative font-bold text-slate-800 italic shadow-2xl">
                                        <button @click="openAction = false" type="button" class="absolute top-8 right-10 text-slate-200 hover:text-rose-500 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-circle-xmark text-3xl"></i></button>
                                        <h3 class="font-black uppercase text-xl mb-6 leading-none italic tracking-tighter underline decoration-orange-500 decoration-4">🔍 {{ __("Contrôle de Fertilité") }}</h3>
                                        <form action="{{ route('incubations.mirage', $inc->id) }}" method="POST" class="space-y-6 text-left">
                                            @csrf
                                            <div class="space-y-2">
                                                <label class="text-[9px] font-black text-slate-400 uppercase ml-2 italic">{{ __("Nombre d'œufs fertiles") }}</label>
                                                <input type="number" min="0" placeholder="0" name="fertile_eggs" max="{{ $inc->eggs_count }}" required class="w-full text-center text-5xl font-black bg-slate-50 border-none rounded-[2rem] p-8 italic shadow-inner outline-none focus:ring-4 focus:ring-orange-500/10 transition-all text-slate-900">
                                            </div>
                                            <button type="submit" class="w-full bg-slate-900 text-white py-6 rounded-[1.5rem] font-black uppercase text-[11px] italic shadow-2xl border-none cursor-pointer hover:bg-orange-600 transition-all tracking-[0.2em]">{{ __("Enregistrer Mirage") }}</button>
                                        </form>
                                    </div>
                                </div>
                            @endif

                            @if($inc->status == 'mirage_fait')
                                <button @click.stop="openAction = true" class="px-6 py-3 bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase rounded-2xl transition-all flex items-center gap-3 shadow-xl border-none cursor-pointer italic">
                                    <i class="fa-solid fa-egg"></i> {{ __("Clôture Éclosion") }}
                                </button>
                                {{-- Modal Éclosion --}}
                                <div x-show="openAction" x-cloak x-transition.opacity class="fixed inset-0 z-[150] flex items-center justify-center bg-slate-900/90 backdrop-blur-md p-4">
                                    <div @click.outside="openAction = false" class="bg-white p-12 rounded-[4rem] w-full max-w-sm text-center relative font-bold text-slate-800 italic shadow-2xl">
                                        <button @click="openAction = false" type="button" class="absolute top-8 right-10 text-slate-200 hover:text-rose-500 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-circle-xmark text-3xl"></i></button>
                                        <h3 class="font-black uppercase text-xl mb-6 text-emerald-600 leading-none italic tracking-tighter underline decoration-emerald-200 decoration-4">🐣 {{ __("Bilan d'Éclosion") }}</h3>
                                        <form action="{{ route('incubations.hatch', $inc->id) }}" method="POST" class="space-y-6 text-left">
                                            @csrf
                                            <div class="space-y-2">
                                                <label class="text-[9px] font-black text-slate-400 uppercase ml-2 italic">{{ __("Poussins viables nés") }}</label>
                                                <input type="number" min="0" placeholder="0" name="hatched_chicks" max="{{ $inc->fertile_eggs }}" required class="w-full text-center text-5xl font-black bg-slate-50 border-none rounded-[2rem] p-8 italic shadow-inner outline-none focus:ring-4 focus:ring-emerald-500/10 transition-all text-emerald-900">
                                            </div>
                                            <button type="submit" class="w-full bg-slate-900 text-white py-6 rounded-[1.5rem] font-black uppercase text-[11px] italic shadow-2xl border-none cursor-pointer hover:bg-emerald-600 transition-all tracking-[0.2em]">{{ __("Clôturer le Cycle") }}</button>
                                        </form>
                                    </div>
                                </div>
                            @endif

                            {{-- Bouton Dispatch (status = clos, APRÈS éclosion) — SÉPARÉ --}}
                            @if($inc->status == 'clos' && ($inc->hatched_chicks ?? 0) > 0)
                                @php
                                    $alreadyDispatched = (int) ($inc->chick_dispatches_sum_quantity ?? 0);
                                    $chickRemaining = max(0, ($inc->hatched_chicks ?? 0) - $alreadyDispatched);
                                @endphp
                                <a href="{{ route('chick-dispatches.show', $inc) }}"
                                @class([
                                    'px-6 py-3 text-white text-[10px] font-black uppercase rounded-2xl transition-all flex items-center gap-3 shadow-xl no-underline italic',
                                    'bg-blue-500 hover:bg-blue-600' => $chickRemaining > 0,
                                    'bg-slate-400 hover:bg-slate-500' => $chickRemaining === 0,
                                ])>
                                    @if($chickRemaining > 0)
                                        <i class="fa-solid fa-paper-plane"></i> {{ __("Dispatcher") }} ({{ $chickRemaining }})
                                    @else
                                        <i class="fa-solid fa-check-double"></i> {{ __("Dispatches") }} ({{ $alreadyDispatched }})
                                    @endif
                                </a>
                            @endif

                            <form action="{{ route('incubations.destroy', $inc->id) }}" method="POST" onsubmit="return confirm(@json(__("DÉCISION CRITIQUE : Annuler définitivement ce cycle ?")))">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-slate-200 hover:text-rose-500 transition-all hover:scale-110 border-none bg-transparent cursor-pointer">
                                    <i class="fa-solid fa-trash-can text-xl"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-24 bg-white rounded-[4rem] border-4 border-dashed border-slate-50 italic">
                        <i class="fa-solid fa-wind text-4xl text-slate-100 mb-4"></i>
                        <p class="text-[11px] font-black text-slate-300 uppercase tracking-[0.4em]">{{ __("Flux de production à l'arrêt") }}</p>
                    </div>
                @endforelse
            </div>

            {{-- JOURNAL (HISTORIQUE ACCORDÉON) --}}
            <div class="mt-24 border-t border-slate-100 pt-16 text-left italic font-bold">
                <div class="flex items-center gap-6 mb-12">
                    <div class="w-16 h-16 bg-slate-900 rounded-[2rem] flex items-center justify-center text-white shadow-2xl">
                        <i class="fa-solid fa-book-medical text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-3xl font-black text-slate-800 uppercase leading-none tracking-tighter italic">{{ __("Registre d'Accouvage") }}</h3>
                        <p class="text-[11px] text-slate-400 font-black uppercase tracking-[0.3em] mt-2 italic leading-none">{{ __("Archives de performance consolidées") }}</p>
                    </div>
                </div>

                <div class="space-y-4 font-bold italic" x-data="{ activeMonth: null }">
                    @foreach($stats['historique'] as $month => $data)
                        <div class="bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm hover:shadow-lg transition-all">
                            <button @click="activeMonth = (activeMonth === '{{ $month }}' ? null : '{{ $month }}')" 
                                    class="w-full px-10 py-7 flex justify-between items-center hover:bg-slate-50 transition-colors italic font-black uppercase border-none bg-transparent cursor-pointer">
                                <span class="text-slate-800 tracking-[0.2em] text-sm">{{ $month }}</span>
                                <div class="flex items-center gap-10">
                                    <div class="hidden md:flex items-center gap-6">
                                        <span class="text-[9px] text-slate-400 uppercase font-black italic">{{ __("Moy. Éclosion :") }} <span class="text-emerald-500 ml-1">{{ number_format($data['avg_hatchability'], 1) }}%</span></span>
                                        <span class="text-[9px] text-slate-400 uppercase font-black italic">{{ __("Productivité :") }} <span class="text-blue-500 ml-1">{{ number_format($data['total_chicks'], 0, ',', ' ') }}</span></span>
                                    </div>
                                    <i class="fa-solid fa-chevron-down text-slate-300 transition-transform duration-500" :class="activeMonth === '{{ $month }}' ? 'rotate-180 text-blue-500' : ''"></i>
                                </div>
                            </button>

                            <div x-show="activeMonth === '{{ $month }}'" x-collapse class="px-10 pb-10">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-[11px] font-black text-left italic">
                                        <thead>
                                            <tr class="text-[8px] text-slate-400 uppercase tracking-widest border-b border-slate-50 italic">
                                                <th class="py-5">{{ __("Code Cycle") }}</th>
                                                <th class="py-5 text-center">{{ __("Volume Mis") }}</th>
                                                <th class="py-5 text-center">{{ __("Nés Viables") }}</th>
                                                <th class="py-5 text-center">{{ __("Succès Brute") }}</th>
                                                <th class="py-5 text-right">{{ __("Actions") }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-50">
                                            @foreach($data['items'] as $item)
                                                <tr class="text-slate-600 hover:bg-slate-50 transition-colors">
                                                    <td class="py-5">
                                                        <span class="text-blue-600 uppercase">{{ $item->code_incubation }}</span><br>
                                                        <span class="text-[8px] text-slate-300 font-bold tracking-tighter italic uppercase">{{ $item->incubator->name ?? __("N/A") }}</span>
                                                    </td>
                                                    <td class="py-5 text-center text-slate-400">{{ $item->eggs_count }}</td>
                                                    <td class="py-5 text-center text-slate-800">{{ $item->hatched_chicks }}</td>
                                                    <td class="py-5 text-center">
                                                        <span @class([
                                                            'px-4 py-1.5 rounded-xl text-[9px] font-black border uppercase italic',
                                                            'bg-emerald-50 text-emerald-600 border-emerald-100' => $item->hatchability_rate >= 80,
                                                            'bg-orange-50 text-orange-600 border-orange-100' => $item->hatchability_rate < 80,
                                                        ])>
                                                            {{ number_format($item->hatchability_rate, 1) }}%
                                                        </span>
                                                    </td>
                                                    <td class="py-5 text-right">
                                                        <div class="flex items-center justify-end gap-2">
                                                            {{-- DISPATCH --}}
                                                            @if(($item->hatched_chicks ?? 0) > 0)
                                                                @php
                                                                    $dispatched = (int) ($item->chick_dispatches_sum_quantity ?? 0);
                                                                    $left = max(0, ($item->hatched_chicks ?? 0) - $dispatched);
                                                                @endphp
                                                                <a href="{{ route('chick-dispatches.show', $item) }}"
                                                                @class([
                                                                    'px-3 py-1.5 rounded-xl text-[8px] font-black uppercase no-underline inline-flex items-center gap-1.5',
                                                                    'bg-blue-50 text-blue-600 hover:bg-blue-100' => $left > 0,
                                                                    'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' => $left === 0,
                                                                ])>
                                                                    @if($left > 0)
                                                                        <i class="fa-solid fa-paper-plane"></i> {{ $left }} {{ __("restants") }}
                                                                    @else
                                                                        <i class="fa-solid fa-check-double"></i> {{ $dispatched }}
                                                                    @endif
                                                                </a>
                                                            @endif

                                                            {{-- SUPPRIMER --}}
                                                            @can('production.S')
                                                            <form action="{{ route('incubations.destroy', $item->id) }}" method="POST" onsubmit="return confirm(@json(__("Supprimer cette archive ?")))">
                                                                @csrf @method('DELETE')
                                                                <button type="submit" class="text-slate-200 hover:text-rose-500 transition-all border-none bg-transparent cursor-pointer">
                                                                    <i class="fa-solid fa-trash-can text-sm"></i>
                                                                </button>
                                                            </form>
                                                            @endcan
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if(method_exists($incubations, 'links'))
                <div class="mt-12 flex justify-center pb-20">
                    {{ $incubations->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- 🚀 MODAL DE LANCEMENT GLOBAL --}}
    {{-- Composant défini via fonction : le JSON (@json) vit dans un vrai
         contexte JS et ne casse plus l'attribut x-data (guillemets doubles). --}}
    <script>
        function incubationLaunchModal() {
            return {
                openLaunch: {{ $errors->any() ? 'true' : 'false' }},
                isExternal: false,
                selectedProvider: '',
                duration: 21,
                incubationDurations: @json($incubationDurations ?? []),
                updateDuration(event) {
                    const opt = event.target.options[event.target.selectedIndex];
                    const species = opt?.dataset?.species;
                    this.duration = this.incubationDurations[species] ?? 21;
                }
            };
        }
    </script>
    <div x-data="incubationLaunchModal()"
         @open-launch-modal.window="openLaunch = true"
         x-show="openLaunch" 
         x-cloak 
         class="fixed inset-0 z-[9999] overflow-y-auto">
        
        {{-- Overlay sombre avec flou --}}
        <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm transition-opacity"></div>

        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div @click.outside="openLaunch = false" 
                 x-show="openLaunch"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95 translate-y-8"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 class="relative bg-white w-full max-w-2xl rounded-[3rem] p-8 md:p-10 shadow-2xl text-left italic font-bold">
                
                {{-- Bouton Fermer --}}
                <button @click="openLaunch = false" type="button" class="absolute top-8 right-8 text-slate-300 hover:text-rose-500 hover:rotate-90 transition-all border-none bg-transparent cursor-pointer">
                    <i class="fa-solid fa-circle-xmark text-3xl"></i>
                </button>

                {{-- En-tête du Modal --}}
                <div class="mb-8">
                    <h2 class="text-3xl font-black uppercase mb-1 leading-none italic text-slate-900 tracking-tighter">
                        🥚 {{ __("Nouveau") }} <span class="text-blue-600">{{ __("Cycle") }}</span>
                    </h2>
                    <p class="text-[10px] text-slate-400 uppercase tracking-[0.3em] font-black italic">
                        {{ __("Paramétrage du flux d'accouvage") }}
                    </p>
                </div>

                {{-- Toggle Interne / Externe (Style Segmented Control) --}}
                <div class="flex bg-slate-100 p-1.5 rounded-2xl mb-8 shadow-inner">
                    <button type="button"
                            @click="isExternal = false; selectedProvider = ''"
                            :class="!isExternal ? 'bg-white shadow-sm text-blue-600' : 'text-slate-400 hover:text-slate-600'"
                            class="flex-1 py-3 text-[10px] uppercase font-black rounded-xl transition-all duration-300 border-none cursor-pointer italic">
                        🏠 {{ __("Production Interne") }}
                    </button>
                    <button type="button"
                            @click="isExternal = true"
                            :class="isExternal ? 'bg-white shadow-sm text-orange-600' : 'text-slate-400 hover:text-slate-600'"
                            class="flex-1 py-3 text-[10px] uppercase font-black rounded-xl transition-all duration-300 border-none cursor-pointer italic">
                        🚚 {{ __("Achat Externe") }}
                    </button>
                </div>

                <form action="{{ route('incubations.store') }}" method="POST" class="space-y-6">
                    @csrf
                    <input type="hidden" name="source_type" :value="isExternal ? 'external' : 'internal'">

                    {{-- Section des Choix (Machine & Origine) --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        
                        {{-- Choix de la Machine (Toujours visible) --}}
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2">{{ __("Unité d'Incubation") }}</label>
                            <div class="relative">
                                <select name="incubator_id" required class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 font-black text-xs italic shadow-sm outline-none focus:ring-2 focus:ring-blue-500/50 transition-all appearance-none cursor-pointer text-slate-700">
                                    <option value="">{{ __("Choisir machine...") }}</option>
                                    @foreach($incubators as $incubator)
                                        @php $isBusy = $incubator->incubations->contains(fn($inc) => $inc->status !== 'clos'); @endphp
                                        <option value="{{ $incubator->id }}" {{ ($incubator->status == 'Maintenance' || $isBusy) ? 'disabled' : '' }}>
                                            {{ $incubator->name }} (Cap. {{ number_format($incubator->capacity, 0, ',', ' ') }})
                                        </option>
                                    @endforeach
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none"></i>
                            </div>
                        </div>

                        {{-- Choix Interne : Lot --}}
                        <div x-show="!isExternal" class="space-y-2">
                            <label class="text-[10px] font-black text-blue-500 uppercase italic ml-2">{{ __("Lot Reproducteur") }}</label>
                            <div class="relative">
                                <select name="batch_id" @change="updateDuration($event)" :required="!isExternal" class="w-full bg-blue-50/50 border border-blue-100 rounded-2xl px-5 py-4 font-black text-xs italic shadow-sm outline-none focus:ring-2 focus:ring-blue-500/50 transition-all appearance-none cursor-pointer text-blue-900">
                                    <option value="">{{ __("-- Sélectionner le lot --") }}</option>
                                    @foreach($activeBatches as $batch)
                                        <option value="{{ $batch->id }}" data-species="{{ $batch->species->slug ?? 'poulet' }}">{{ $batch->code }}</option>
                                    @endforeach
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-blue-300 pointer-events-none"></i>
                            </div>
                        </div>

                        {{-- Choix Externe : Fournisseur --}}
                        <div x-show="isExternal" x-cloak class="space-y-2">
                            <label class="text-[10px] font-black text-orange-500 uppercase italic ml-2">{{ __("Fournisseur Externe") }}</label>
                            <div class="relative">
                                <select name="provider_id" x-model="selectedProvider" :required="isExternal" class="w-full bg-orange-50 border border-orange-100 rounded-2xl px-5 py-4 font-black text-xs italic shadow-sm outline-none focus:ring-2 focus:ring-orange-500/50 transition-all appearance-none cursor-pointer text-orange-900">
                                    <option value="">{{ __("-- Sélectionner --") }}</option>
                                    @foreach($providers as $provider)
                                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                    @endforeach
                                    <option value="new" class="font-black text-rose-600 bg-white">➕ {{ __("NOUVEAU FOURNISSEUR...") }}</option>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-orange-300 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    {{-- BLOC CRÉATION FOURNISSEUR (Prend toute la largeur sous la grille) --}}
                    <div x-show="isExternal && selectedProvider === 'new'" x-collapse>
                        <div class="bg-orange-50 p-6 rounded-[2rem] border border-orange-100 shadow-inner">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="bg-orange-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-[10px]"><i class="fa-solid fa-plus"></i></div>
                                <p class="text-[10px] font-black text-orange-700 uppercase tracking-widest italic leading-none">{{ __("Création Express") }}</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <input type="text" name="new_provider_name" placeholder="{{ __('Nom de l\'entreprise *') }}" class="w-full bg-white border border-slate-100 rounded-xl px-4 py-3 font-black text-xs italic shadow-sm outline-none focus:ring-2 focus:ring-orange-500 text-slate-800" :required="selectedProvider === 'new'">
                                <input type="text" name="new_provider_phone" placeholder="{{ __('Téléphone *') }}" class="w-full bg-white border border-slate-100 rounded-xl px-4 py-3 font-black text-xs italic shadow-sm outline-none focus:ring-2 focus:ring-orange-500 text-slate-800" :required="selectedProvider === 'new'">

                                <div class="relative">
                                    <select name="new_provider_type" class="w-full bg-white border border-slate-100 rounded-xl px-4 py-3 font-black text-xs italic shadow-sm outline-none focus:ring-2 focus:ring-orange-500 text-slate-800 appearance-none cursor-pointer" :required="selectedProvider === 'new'">
                                        <option value="Poussins">{{ __("Type: Poussins/Œufs") }}</option>
                                        <option value="Autre">{{ __("Type: Autre") }}</option>
                                    </select>
                                    <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none text-[10px]"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Section Critique (Volume, Date et Durée) --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 sm:gap-6 bg-slate-900 p-6 sm:p-8 rounded-[2.5rem] shadow-xl relative overflow-hidden mt-4">
                        <div class="space-y-2 relative z-10">
                            <label class="text-[10px] font-black text-blue-400 uppercase italic ml-2 block text-center tracking-widest">{{ __("Volume Mis") }}</label>
                            <input type="number" min="1" name="eggs_count" required placeholder="0" class="w-full bg-white/10 border border-white/5 rounded-2xl py-4 sm:py-6 font-black italic shadow-inner text-center text-2xl sm:text-4xl text-white outline-none focus:bg-white/20 focus:border-blue-500/50 transition-all placeholder:text-white/20">
                        </div>

                        <div class="space-y-2 relative z-10">
                            <label class="text-[10px] font-black text-blue-400 uppercase italic ml-2 block text-center tracking-widest">{{ __("Lancement") }}</label>
                            <input type="date" name="start_date" value="{{ date('Y-m-d') }}" required class="w-full bg-white/10 border border-white/5 rounded-2xl py-4 sm:py-6 px-2 font-black text-sm sm:text-lg italic shadow-inner text-center text-white outline-none focus:bg-white/20 focus:border-blue-500/50 transition-all">
                        </div>

                        <div class="space-y-2 relative z-10 col-span-2 sm:col-span-1">
                            <label class="text-[10px] font-black text-blue-400 uppercase italic ml-2 block text-center tracking-widest">{{ __("Durée (jours)") }}</label>
                            <input type="number" min="10" max="60" name="duration" x-model="duration" placeholder="21" class="w-full bg-white/10 border border-white/5 rounded-2xl py-4 sm:py-6 font-black italic shadow-inner text-center text-2xl sm:text-4xl text-white outline-none focus:bg-white/20 focus:border-blue-500/50 transition-all placeholder:text-white/20">
                        </div>

                        <i class="fa-solid fa-egg absolute -right-6 -bottom-6 text-white/5 text-[8rem] rotate-12 pointer-events-none"></i>
                    </div>

                    {{-- Coût de revient du couvoir : œufs + frais d'incubation,
                         répercutés sur les poussins éclos (coût d'acquisition du lot). --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div class="space-y-1">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2 block tracking-widest">
                                <i class="fa-solid fa-coins text-amber-500 mr-1"></i> {{ __("Coût unitaire de l'œuf") }} ({{ setting('general.currency', 'GNF') }})
                            </label>
                            <input type="number" min="0" step="0.01" name="egg_unit_cost" value="{{ old('egg_unit_cost') }}"
                                placeholder="{{ __('Achat ou valeur interne / œuf') }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner text-center outline-none focus:ring-2 focus:ring-amber-400">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2 block tracking-widest">
                                <i class="fa-solid fa-bolt text-amber-500 mr-1"></i> {{ __("Frais d'incubation (cycle)") }} ({{ setting('general.currency', 'GNF') }})
                            </label>
                            <input type="number" min="0" step="0.01" name="overhead_cost" value="{{ old('overhead_cost') }}"
                                placeholder="{{ __('Énergie, main-d\'œuvre, amortissement') }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner text-center outline-none focus:ring-2 focus:ring-amber-400">
                        </div>
                    </div>
                    <p class="text-[8px] text-slate-400 ml-2 italic mt-1">{{ __("Coût/poussin = (coût des œufs + frais d'incubation) ÷ poussins éclos — répercuté sur le lot de poussinière.") }}</p>

                    {{-- Bouton de validation --}}
                    <button type="submit" class="w-full bg-blue-600 text-white font-black py-6 rounded-[2rem] uppercase italic shadow-[0_15px_30px_-10px_rgba(37,99,235,0.5)] hover:bg-blue-500 hover:shadow-[0_20px_40px_-10px_rgba(37,99,235,0.6)] transition-all transform hover:-translate-y-1 active:scale-95 active:translate-y-0 flex items-center justify-center gap-3 tracking-[0.2em] text-xs border-none cursor-pointer mt-4">
                        <i class="fa-solid fa-power-off text-blue-200"></i> {{ __("Initialiser la Production") }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>