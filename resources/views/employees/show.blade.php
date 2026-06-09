<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-4 text-left">
                <a href="{{ route('employees.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                    <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform text-xs"></i>
                    <span class="text-[10px] font-black uppercase italic tracking-widest">Retour</span>
                </a>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ $employee->first_name }} {{ $employee->last_name }}</h2>
                    <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mt-1 italic">{{ $employee->employee_id }} · {{ $employee->job_title }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                {{-- MODULE PLANNING : Accès aux tâches --}}
                @can('planning.L')
                <a href="{{ route('tasks.index', ['employee' => $employee->id]) }}" class="bg-white border border-indigo-200 px-4 py-2 rounded-xl text-[9px] font-black uppercase text-indigo-600 hover:bg-indigo-900 hover:text-white transition shadow-sm italic no-underline">
                    <i class="fas fa-clipboard-check mr-1"></i> Tâches
                </a>
                @endcan

                {{-- MODULE RH : Accès à l'historique de paie --}}
                @can('rh.L')
                <a href="{{ route('payroll.employee-history', $employee) }}" class="bg-blue-50 border border-blue-100 px-4 py-2 rounded-xl text-[9px] font-black uppercase text-blue-600 hover:bg-blue-600 hover:text-white transition shadow-sm italic no-underline">
                    <i class="fas fa-money-bill-wave mr-1"></i> Paie
                </a>
                @endcan

                {{-- MODULE ANNUAIRE : Modification de la fiche --}}
                @can('annuaire.M')
                <a href="{{ route('employees.edit', $employee) }}" class="bg-white border border-slate-200 px-4 py-2 rounded-xl text-[9px] font-black uppercase text-slate-600 hover:bg-slate-900 hover:text-white transition shadow-sm italic no-underline">
                    <i class="fas fa-pen mr-1"></i> Modifier
                </a>
                @endcan
                @can('annuaire.S')
                @php $isLocked = $employee->batches()->where('status', 'Actif')->exists(); @endphp
                @if(!$isLocked)
                <form action="{{ route('employees.destroy', $employee) }}" method="POST" onsubmit="return confirm('Archiver cet employé ?')">
                    @csrf @method('DELETE')
                    <button class="bg-red-50 border border-red-100 px-4 py-2 rounded-xl text-[9px] font-black uppercase text-red-500 hover:bg-red-600 hover:text-white transition shadow-sm italic border-none cursor-pointer">
                        <i class="fas fa-archive mr-1"></i> Archiver
                    </button>
                </form>
                @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- COLONNE GAUCHE : IDENTITÉ --}}
                <div class="space-y-5 text-left">
                    {{-- PHOTO + NOM --}}
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 text-center">
                        <div class="w-28 h-28 mx-auto mb-4 rounded-2xl overflow-hidden bg-slate-100 border-4 border-white shadow-xl relative">
                            @if($employee->photo_path)
                                <img src="{{ asset('storage/' . $employee->photo_path) }}"
                                     class="w-full h-full object-cover"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                <div class="w-full h-full bg-slate-900 items-center justify-center hidden absolute inset-0">
                                    <span class="text-4xl font-black text-blue-400 uppercase">{{ substr($employee->first_name, 0, 1) }}</span>
                                </div>
                            @else
                                <div class="w-full h-full bg-slate-900 flex items-center justify-center">
                                    <span class="text-4xl font-black text-blue-400 uppercase">{{ substr($employee->first_name, 0, 1) }}</span>
                                </div>
                            @endif
                        </div>
                        <h2 class="text-xl font-black text-slate-800 tracking-tighter uppercase italic">{{ $employee->first_name }} {{ $employee->last_name }}</h2>
                        <span class="inline-block mt-2 px-4 py-1.5 rounded-xl bg-slate-900 text-white font-black uppercase text-[8px] tracking-widest italic">{{ $employee->job_title }}</span>
                    </div>

                    {{-- STATUT --}}
                    @php
                        $statusConfig = match($employee->status) {
                            'Actif' => ['from' => 'emerald-500', 'to' => 'emerald-600', 'icon' => 'fa-check-double', 'shadow' => 'emerald'],
                            'Congé' => ['from' => 'blue-500', 'to' => 'blue-600', 'icon' => 'fa-umbrella-beach', 'shadow' => 'blue'],
                            'Suspendu' => ['from' => 'orange-500', 'to' => 'orange-600', 'icon' => 'fa-user-slash', 'shadow' => 'orange'],
                            default => ['from' => 'slate-400', 'to' => 'slate-500', 'icon' => 'fa-circle-question', 'shadow' => 'slate'],
                        };
                    @endphp
                    <div class="bg-gradient-to-br from-{{ $statusConfig['from'] }} to-{{ $statusConfig['to'] }} p-5 rounded-2xl shadow-lg shadow-{{ $statusConfig['shadow'] }}-500/20 text-white flex items-center justify-between">
                        <div>
                            <p class="text-[8px] font-black uppercase opacity-70 tracking-widest">Statut</p>
                            <p class="text-2xl font-black italic tracking-tighter uppercase">{{ $employee->status }}</p>
                        </div>
                        <i class="fas {{ $statusConfig['icon'] }} text-3xl opacity-30"></i>
                    </div>

                    {{-- INFOS CONTACT --}}
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm divide-y divide-slate-50">
                        <div class="p-4 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400"><i class="fas fa-phone text-xs"></i></div>
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Téléphone</p>
                                <p class="text-xs font-black text-slate-700">{{ $employee->phone }}</p>
                            </div>
                        </div>
                        @if($employee->orange_money_number ?? false)
                        <div class="p-4 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-orange-50 flex items-center justify-center text-orange-500"><i class="fas fa-mobile-alt text-xs"></i></div>
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Orange Money</p>
                                <p class="text-xs font-black text-orange-600">{{ $employee->orange_money_number }}</p>
                            </div>
                        </div>
                        @endif
                        <div class="p-4 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400"><i class="fas fa-id-badge text-xs"></i></div>
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Matricule</p>
                                <p class="text-xs font-mono font-black text-slate-700 uppercase">{{ $employee->employee_id }}</p>
                            </div>
                        </div>
                        @if($employee->assigned_building_id ?? false)
                        <div class="p-4 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center text-blue-500"><i class="fas fa-warehouse text-xs"></i></div>
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Bâtiment assigné</p>
                                <p class="text-xs font-black text-blue-600">{{ $employee->assignedBuilding?->name ?? '—' }}</p>
                            </div>
                        </div>
                        @endif
                    </div>

                    {{-- SOLDE CONGÉS --}}
                    @if(\Illuminate\Support\Facades\Schema::hasColumn('employees', 'annual_leave_balance'))
                    <div class="bg-blue-50 p-5 rounded-2xl border border-blue-100 text-center">
                        <p class="text-[8px] font-black text-blue-500 uppercase tracking-widest">Solde congés annuels</p>
                        <p class="text-3xl font-black text-blue-600 mt-1">{{ $employee->annual_leave_balance ?? 30 }} <small class="text-xs text-blue-400">jours</small></p>
                    </div>
                    @endif
                </div>

                {{-- COLONNE DROITE : DÉTAILS + HISTORIQUE --}}
                <div class="lg:col-span-2 space-y-5 text-left">

                    {{-- CONTRAT --}}
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                        <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-5 italic">
                            <span class="w-6 h-[2px] bg-blue-500 mr-2"></span> Contrat & Rémunération
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Salaire base</p>
                                <p class="text-xl font-black text-slate-900 tracking-tighter mt-1">{{ number_format($employee->salary, 0, ',', '.') }}</p>
                                <p class="text-[7px] font-black text-slate-400 uppercase">{{ setting('general.currency', 'GNF') }} / mois</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Contrat</p>
                                <p class="text-sm font-black text-slate-800 uppercase mt-1">{{ $employee->contract_type ?? 'CDI' }}</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Département</p>
                                <p class="text-sm font-black text-slate-800 uppercase mt-1">{{ $employee->department ?? 'Général' }}</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Embauche</p>
                                <p class="text-sm font-black text-slate-800 mt-1">{{ $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('d/m/Y') : '—' }}</p>
                                @if($employee->hire_date)
                                <p class="text-[7px] text-blue-500 font-black mt-0.5">{{ \Carbon\Carbon::parse($employee->hire_date)->diffForHumans(null, true) }}</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- DOCUMENTS --}}
                    @if($employee->cv_path)
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
                        <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-4 italic">
                            <span class="w-6 h-[2px] bg-emerald-500 mr-2"></span> Documents
                        </h3>
                        <a href="{{ asset('storage/' . $employee->cv_path) }}" target="_blank" class="flex items-center p-4 bg-slate-50 border border-slate-100 rounded-xl hover:bg-slate-900 hover:text-white transition-all group no-underline text-slate-700">
                            <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center text-blue-600 mr-4 group-hover:bg-blue-600 group-hover:text-white transition-all shadow-sm">
                                <i class="fas fa-file-pdf text-lg"></i>
                            </div>
                            <div>
                                <p class="text-[8px] font-black uppercase opacity-50 tracking-widest">Dossier PDF</p>
                                <p class="text-xs font-black uppercase tracking-tighter">Contrat / CV</p>
                            </div>
                        </a>
                    </div>
                    @endif

                    {{-- HISTORIQUE PAIE --}}
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center italic">
                                <span class="w-6 h-[2px] bg-emerald-500 mr-2"></span> Historique Paie
                            </h3>
                            <a href="{{ route('payroll.employee-history', $employee) }}" class="text-[8px] font-black text-blue-500 uppercase tracking-widest hover:text-blue-700 no-underline">Voir tout →</a>
                        </div>

                        @php
                            $recentPayslips = \App\Models\Payslip::where('employee_id', $employee->id)
                                ->with('period')->latest()->limit(4)->get();
                        @endphp

                        @if($recentPayslips->count() > 0)
                        <div class="space-y-2">
                            @foreach($recentPayslips as $slip)
                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <div class="flex items-center gap-3">
                                    <span @class(['w-2 h-2 rounded-full', 'bg-emerald-500' => $slip->payment_status === 'paye', 'bg-amber-400' => $slip->payment_status !== 'paye'])></span>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-800 uppercase">{{ $slip->period->label ?? '—' }}</p>
                                        <p class="text-[7px] text-slate-400">{{ $slip->days_worked }}j · {{ $slip->payment_status === 'paye' ? 'Payé' : 'En attente' }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-black {{ $slip->payment_status === 'paye' ? 'text-emerald-600' : 'text-slate-600' }}">{{ number_format($slip->net_salary, 0, ',', '.') }}</span>
                                    <a href="{{ route('payroll.print', ['payslip' => $slip, 'type' => $slip->payment_status === 'paye' ? 'fiche' : 'bon']) }}" target="_blank"
                                       class="w-7 h-7 rounded-lg bg-white text-blue-400 hover:text-blue-600 flex items-center justify-center no-underline border border-slate-100" title="Imprimer">
                                        <i class="fa-solid fa-print text-[9px]"></i>
                                    </a>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-[9px] text-slate-300 text-center py-6 uppercase italic tracking-widest">Aucune fiche de paie</p>
                        @endif
                    </div>

                    {{-- CONGÉS ACTIFS --}}
                    @php
                        $activeLeaves = \App\Models\EmployeeLeave::where('employee_id', $employee->id)
                            ->whereIn('status', ['approuve', 'en_cours'])
                            ->latest()->limit(3)->get();
                        $pastLeaves = \App\Models\EmployeeLeave::where('employee_id', $employee->id)
                            ->where('status', 'termine')
                            ->latest()->limit(3)->get();
                    @endphp

                    @if($activeLeaves->count() > 0 || $pastLeaves->count() > 0)
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                        <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-4 italic">
                            <span class="w-6 h-[2px] bg-amber-500 mr-2"></span> Congés & Absences
                        </h3>

                        @foreach($activeLeaves as $l)
                        <div class="flex items-center justify-between p-3 bg-amber-50 rounded-xl mb-2 border border-amber-100">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                <span class="text-[9px] font-black text-amber-700 uppercase">{{ $l->type_label }}</span>
                            </div>
                            <span class="text-[9px] font-black text-slate-600">{{ $l->start_date->format('d/m') }} → {{ $l->end_date->format('d/m') }} ({{ $l->days_count }}j)</span>
                        </div>
                        @endforeach

                        @foreach($pastLeaves as $l)
                        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl mb-2">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                                <span class="text-[9px] font-black text-slate-500 uppercase">{{ $l->type_label }}</span>
                            </div>
                            <span class="text-[9px] font-black text-slate-400">{{ $l->start_date->format('d/m') }} → {{ $l->end_date->format('d/m') }} ({{ $l->days_count }}j)</span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
