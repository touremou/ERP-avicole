<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('reports.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm no-underline">
                <i class="fas fa-chevron-left text-xs"></i>
                <span class="text-[10px] font-black uppercase italic tracking-widest leading-none">Rapports</span>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">🍼 Nurserie / Reproduction</h2>
                <p class="text-[10px] font-black text-pink-600 uppercase tracking-[0.2em] mt-2 italic">Agnelage · chevrotage · sevrage</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <form method="GET" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">Du</label>
                    <input type="date" name="date_from" value="{{ $from->toDateString() }}" class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">Au</label>
                    <input type="date" name="date_to" value="{{ $to->toDateString() }}" class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-pink-600 transition-all border-none cursor-pointer">
                    <i class="fa-solid fa-filter mr-1"></i> Appliquer
                </button>
            </form>

            {{-- KPI --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-pink-500 uppercase tracking-widest mb-2 italic">Naissances</p>
                    <p class="text-4xl font-black text-slate-900 italic tracking-tighter">{{ number_format($totalBorn) }}</p>
                    <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">Petits nés sur la période</p>
                </div>
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic">Sevrages</p>
                    <p class="text-4xl font-black text-slate-900 italic tracking-tighter">{{ number_format($totalWeaned) }}</p>
                    <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">Jeunes sevrés</p>
                </div>
                <div class="p-8 rounded-[3rem] shadow-2xl bg-slate-900 text-white">
                    <p class="text-[9px] font-black text-pink-400 uppercase tracking-widest mb-2 italic">Taux de sevrage moyen</p>
                    <p class="text-4xl font-black italic tracking-tighter">{{ $avgWeaningRate }}%</p>
                    <p class="text-[8px] mt-2 uppercase font-black opacity-60">Sevrés / nés</p>
                </div>
            </div>

            {{-- DÉTAIL PAR LOT --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-6">
                    <span class="w-2 h-6 bg-pink-500 rounded-full mr-3"></span> Détail par lot
                </h3>
                @if($rows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="pb-3">Lot</th><th class="pb-3">Espèce</th><th class="pb-3 text-center">Naissances</th><th class="pb-3 text-center">Sevrages</th><th class="pb-3 text-right">Taux sevrage</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                            <tr class="text-xs font-black border-b border-slate-50">
                                <td class="py-3">
                                    <a href="{{ route('batches.show', $r['batch']->id) }}" class="text-slate-800 uppercase no-underline hover:text-pink-600">{{ $r['batch']->code }}</a>
                                </td>
                                <td class="py-3 text-slate-500 uppercase">{{ $r['icon'] }} {{ $r['species'] }}</td>
                                <td class="py-3 text-center text-pink-600">{{ number_format($r['born']) }}</td>
                                <td class="py-3 text-center text-emerald-600">{{ number_format($r['weaned']) }}</td>
                                <td class="py-3 text-right {{ ($r['weaning_rate'] ?? 0) >= 80 ? 'text-emerald-600' : 'text-slate-900' }}">{{ $r['weaning_rate'] !== null ? $r['weaning_rate'].'%' : '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase tracking-widest italic py-8">
                        Aucune naissance ni sevrage saisi sur la période. Les naissances/sevrages se saisissent au pointage journalier des lots reproducteurs.
                    </p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
