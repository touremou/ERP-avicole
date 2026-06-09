<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-4 italic font-black uppercase tracking-tighter">
                <div class="w-12 h-12 bg-slate-900 rounded-2xl flex items-center justify-center text-amber-500 shadow-xl">
                    <i class="fa-solid fa-gears"></i>
                </div>
                <div class="text-left">
                    <h2 class="text-2xl text-slate-800 leading-none">Parc Machines</h2>
                    <p class="text-[10px] text-slate-400 mt-1 tracking-[0.3em]">Maintenance & Performance</p>
                </div>
            </div>

            {{-- Permission C : Ajout de machine --}}
            @can('provenderie.C')
            <button onclick="openModal('modal-add')" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] text-[10px] font-black uppercase italic tracking-widest shadow-2xl hover:bg-emerald-500 transition-all active:scale-95">
                <i class="fa-solid fa-plus mr-2 text-emerald-400"></i> Nouvelle Machine
            </button>
            @endcan
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- BLOC ERREURS --}}
            @if(session('error'))
                <div class="mb-8 p-4 bg-red-600 text-white rounded-2xl shadow-lg text-[10px] font-black uppercase italic flex items-center gap-3 animate-pulse">
                    <i class="fa-solid fa-circle-exclamation text-lg"></i>
                    {{ session('error') }}
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($machines as $machine)
                <div @class([
                    'bg-white rounded-[3.5rem] border shadow-sm p-8 relative overflow-hidden group transition-all hover:shadow-2xl',
                    'border-slate-100' => $machine->status != 'Désactivé',
                    'border-slate-300 bg-slate-50 opacity-80' => $machine->status == 'Désactivé'
                ])>
                    
                    {{-- MENU OPTIONS --}}
                    <div class="absolute top-8 left-8 flex gap-2 opacity-0 group-hover:opacity-100 transition-all transform -translate-x-2 group-hover:translate-x-0">
                        @if($machine->status != 'Désactivé')
                            {{-- Permission M : Edition --}}
                            @can('provenderie.M')
                            <button onclick='openEditModal(@json($machine))' class="w-8 h-8 bg-slate-900 text-white rounded-xl flex items-center justify-center hover:bg-blue-500 transition-colors shadow-lg">
                                <i class="fa-solid fa-pen text-[10px]"></i>
                            </button>
                            @endcan

                            {{-- Permission S : Suppression --}}
                            @can('provenderie.S')
                                @if($machine->hasProductionHistory())
                                    <div title="Historique existant : Désactivation conseillée" 
                                         class="w-8 h-8 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center cursor-help border border-slate-200">
                                        <i class="fa-solid fa-lock text-[10px]"></i>
                                    </div>
                                @else
                                    <form action="{{ route('machines.destroy', $machine->id) }}" method="POST" onsubmit="return confirm('Supprimer définitivement ?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-8 h-8 bg-white text-red-500 border border-red-100 rounded-xl flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors shadow-lg">
                                            <i class="fa-solid fa-trash text-[10px]"></i>
                                        </button>
                                    </form>
                                @endif
                            @endcan
                        @else
                            <div class="px-3 py-1 bg-slate-200 text-slate-500 rounded-lg text-[7px] font-black uppercase italic border border-slate-300 shadow-inner">
                                <i class="fa-solid fa-lock mr-1"></i> Fiche Archivée
                            </div>
                        @endif
                    </div>

                    {{-- Status Badge --}}
                    <div class="absolute top-8 right-8">
                        <span @class([
                            'px-4 py-1 rounded-full text-[8px] font-black uppercase tracking-widest italic shadow-sm',
                            'bg-emerald-100 text-emerald-600 border border-emerald-200' => $machine->status == 'Opérationnel',
                            'bg-amber-100 text-amber-600 border border-amber-200' => $machine->status == 'Maintenance',
                            'bg-red-100 text-red-600 border border-red-200' => $machine->status == 'En Panne',
                            'bg-slate-200 text-slate-500' => $machine->status == 'Désactivé'
                        ])>
                            ● {{ $machine->status }}
                        </span>
                    </div>

                    <div @class([
                        'w-16 h-16 rounded-3xl flex items-center justify-center mb-6 mt-4 shadow-inner transition-colors',
                        'bg-slate-50 text-slate-400 group-hover:text-slate-900' => $machine->status != 'Désactivé',
                        'bg-slate-200 text-slate-500' => $machine->status == 'Désactivé'
                    ])>
                        <i class="fa-solid fa-screwdriver-wrench text-xl"></i>
                    </div>

                    <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-1">{{ $machine->name }}</h3>
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-8 italic">{{ $machine->type }} • {{ number_format($machine->capacity_per_hour, 1) }} kg/h</p>

                    {{-- Jauge de maintenance --}}
                    
                    <div @class(['space-y-4 mb-8', 'grayscale opacity-50' => $machine->status == 'Désactivé'])>
                        @php
                            $progress = $machine->maintenance_progress;
                            $barColor = 'bg-slate-900';
                            $statusLabel = 'SANTÉ : OPTIMALE';
                            $statusClass = 'text-emerald-500';

                            if ($machine->needs_maintenance) {
                                $barColor = 'bg-red-500 animate-pulse';
                                $statusLabel = 'CRITIQUE : ARRÊT REQUIS';
                                $statusClass = 'text-red-600';
                            } elseif ($progress >= 75) {
                                $barColor = 'bg-amber-500';
                                $statusLabel = 'PRÉVENTIF : RÉVISION PROCHE';
                                $statusClass = 'text-amber-600';
                            }
                        @endphp

                        <div class="flex justify-between items-end">
                            <div class="text-left">
                                <p class="text-[10px] font-black text-slate-800 uppercase italic leading-none tracking-tighter">Cycle de Vie</p>
                                <p class="text-[8px] {{ $statusClass }} font-black uppercase mt-1 italic tracking-widest">{{ $statusLabel }}</p>
                            </div>
                            <p class="text-lg font-black italic leading-none {{ $progress >= 90 ? 'text-red-600' : '' }}">{{ $progress }}%</p>
                        </div>

                        <div class="w-full bg-slate-100 h-5 rounded-full overflow-hidden p-1 shadow-inner border border-slate-50">
                            <div class="h-full rounded-full transition-all duration-1000 {{ $barColor }}" style="width: {{ min($progress, 100) }}%"></div>
                        </div>

                        <div class="flex justify-between items-center text-[8px] text-slate-400 uppercase italic">
                            <p>Utilisation : <strong>{{ number_format($machine->total_hours_run, 2) }}h</strong></p>
                            <p>Limite : {{ $machine->maintenance_interval_hours }}h</p>
                        </div>
                    </div>

                    {{-- ACTIONS --}}
                    <div class="grid grid-cols-1 gap-3 pt-6 border-t border-slate-50">
                        <div class="grid grid-cols-2 gap-3">
                            {{-- Permission M : Révision --}}
                            @can('provenderie.M')
                            <button type="button" 
                                    @disabled($machine->status == 'Désactivé')
                                    onclick="openMaintenanceModal({{ $machine->id }}, '{{ addslashes($machine->name) }}', '{{ number_format($machine->total_hours_run, 2) }}')" 
                                    @class([
                                        'w-full py-4 rounded-2xl text-[9px] font-black uppercase italic tracking-widest transition-all shadow-lg',
                                        'bg-slate-900 text-white hover:bg-emerald-500' => $machine->status != 'Désactivé',
                                        'bg-slate-100 text-slate-300 cursor-not-allowed shadow-none' => $machine->status == 'Désactivé'
                                    ])>
                                <i class="fa-solid fa-screwdriver-wrench mr-1"></i> Révision
                            </button>

                            <form action="{{ route('machines.status', $machine->id) }}" method="POST">
                                @csrf @method('PUT')
                                <input type="hidden" name="status" value="{{ $machine->status === 'En Panne' ? 'Opérationnel' : 'En Panne' }}">
                                <button type="submit" 
                                        @disabled($machine->status == 'Désactivé')
                                        @class([
                                            'w-full py-4 rounded-2xl text-[9px] font-black uppercase italic tracking-widest transition-all shadow-lg',
                                            'bg-emerald-500 text-white' => $machine->status == 'En Panne',
                                            'bg-slate-50 text-slate-400 hover:bg-red-500 hover:text-white' => $machine->status != 'En Panne' && $machine->status != 'Désactivé',
                                            'bg-slate-100 text-slate-200 cursor-not-allowed shadow-none' => $machine->status == 'Désactivé'
                                        ])>
                                    {{ $machine->status == 'En Panne' ? 'Réactiver' : 'Signaler Panne' }}
                                </button>
                            </form>
                            @endcan
                        </div>

                        {{-- Permission S : Désactivation --}}
                        @can('provenderie.S')
                        <form action="{{ route('machines.status', $machine->id) }}" method="POST">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="{{ $machine->status === 'Désactivé' ? 'Opérationnel' : 'Désactivé' }}">
                            <button type="submit" @class([
                                'w-full py-3 rounded-xl text-[8px] font-black uppercase italic tracking-[0.2em] transition-all border',
                                'bg-emerald-50 text-emerald-600 border-emerald-100 hover:bg-emerald-500 hover:text-white' => $machine->status == 'Désactivé',
                                'bg-white text-slate-400 border-slate-100 hover:bg-slate-900 hover:text-white' => $machine->status != 'Désactivé'
                            ])>
                                <i class="fa-solid {{ $machine->status == 'Désactivé' ? 'fa-power-off' : 'fa-ban' }} mr-2"></i>
                                {{ $machine->status == 'Désactivé' ? 'Réactiver la machine' : 'Mise au rebut (Désactiver)' }}
                            </button>
                        </form>
                        @endcan
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- MODALES (AJOUT, EDIT, MAINTENANCE) : Protégées par les permissions correspondantes --}}
    {{-- MODALE AJOUTER --}}
     @can('provenderie.C')
    <div id="modal-add" class="fixed inset-0 bg-slate-900/90 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] p-10 max-w-lg w-full shadow-2xl overflow-hidden text-left">
            <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8">Nouvelle Machine</h3>
            <form action="{{ route('machines.store') }}" method="POST" class="space-y-6">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Nom de la machine</label>
                        <input type="text" name="name" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Type</label>
                        <input type="text" name="type" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Capacité (kg/h)</label>
                        <input type="number" step="0.1" min="0" placeholder="0.0" name="capacity_per_hour" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div class="col-span-2">
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Intervalle Maintenance (Heures)</label>
                        <input type="number" name="maintenance_interval_hours" value="500" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500">
                    </div>
                </div>
                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeModal('modal-add')" class="flex-1 py-4 text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition-colors">Annuler</button>
                    <button type="submit" class="flex-1 bg-slate-900 text-white py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-500 shadow-lg transition-all">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
    @endcan

    {{-- MODALE MODIFIER --}}
     @can('provenderie.M')
    <div id="modal-edit" class="fixed inset-0 bg-slate-900/90 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] p-10 max-w-lg w-full shadow-2xl overflow-hidden text-left">
            <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8">Modifier Machine</h3>
            <form id="form-edit-machine" method="POST" class="space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Nom de la machine</label>
                        <input type="text" name="name" id="edit-name" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Type</label>
                        <input type="text" name="type" id="edit-type" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Capacité (kg/h)</label>
                        <input type="number" step="0.1" min="0" placeholder="0.0" name="capacity_per_hour" id="edit-capacity" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Intervalle Maintenance (Heures)</label>
                        <input type="number" min="0" placeholder="0" name="maintenance_interval_hours" id="edit-interval" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeModal('modal-edit')" class="flex-1 py-4 text-[10px] font-black uppercase text-slate-400">Annuler</button>
                    <button type="submit" class="flex-1 bg-slate-900 text-white py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-500 shadow-lg transition-all">Mettre à jour</button>
                </div>
            </form>
        </div>
    </div>
    @endcan

    {{-- MODALE MAINTENANCE --}}
     @can('provenderie.M')
    <div id="modal-maintenance" class="fixed inset-0 bg-slate-900/90 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] p-10 max-w-lg w-full shadow-2xl text-left">
            <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-2">Clôturer Révision</h3>
            <p id="maint-machine-name" class="text-[10px] font-bold text-emerald-500 uppercase mb-8 italic tracking-widest"></p>
            <form id="form-maintenance" method="POST" class="space-y-6">
                @csrf @method('PUT')
                <div>
                    <label class="text-[10px] uppercase tracking-widest text-slate-400 font-black italic">Rapport d'intervention</label>
                    <textarea name="description" required placeholder="Détaillez les travaux effectués..." class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-900 focus:ring-2 focus:ring-emerald-500 min-h-[100px]"></textarea>
                </div>
                <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100 flex items-start gap-3">
                    <i class="fa-solid fa-circle-info text-emerald-500 mt-1"></i>
                    <p class="text-[9px] text-emerald-700 leading-tight italic">
                        Note : Cette action réinitialise le compteur de <strong id="maint-hours-display">0</strong>h à 0h.
                    </p>
                </div>
                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeModal('modal-maintenance')" class="flex-1 py-4 text-[10px] font-black uppercase text-slate-400">Annuler</button>
                    <button type="submit" class="flex-1 bg-slate-900 text-white py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-500 shadow-xl">Valider & Reset</button>
                </div>
            </form>
        </div>
    </div>
    @endcan

    <script>
        function openModal(id) { 
            document.getElementById(id).classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) { 
            document.getElementById(id).classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function openEditModal(machine) {
            const form = document.getElementById('form-edit-machine');
            let url = "{{ route('machines.update', ':id') }}";
            form.action = url.replace(':id', machine.id);

            document.getElementById('edit-name').value = machine.name;
            document.getElementById('edit-type').value = machine.type;
            document.getElementById('edit-capacity').value = machine.capacity_per_hour;
            document.getElementById('edit-interval').value = machine.maintenance_interval_hours;
            
            openModal('modal-edit');
        }

        function openMaintenanceModal(id, name, hours) {
            const form = document.getElementById('form-maintenance');
            const nameDisplay = document.getElementById('maint-machine-name');
            const hoursDisplay = document.getElementById('maint-hours-display');
            
            let url = "{{ route('machines.reset', ':id') }}";
            form.action = url.replace(':id', id);
            
            if(nameDisplay) nameDisplay.innerText = `MACHINE : ${name}`;
            if(hoursDisplay) hoursDisplay.innerText = hours;
            
            openModal('modal-maintenance');
        }

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal('modal-add'); 
                closeModal('modal-edit'); 
                closeModal('modal-maintenance');
            }
        });
    </script>
    {{-- ... (Scripts JS identiques à l'original pour la gestion des modals) --}}
</x-app-layout>