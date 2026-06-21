<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-clipboard-check text-lg"></i></div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Planning Opérationnel") }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ $date->translatedFormat('l d F Y') }}</p>
                </div>
            </div>
            @can('annuaire.M')
            <div class="flex gap-2">
                <a href="{{ route('tasks.templates') }}" class="bg-white border border-slate-200 px-4 py-2 rounded-xl text-[9px] font-black uppercase italic text-slate-600 hover:bg-slate-50 no-underline"><i class="fa-solid fa-gear text-slate-400 mr-1"></i> {{ __("Templates") }}</a>
                <form method="POST" action="{{ route('tasks.generate') }}">@csrf
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <button class="bg-indigo-600 text-white px-5 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-indigo-700 border-none cursor-pointer shadow-lg italic"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> {{ __("Générer") }}</button>
                </form>
            </div>
            @endcan
        </div>
    </x-slot>

    <div class="py-6 italic font-bold" x-data="taskBoard()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-4 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- BARRE DE FILTRES --}}
            <form method="GET" action="{{ route('tasks.index') }}" id="filterForm" class="mb-4 flex flex-wrap items-center gap-2">
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">

                {{-- Filtre employé réservé à l'encadrement : un opérateur est
                     verrouillé sur ses propres tâches côté serveur. --}}
                @if($canSeeAll ?? false)
                <select name="employee" onchange="document.getElementById('filterForm').submit()" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-[9px] font-black uppercase text-slate-600 shadow-sm outline-none cursor-pointer">
                    <option value="">{{ __("Tous les employés") }}</option>
                    @foreach($employees as $e)
                        <option value="{{ $e->id }}" {{ ($activeFilters['employee'] ?? '') == $e->id ? 'selected' : '' }}>{{ $e->first_name }} {{ $e->last_name }}</option>
                    @endforeach
                </select>
                @else
                <span class="bg-indigo-50 text-indigo-600 border border-indigo-100 rounded-xl px-3 py-2 text-[9px] font-black uppercase shadow-sm">
                    <i class="fa-solid fa-user-check mr-1"></i>{{ __("Mes tâches") }}
                </span>
                @endif
                <select name="building" onchange="document.getElementById('filterForm').submit()" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-[9px] font-black uppercase text-slate-600 shadow-sm outline-none cursor-pointer">
                    <option value="">{{ __("Tous les bâtiments") }}</option>
                    @foreach($buildings as $b)
                        <option value="{{ $b->id }}" {{ ($activeFilters['building'] ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
                <select name="category" onchange="document.getElementById('filterForm').submit()" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-[9px] font-black uppercase text-slate-600 shadow-sm outline-none cursor-pointer">
                    <option value="">{{ __("Toutes catégories") }}</option>
                    @foreach(['alimentation' => __("🌾 Alimentation"), 'collecte' => __("🥚 Collecte"), 'controle' => __("📋 Contrôle"), 'nettoyage' => __("🧹 Nettoyage"), 'sante' => __("💉 Santé"), 'maintenance' => __("🔧 Maintenance")] as $k => $v)
                        <option value="{{ $k }}" {{ ($activeFilters['category'] ?? '') === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>
                <select name="priority" onchange="document.getElementById('filterForm').submit()" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-[9px] font-black uppercase text-slate-600 shadow-sm outline-none cursor-pointer">
                    <option value="">{{ __("Toutes priorités") }}</option>
                    @foreach(['critique' => __("🔴 Critique"), 'haute' => __("🟠 Haute"), 'normale' => __("⚪ Normale"), 'basse' => __("🔵 Basse")] as $k => $v)
                        <option value="{{ $k }}" {{ ($activeFilters['priority'] ?? '') === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>

                @if(!empty($activeFilters))
                <a href="{{ route('tasks.index', ['date' => $date->toDateString()]) }}" class="text-[9px] font-black text-red-400 hover:text-red-600 uppercase tracking-widest no-underline px-2">
                    <i class="fa-solid fa-xmark mr-0.5"></i> {{ __("Reset") }}
                </a>
                @endif
            </form>

            @php
                $filterParams = $activeFilters ?? [];
                $currentView = $view ?? 'day';
            @endphp

            {{-- TOGGLE VUE JOUR / MOIS --}}
            <div class="mb-4 flex items-center justify-between">
                <div class="flex bg-slate-100 rounded-xl p-1 gap-1">
                    <a href="{{ route('tasks.index', array_merge(['date' => $date->toDateString(), 'view' => 'day'], $filterParams)) }}"
                       @class(['px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest no-underline transition-all',
                           'bg-white text-indigo-600 shadow-sm' => $currentView === 'day',
                           'text-slate-400 hover:text-slate-600' => $currentView !== 'day'])>
                        <i class="fa-solid fa-list mr-1"></i> {{ __("Jour") }}
                    </a>
                    <a href="{{ route('tasks.index', array_merge(['date' => $date->toDateString(), 'view' => 'month'], $filterParams)) }}"
                       @class(['px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest no-underline transition-all',
                           'bg-white text-indigo-600 shadow-sm' => $currentView === 'month',
                           'text-slate-400 hover:text-slate-600' => $currentView !== 'month'])>
                        <i class="fa-solid fa-calendar-days mr-1"></i> {{ __("Mois") }}
                    </a>
                </div>
                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                    {{ $currentView === 'month' ? $date->translatedFormat('F Y') : $date->translatedFormat('l d F Y') }}
                </span>
            </div>

            {{-- ═══ VUE MOIS ═══ --}}
            @if($currentView === 'month')
                {{-- Navigation mois --}}
                <div class="flex items-center justify-between mb-5">
                    <a href="{{ route('tasks.index', array_merge(['date' => $date->copy()->subMonth()->startOfMonth()->toDateString(), 'view' => 'month'], $filterParams)) }}" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline border border-slate-200"><i class="fa-solid fa-chevron-left"></i></a>
                    <h3 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter">{{ $date->translatedFormat('F Y') }}</h3>
                    <a href="{{ route('tasks.index', array_merge(['date' => $date->copy()->addMonth()->startOfMonth()->toDateString(), 'view' => 'month'], $filterParams)) }}" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline border border-slate-200"><i class="fa-solid fa-chevron-right"></i></a>
                </div>

                {{-- Grille calendrier --}}
                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden mb-6">
                    {{-- En-têtes jours --}}
                    <div class="grid grid-cols-7 border-b border-slate-100">
                        @foreach([__("Lun"), __("Mar"), __("Mer"), __("Jeu"), __("Ven"), __("Sam"), __("Dim")] as $dayName)
                            <div class="px-2 py-3 text-center text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ $dayName }}</div>
                        @endforeach
                    </div>

                    {{-- Cases du mois --}}
                    @php
                        $startOfMonth = $date->copy()->startOfMonth();
                        $endOfMonth = $date->copy()->endOfMonth();
                        $startPadding = $startOfMonth->dayOfWeekIso - 1;
                        $today = now()->toDateString();
                    @endphp
                    <div class="grid grid-cols-7">
                        {{-- Cases vides avant le 1er --}}
                        @for($i = 0; $i < $startPadding; $i++)
                            <div class="p-2 min-h-[80px] bg-slate-50/50"></div>
                        @endfor

                        {{-- Jours du mois --}}
                        @php $cursor = $startOfMonth->copy(); @endphp
                        @while($cursor->lte($endOfMonth))
                            @php
                                $key = $cursor->format('Y-m-d');
                                $day = $calendarData[$key] ?? ['total' => 0, 'done' => 0, 'late' => 0, 'rate' => null];
                                $isToday = $key === $today;
                                $isSelected = $key === $date->toDateString();
                            @endphp
                            <a href="{{ route('tasks.index', array_merge(['date' => $key, 'view' => 'day'], $filterParams)) }}"
                               @class(['p-2 min-h-[80px] border-t border-r border-slate-50 transition-all no-underline group',
                                   'bg-indigo-50 ring-2 ring-indigo-500 ring-inset' => $isToday,
                                   'hover:bg-slate-50' => !$isToday])>
                                <div class="flex justify-between items-start">
                                    <span @class(['text-xs font-black',
                                        'text-indigo-600' => $isToday,
                                        'text-slate-800' => !$isToday && $cursor->isWeekday(),
                                        'text-slate-400' => !$isToday && $cursor->isWeekend()])>{{ $cursor->day }}</span>
                                    @if($day['late'] > 0)
                                        <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                                    @endif
                                </div>

                                @if($day['total'] > 0)
                                    {{-- Barre de progression --}}
                                    <div class="mt-2 w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                        <div @class(['h-full rounded-full transition-all',
                                            'bg-emerald-500' => ($day['rate'] ?? 0) >= 80,
                                            'bg-amber-400' => ($day['rate'] ?? 0) >= 40 && ($day['rate'] ?? 0) < 80,
                                            'bg-red-400' => ($day['rate'] ?? 0) < 40 && ($day['rate'] ?? 0) > 0,
                                            'bg-slate-200' => ($day['rate'] ?? 0) === 0])
                                             style="width: {{ $day['rate'] ?? 0 }}%"></div>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between">
                                        <span class="text-[7px] font-black text-slate-400">{{ $day['done'] }}/{{ $day['total'] }}</span>
                                        @if($day['rate'] !== null)
                                            <span @class(['text-[7px] font-black',
                                                'text-emerald-500' => $day['rate'] >= 80,
                                                'text-amber-500' => $day['rate'] >= 40 && $day['rate'] < 80,
                                                'text-red-500' => $day['rate'] < 40])>{{ $day['rate'] }}%</span>
                                        @endif
                                    </div>
                                @else
                                    <p class="mt-3 text-[7px] text-slate-200 text-center font-black">—</p>
                                @endif
                            </a>
                            @php $cursor->addDay(); @endphp
                        @endwhile

                        {{-- Cases vides après le dernier jour --}}
                        @php $endPadding = 7 - ($endOfMonth->dayOfWeekIso); @endphp
                        @if($endPadding < 7)
                            @for($i = 0; $i < $endPadding; $i++)
                                <div class="p-2 min-h-[80px] bg-slate-50/50 border-t border-slate-50"></div>
                            @endfor
                        @endif
                    </div>
                </div>

                {{-- Légende --}}
                <div class="flex items-center justify-center gap-6 mb-6">
                    <div class="flex items-center gap-1.5"><div class="w-3 h-1.5 rounded-full bg-emerald-500"></div><span class="text-[8px] font-black text-slate-400 uppercase">≥80%</span></div>
                    <div class="flex items-center gap-1.5"><div class="w-3 h-1.5 rounded-full bg-amber-400"></div><span class="text-[8px] font-black text-slate-400 uppercase">40-79%</span></div>
                    <div class="flex items-center gap-1.5"><div class="w-3 h-1.5 rounded-full bg-red-400"></div><span class="text-[8px] font-black text-slate-400 uppercase">&lt;40%</span></div>
                    <div class="flex items-center gap-1.5"><div class="w-2 h-2 rounded-full bg-red-500"></div><span class="text-[8px] font-black text-slate-400 uppercase">{{ __("Retards") }}</span></div>
                </div>

            @else
            {{-- ═══ VUE JOUR ═══ --}}

            {{-- NAVIGATION DATES --}}
            <div class="flex items-center justify-between mb-5">
                <a href="{{ route('tasks.index', array_merge(['date' => $date->copy()->subDay()->toDateString()], $filterParams)) }}" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline border border-slate-200"><i class="fa-solid fa-chevron-left"></i></a>
                <div class="flex gap-2">
                    @for($i = -2; $i <= 2; $i++)
                        @php $d = $date->copy()->addDays($i); @endphp
                        <a href="{{ route('tasks.index', array_merge(['date' => $d->toDateString()], $filterParams)) }}"
                           @class(['px-4 py-2 rounded-xl text-[9px] font-black uppercase no-underline transition-all',
                               'bg-indigo-600 text-white shadow-lg' => $i === 0,
                               'bg-white text-slate-500 border border-slate-200 hover:bg-slate-50' => $i !== 0])>
                            {{ $d->translatedFormat('D d') }}
                        </a>
                    @endfor
                </div>
                <a href="{{ route('tasks.index', array_merge(['date' => $date->copy()->addDay()->toDateString()], $filterParams)) }}" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline border border-slate-200"><i class="fa-solid fa-chevron-right"></i></a>
            </div>

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Total</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['total'] }}</p>
                </div>
                <div class="bg-emerald-50 p-4 rounded-2xl border border-emerald-200 shadow-sm text-center">
                    <p class="text-[7px] font-black text-emerald-500 uppercase tracking-widest">Fait</p>
                    <p class="text-2xl font-black text-emerald-600">{{ $stats['done'] }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-amber-500 uppercase tracking-widest">À faire</p>
                    <p class="text-2xl font-black text-amber-600">{{ $stats['pending'] }}</p>
                </div>
                <div @class(['p-4 rounded-2xl border shadow-sm text-center',
                    'bg-red-50 border-red-200' => $stats['overdue'] > 0, 'bg-white border-slate-100' => $stats['overdue'] === 0])>
                    <p class="text-[7px] font-black text-red-500 uppercase tracking-widest">Retard</p>
                    <p class="text-2xl font-black {{ $stats['overdue'] > 0 ? 'text-red-600 animate-pulse' : 'text-slate-300' }}">{{ $stats['overdue'] }}</p>
                </div>
                <div class="bg-indigo-600 p-4 rounded-2xl shadow-lg text-center text-white">
                    <p class="text-[7px] font-black text-indigo-200 uppercase tracking-widest">Taux</p>
                    <p class="text-2xl font-black">{{ $stats['rate'] }}%</p>
                </div>
            </div>

            {{-- TÂCHES EN RETARD --}}
            @if($overdueTasks->count() > 0)
            <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-2xl">
                <p class="text-[9px] font-black text-red-600 uppercase tracking-widest mb-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i> {{ $overdueTasks->count() }} tâche(s) en retard</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($overdueTasks->take(5) as $ot)
                    <span class="text-[8px] font-black bg-red-100 text-red-700 px-2 py-1 rounded-lg">{{ $ot->title }} ({{ $ot->scheduled_date->format('d/m') }})</span>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
                {{-- LISTE DES TÂCHES --}}
                <div class="lg:col-span-3">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                        <div class="px-5 py-3 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                            <div class="flex gap-2">
                                @foreach(['all' => 'Toutes', 'pending' => 'À faire', 'done' => 'Faites'] as $f => $l)
                                <a href="{{ route('tasks.index', array_merge(['date' => $date->toDateString(), 'filter' => $f], $filterParams)) }}"
                                   @class(['px-3 py-1 rounded-lg text-[8px] font-black uppercase no-underline transition-all',
                                       'bg-indigo-100 text-indigo-600' => $filter === $f,
                                       'text-slate-400 hover:bg-slate-100' => $filter !== $f])>{{ $l }}</a>
                                @endforeach
                            </div>
                            <span class="text-[8px] font-black text-slate-400">{{ $tasks->count() }} tâches</span>
                        </div>

                        <div class="divide-y divide-slate-50">
                            @forelse($tasks as $task)
                            <div @class(['px-5 py-3 flex items-center gap-4 transition-all group',
                                'bg-emerald-50/30' => $task->status === 'fait',
                                'bg-red-50/30' => $task->status === 'en_retard',
                                'hover:bg-slate-50/50' => !in_array($task->status, ['fait', 'en_retard'])])>

                                {{-- COMPLÉTION RAPIDE --}}
                                @if($task->status !== 'fait')
                                <form method="POST" action="{{ route('tasks.complete', $task) }}">@csrf
                                    <button class="w-8 h-8 rounded-lg border-2 {{ $task->status === 'en_retard' ? 'border-red-300 hover:bg-red-500' : 'border-slate-200 hover:bg-emerald-500' }} hover:text-white flex items-center justify-center transition-all bg-transparent cursor-pointer">
                                        <i class="fa-solid fa-check text-[10px]"></i>
                                    </button>
                                </form>
                                @else
                                <div class="w-8 h-8 rounded-lg bg-emerald-500 flex items-center justify-center text-white shrink-0"><i class="fa-solid fa-check text-[10px]"></i></div>
                                @endif

                                {{-- INFO --}}
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        @if($task->template)<i class="fa-solid {{ $task->template->icon ?? 'fa-circle' }} text-{{ $task->template->color ?? 'slate' }}-400 text-[10px]"></i>@endif
                                        <p class="text-[10px] font-black text-slate-800 uppercase truncate {{ $task->status === 'fait' ? 'line-through text-slate-400' : '' }}">{{ $task->title }}</p>
                                        <span @class(['text-[7px] font-black uppercase px-1.5 py-0.5 rounded',
                                            'bg-red-100 text-red-600' => $task->priority === 'critique',
                                            'bg-amber-100 text-amber-600' => $task->priority === 'haute',
                                            'hidden' => in_array($task->priority, ['normale', 'basse'])])>{{ $task->priority }}</span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-0.5">
                                        @if($task->scheduled_time)<span class="text-[8px] text-slate-400"><i class="fa-solid fa-clock mr-0.5"></i> {{ \Carbon\Carbon::parse($task->scheduled_time)->format('H:i') }}</span>@endif
                                        @if($task->building)<span class="text-[8px] text-blue-400">{{ $task->building->name }}</span>@endif
                                        @if($task->plot_id && $task->plot)<span class="text-[8px] text-green-500"><i class="fa-solid fa-leaf mr-0.5"></i>{{ $task->plot->name }}</span>@endif
                                    </div>
                                </div>

                                {{-- ASSIGNÉ + ACTIONS --}}
                                <div class="text-right shrink-0 flex items-center gap-2">
                                    @if($task->employee)
                                        <span class="text-[8px] font-black text-slate-500 uppercase">{{ $task->employee->first_name }}</span>
                                    @else
                                        <form method="POST" action="{{ route('tasks.assign', $task) }}" class="inline">@csrf
                                            <select name="employee_id" onchange="this.form.submit()" class="text-[8px] bg-amber-50 border-none rounded-lg px-2 py-1 font-black text-amber-600 cursor-pointer outline-none">
                                                <option>Assigner...</option>
                                                @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->first_name }}</option>@endforeach
                                            </select>
                                        </form>
                                    @endif
                                    @if($task->status !== 'fait')
                                    <a href="{{ route('tasks.edit', $task) }}" class="w-6 h-6 rounded-md bg-slate-50 text-slate-300 hover:text-blue-500 hover:bg-blue-50 flex items-center justify-center no-underline transition-all" title="Modifier">
                                        <i class="fa-solid fa-pen text-[8px]"></i>
                                    </a>
                                    @endif
                                </div>
                            </div>
                            @empty
                            <div class="px-8 py-12 text-center">
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest mb-3">Aucune tâche pour cette date</p>
                                @can('annuaire.M')
                                <form method="POST" action="{{ route('tasks.generate') }}">@csrf
                                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                    <button class="bg-indigo-600 text-white px-6 py-3 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-indigo-700 border-none cursor-pointer italic"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Générer automatiquement</button>
                                </form>
                                @endcan
                            </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- SIDEBAR : AJOUT MANUEL --}}
                <div class="space-y-5">
                    <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm text-left">
                        <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-plus text-indigo-500"></i> Tâche manuelle
                        </h4>
                        <form method="POST" action="{{ route('tasks.store') }}" class="space-y-3">
                            @csrf
                            <input type="text" name="title" required placeholder="Titre de la tâche..." class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                            <select name="category" required class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black uppercase shadow-inner outline-none">
                                <option value="alimentation">🌾 Alimentation</option>
                                <option value="collecte">🥚 Collecte</option>
                                <option value="controle">📋 Contrôle</option>
                                <option value="nettoyage">🧹 Nettoyage</option>
                                <option value="sante">💉 Santé</option>
                                <option value="maintenance">🔧 Maintenance</option>
                            </select>
                            <select name="employee_id" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                                <option value="">Employé...</option>
                                @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->first_name }} {{ $e->last_name }}</option>@endforeach
                            </select>
                            <select name="building_id" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                                <option value="">Bâtiment...</option>
                                @foreach($buildings as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                            </select>
                            <input type="hidden" name="scheduled_date" value="{{ $date->toDateString() }}">
                            <input type="time" name="scheduled_time" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-xs font-black shadow-inner outline-none">
                            <select name="priority" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black uppercase shadow-inner outline-none">
                                <option value="normale">Normale</option>
                                <option value="haute">Haute</option>
                                <option value="critique">Critique</option>
                                <option value="basse">Basse</option>
                            </select>
                            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-indigo-600 border-none cursor-pointer italic">Créer</button>
                        </form>
                    </div>

                    {{-- RÉSUMÉ PAR CATÉGORIE --}}
                    @if($stats['total'] > 0)
                    <div class="bg-slate-900 p-5 rounded-2xl text-white">
                        <h4 class="text-[8px] font-black text-slate-500 uppercase tracking-widest mb-3">Par catégorie</h4>
                        @foreach($stats['by_category'] as $cat => $count)
                        <div class="flex justify-between mb-1.5">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ $cat }}</span>
                            <span class="text-sm font-black text-white">{{ $count }}</span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @endif {{-- fin vue jour/mois --}}
    </div>

    <script>function taskBoard() { return {} }</script>
</x-app-layout>
