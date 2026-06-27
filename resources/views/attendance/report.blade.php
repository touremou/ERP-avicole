<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    📋 {{ __("Rapport de présence") }}
                </h2>
                <p class="text-[10px] font-black text-violet-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            {{-- Sélecteur de période --}}
            <form method="GET" action="{{ route('attendance.report') }}" class="mb-6 flex flex-wrap items-center gap-3 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
                <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Du") }}</label>
                <input type="date" name="from" value="{{ $from }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Au") }}</label>
                <input type="date" name="to" value="{{ $to }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                <button type="submit" class="px-5 py-3 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-violet-600 transition-all border-none cursor-pointer">{{ __("Afficher") }}</button>
                <span class="flex-1"></span>
                <a href="{{ route('attendance.report.csv', ['from' => $from, 'to' => $to]) }}" class="px-4 py-3 bg-emerald-50 text-emerald-600 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-100 transition-all no-underline" title="{{ __('Export Excel/CSV') }}"><i class="fa-solid fa-file-csv mr-1"></i> CSV</a>
                <a href="{{ route('attendance.report.pdf', ['from' => $from, 'to' => $to]) }}" class="px-4 py-3 bg-red-50 text-red-600 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-red-100 transition-all no-underline" title="{{ __('Export PDF') }}"><i class="fa-solid fa-file-pdf mr-1"></i> PDF</a>
            </form>

            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __("Employé") }}</th>
                            <th class="px-3 py-4 text-center text-emerald-500">{{ __("Présent") }}</th>
                            <th class="px-3 py-4 text-center text-amber-500">{{ __("Retard") }}</th>
                            <th class="px-3 py-4 text-center text-red-500">{{ __("Absent") }}</th>
                            <th class="px-3 py-4 text-center text-violet-500">{{ __("Congé") }}</th>
                            <th class="px-4 py-4 text-center">{{ __("Taux présence") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($rows as $row)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-700 uppercase">
                                {{ $row['employee']->first_name }} {{ $row['employee']->last_name }}
                                <span class="block text-[7px] text-slate-300">{{ $row['employee']->job_title ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-4 text-center text-sm font-black text-emerald-600">{{ $row['counts']['present'] }}</td>
                            <td class="px-3 py-4 text-center text-sm font-black text-amber-600">{{ $row['counts']['retard'] }}</td>
                            <td class="px-3 py-4 text-center text-sm font-black text-red-600">{{ $row['counts']['absent'] }}</td>
                            <td class="px-3 py-4 text-center text-sm font-black text-violet-600">{{ $row['counts']['conge'] }}</td>
                            <td class="px-4 py-4 text-center">
                                <span @class([
                                    'inline-block px-3 py-1 rounded-full text-[10px] font-black',
                                    'bg-emerald-50 text-emerald-700' => $row['presence_rate'] >= 90,
                                    'bg-amber-50 text-amber-700' => $row['presence_rate'] >= 70 && $row['presence_rate'] < 90,
                                    'bg-red-50 text-red-700' => $row['total'] > 0 && $row['presence_rate'] < 70,
                                    'bg-slate-50 text-slate-400' => $row['total'] === 0,
                                ])>{{ $row['total'] > 0 ? $row['presence_rate'] . ' %' : '—' }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="p-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun employé actif.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-[8px] text-slate-300 font-black uppercase tracking-widest text-center">
                {{ __("Taux de présence = (présents + retards) / jours pointés. Le congé est une absence justifiée.") }}
            </p>
        </div>
    </div>
</x-app-layout>
