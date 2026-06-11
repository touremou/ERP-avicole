<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-money-bill-wave text-lg"></i></div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Gestion de la Paie</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">Périodes, fiches & paiements</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('payroll.leaves') }}" class="bg-white border border-slate-200 px-5 py-2.5 rounded-xl text-[9px] font-black uppercase italic text-slate-600 hover:bg-amber-50 no-underline"><i class="fa-solid fa-calendar-xmark text-amber-500 mr-1"></i> Congés</a>
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

            {{-- CRÉER UNE PÉRIODE --}}
            @can('annuaire.C')
            @if(!$hasCurrent)
            <form method="POST" action="{{ route('payroll.create-period') }}" class="mb-6 bg-blue-50 p-6 rounded-2xl border border-blue-200 flex items-center gap-4">
                @csrf
                <i class="fa-solid fa-plus-circle text-blue-500 text-xl"></i>
                <div class="flex-1">
                    <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest">Créer la paie du mois en cours</p>
                    <p class="text-[8px] text-blue-400">{{ now()->translatedFormat('F Y') }}</p>
                </div>
                <input type="hidden" name="year" value="{{ now()->year }}">
                <input type="hidden" name="month" value="{{ now()->month }}">
                <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-blue-700 border-none cursor-pointer shadow-lg italic">Créer</button>
            </form>
            @endif
            @endcan

            {{-- LISTE DES PÉRIODES --}}
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-6 py-3 text-left">Période</th>
                            <th class="px-4 py-3 text-center">Employés</th>
                            <th class="px-4 py-3 text-right">Masse brute</th>
                            <th class="px-4 py-3 text-right">Net total</th>
                            <th class="px-4 py-3 text-center">Statut</th>
                            <th class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($periods as $p)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4">
                                <p class="text-sm font-black text-slate-900 uppercase">{{ $p->label }}</p>
                                <p class="text-[8px] text-slate-400">{{ $p->start_date->format('d/m') }} → {{ $p->end_date->format('d/m/Y') }}</p>
                            </td>
                            <td class="px-4 py-4 text-center text-sm font-black text-slate-800">{{ $p->payslips_count }}</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-slate-600">{{ number_format($p->total_brut, 0, ',', '.') }}</td>
                            <td class="px-4 py-4 text-right text-sm font-black text-emerald-600">{{ number_format($p->total_net, 0, ',', '.') }}</td>
                            <td class="px-4 py-4 text-center">
                                <span @class(['text-[8px] font-black uppercase px-2.5 py-1 rounded-full',
                                    'bg-slate-100 text-slate-500' => $p->status === 'brouillon',
                                    'bg-blue-100 text-blue-600' => $p->status === 'calcule',
                                    'bg-emerald-100 text-emerald-600' => $p->status === 'valide',
                                    'bg-slate-800 text-white' => $p->status === 'paye'])>{{ $p->status }}</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('payroll.show', $p) }}" class="text-blue-500 hover:text-blue-700 no-underline text-[9px] font-black uppercase"><i class="fa-solid fa-eye mr-1"></i> Détail</a>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-8 py-12 text-center text-slate-300 text-[10px] uppercase italic tracking-widest">Aucune période</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-6 py-3">{{ $periods->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
