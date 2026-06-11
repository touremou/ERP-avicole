<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <a href="{{ route('payroll.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Congés & Absences</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">Planning RH</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- KPI --}}
            <div class="grid grid-cols-3 gap-3 mb-6">
                <div @class(['p-4 rounded-2xl border shadow-sm text-center', 'bg-amber-50 border-amber-200' => $kpi['pending'] > 0, 'bg-white border-slate-100' => $kpi['pending'] === 0])>
                    <p class="text-[7px] font-black text-amber-500 uppercase tracking-widest">Demandes</p>
                    <p class="text-2xl font-black {{ $kpi['pending'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $kpi['pending'] }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-blue-500 uppercase tracking-widest">En congé</p>
                    <p class="text-2xl font-black text-blue-600">{{ $kpi['on_leave'] }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">Ce mois</p>
                    <p class="text-2xl font-black text-slate-800">{{ $kpi['this_month'] }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- FORMULAIRE --}}
                @can('annuaire.C')
                <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm text-left">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Enregistrer un congé</h3>
                    <form method="POST" action="{{ route('payroll.leaves.store') }}" class="space-y-3">
                        @csrf
                        <select name="employee_id" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none uppercase">
                            <option value="">Employé...</option>
                            @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->first_name }} {{ $e->last_name }}</option>@endforeach
                        </select>
                        <select name="type" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                            <option value="conge_annuel">Congé annuel</option>
                            <option value="maladie">Maladie</option>
                            <option value="maternite">Maternité</option>
                            <option value="sans_solde">Sans solde</option>
                            <option value="absence">Absence</option>
                            <option value="formation">Formation</option>
                        </select>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="start_date" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                            <input type="date" name="end_date" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                        </div>
                        <textarea name="reason" rows="2" placeholder="Motif..." class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-bold shadow-inner outline-none"></textarea>
                        <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-blue-600 border-none cursor-pointer italic">Enregistrer</button>
                    </form>
                </div>
                @endcan

                {{-- LISTE --}}
                <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[7px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="px-5 py-3 text-left">Employé</th>
                                <th class="px-3 py-3 text-center">Type</th>
                                <th class="px-3 py-3 text-center">Période</th>
                                <th class="px-3 py-3 text-center">Jours</th>
                                <th class="px-3 py-3 text-center">Statut</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($leaves as $l)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-5 py-3 text-xs font-black text-slate-900 uppercase">{{ $l->employee->first_name }} {{ $l->employee->last_name }}</td>
                                <td class="px-3 py-3 text-center text-[9px] font-black text-slate-600">{{ $l->type_label }}</td>
                                <td class="px-3 py-3 text-center text-[9px] font-black text-slate-500">{{ $l->start_date->format('d/m') }} → {{ $l->end_date->format('d/m') }}</td>
                                <td class="px-3 py-3 text-center text-sm font-black text-slate-800">{{ $l->days_count }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                        'bg-amber-100 text-amber-600' => $l->status === 'demande',
                                        'bg-emerald-100 text-emerald-600' => $l->status === 'approuve',
                                        'bg-blue-100 text-blue-600' => $l->status === 'en_cours',
                                        'bg-slate-100 text-slate-500' => $l->status === 'termine',
                                        'bg-red-100 text-red-500' => $l->status === 'refuse'])>{{ $l->status }}</span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @can('annuaire.M')
                                    @if(in_array($l->status, ['approuve', 'en_cours']))
                                    <form method="POST" action="{{ route('payroll.leaves.end', $l) }}">@csrf
                                        <button class="text-[8px] font-black text-emerald-500 bg-emerald-50 px-3 py-1.5 rounded-lg hover:bg-emerald-100 border-none cursor-pointer uppercase">Retour</button>
                                    </form>
                                    @endif
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="px-8 py-10 text-center text-slate-300 text-[9px] uppercase italic tracking-widest">Aucun congé</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="px-5 py-3">{{ $leaves->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
