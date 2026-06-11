<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <div class="w-14 h-14 bg-slate-900 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                <i class="fa-solid fa-user-gear text-xl"></i>
            </div>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Mon Espace</h2>
                <p class="text-[10px] font-black text-blue-600 uppercase tracking-[0.2em] mt-2 italic">
                    Bonjour {{ $user->name }} · {{ $user->userRole->display_name ?? $user->userRole->name ?? 'Sans rôle' }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            {{-- MODULES ACCESSIBLES --}}
            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-5 italic">
                    <span class="w-6 h-[2px] bg-blue-500 mr-2"></span> Mes accès
                </h3>
                @php $modules = $user->getAccessibleModules(); @endphp
                @if($modules->isEmpty())
                    <p class="text-[10px] text-slate-400 uppercase tracking-widest">Aucun module accessible pour l'instant.</p>
                @else
                    <div class="flex flex-wrap gap-3">
                        @foreach($modules as $m)
                            <span class="px-4 py-2 rounded-xl bg-slate-50 text-slate-600 text-[9px] font-black uppercase tracking-widest border border-slate-100">
                                <i class="fa-solid {{ $m->icon ?? 'fa-cube' }} mr-1 text-{{ $m->color ?? 'slate' }}-500"></i> {{ $m->name }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($employee)
                {{-- FICHE PERSONNELLE --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                        <div class="w-24 h-24 mx-auto mb-4 rounded-2xl overflow-hidden bg-slate-900 flex items-center justify-center">
                            @if($employee->photo_path)
                                <img src="{{ media_url($employee->photo_path) }}" class="w-full h-full object-cover">
                            @else
                                <span class="text-3xl font-black text-blue-400 uppercase">{{ substr($employee->first_name, 0, 1) }}</span>
                            @endif
                        </div>
                        <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter">{{ $employee->first_name }} {{ $employee->last_name }}</h2>
                        <p class="text-[8px] font-black text-blue-500 uppercase tracking-widest mt-1">{{ $employee->employee_id }}</p>
                        <span class="inline-block mt-3 px-4 py-1.5 rounded-xl bg-slate-100 text-slate-700 font-black uppercase text-[8px] tracking-widest">{{ $employee->job_title }}</span>
                    </div>

                    <div class="md:col-span-2 bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-5 italic">
                            <span class="w-6 h-[2px] bg-emerald-500 mr-2"></span> Mes informations
                        </h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Département</p>
                                <p class="text-sm font-black text-slate-800 uppercase mt-1">{{ $employee->department ?? 'Général' }}</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Contrat</p>
                                <p class="text-sm font-black text-slate-800 uppercase mt-1">{{ $employee->contract_type ?? 'CDI' }}</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Téléphone</p>
                                <p class="text-sm font-black text-slate-800 mt-1">{{ $employee->phone ?? '—' }}</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl">
                                <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Bâtiment assigné</p>
                                <p class="text-sm font-black text-blue-600 mt-1">{{ $employee->assignedBuilding?->name ?? '—' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- MES LOTS --}}
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-5 italic">
                        <span class="w-6 h-[2px] bg-amber-500 mr-2"></span> Mes lots sous responsabilité
                    </h3>
                    @if($batches->isEmpty())
                        <p class="text-[9px] text-slate-300 text-center py-6 uppercase italic tracking-widest">Aucun lot actif assigné</p>
                    @else
                        <div class="space-y-2">
                            @foreach($batches as $batch)
                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <div class="flex items-center gap-3">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <div>
                                        <p class="text-[11px] font-black text-slate-800 uppercase">{{ $batch->code }}</p>
                                        <p class="text-[7px] text-slate-400 uppercase tracking-widest">{{ $batch->current_quantity }} sujets · {{ $batch->type }}</p>
                                    </div>
                                </div>
                                @can('elevage.L')
                                <a href="{{ route('batches.show', $batch) }}" class="w-7 h-7 rounded-lg bg-white text-blue-400 hover:text-blue-600 flex items-center justify-center no-underline border border-slate-100" title="Voir le lot">
                                    <i class="fa-solid fa-eye text-[9px]"></i>
                                </a>
                                @endcan
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- MES FICHES DE PAIE --}}
                @if($payslips->isNotEmpty())
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-5 italic">
                        <span class="w-6 h-[2px] bg-emerald-500 mr-2"></span> Mes dernières fiches de paie
                    </h3>
                    <div class="space-y-2">
                        @foreach($payslips as $slip)
                        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                            <div class="flex items-center gap-3">
                                <span @class(['w-2 h-2 rounded-full', 'bg-emerald-500' => $slip->payment_status === 'paye', 'bg-amber-400' => $slip->payment_status !== 'paye'])></span>
                                <p class="text-[10px] font-black text-slate-800 uppercase">{{ $slip->period->label ?? '—' }}</p>
                            </div>
                            <span class="text-xs font-black {{ $slip->payment_status === 'paye' ? 'text-emerald-600' : 'text-slate-600' }}">{{ number_format($slip->net_salary, 0, ',', ' ') }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            @else
                <div class="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <i class="fa-solid fa-circle-info text-slate-200 text-3xl mb-4 block"></i>
                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">Ce compte n'est rattaché à aucune fiche employé.</p>
                </div>
            @endif

            <div class="text-center">
                <a href="{{ route('profile.edit') }}" class="text-[9px] font-black text-slate-400 uppercase tracking-widest hover:text-slate-700 no-underline italic">
                    <i class="fa-solid fa-gear mr-1"></i> Gérer mon profil & mot de passe
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
