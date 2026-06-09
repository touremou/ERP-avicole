<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-clipboard-check text-lg"></i></div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Planning Opérationnel</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ $date->translatedFormat('l d F Y') }}</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('tasks.templates') }}" class="bg-white border border-slate-200 px-4 py-2 rounded-xl text-[9px] font-black uppercase italic text-slate-600 hover:bg-slate-50 no-underline"><i class="fa-solid fa-gear text-slate-400 mr-1"></i> Templates</a>
                <form method="POST" action="{{ route('tasks.generate') }}">@csrf
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <button class="bg-indigo-600 text-white px-5 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-indigo-700 border-none cursor-pointer shadow-lg italic"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Générer</button>
                </form>
            </div>
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

            {{-- NAVIGATION DATES --}}
            <div class="flex items-center justify-between mb-5">
                <a href="{{ route('tasks.index', ['date' => $date->copy()->subDay()->toDateString()]) }}" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline border border-slate-200"><i class="fa-solid fa-chevron-left"></i></a>
                <div class="flex gap-2">
                    @for($i = -2; $i <= 2; $i++)
                        @php $d = $date->copy()->addDays($i); @endphp
                        <a href="{{ route('tasks.index', ['date' => $d->toDateString()]) }}"
                           @class(['px-4 py-2 rounded-xl text-[9px] font-black uppercase no-underline transition-all',
                               'bg-indigo-600 text-white shadow-lg' => $i === 0,
                               'bg-white text-slate-500 border border-slate-200 hover:bg-slate-50' => $i !== 0])>
                            {{ $d->translatedFormat('D d') }}
                        </a>
                    @endfor
                </div>
                <a href="{{ route('tasks.index', ['date' => $date->copy()->addDay()->toDateString()]) }}" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline border border-slate-200"><i class="fa-solid fa-chevron-right"></i></a>
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
                                <a href="{{ route('tasks.index', ['date' => $date->toDateString(), 'filter' => $f]) }}"
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
                                <form method="POST" action="{{ route('tasks.generate') }}">@csrf
                                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                    <button class="bg-indigo-600 text-white px-6 py-3 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-indigo-700 border-none cursor-pointer italic"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Générer automatiquement</button>
                                </form>
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
    </div>

    <script>function taskBoard() { return {} }</script>
</x-app-layout>
