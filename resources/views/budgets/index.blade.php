<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-indigo-600 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl -rotate-3">
                    <i class="fa-solid fa-scale-balanced text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Suivi Budgétaire") }}</h2>
                    <p class="text-[10px] font-black text-indigo-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ __("Budget par poste vs dépenses validées") }}
                    </p>
                </div>
            </div>
            <a href="{{ route('expenses.index') }}" class="text-[10px] font-black uppercase italic text-slate-400 hover:text-slate-800 transition no-underline">
                <i class="fa-solid fa-receipt mr-1"></i> {{ __("Registre des dépenses") }}
            </a>
        </div>
    </x-slot>

    @php
        $canEdit = auth()->user()->can('depenses.M');
        $currency = setting('general.currency', 'GNF');
        $months = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
    @endphp

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success',
                        'bg-red-500 text-white' => $msg === 'error',
                    ])>{{ session($msg) }}</div>
                @endif
            @endforeach

            {{-- PÉRIODE + EXPORT --}}
            <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
                <form method="GET" class="flex flex-wrap gap-3 items-center">
                    <select name="month" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none cursor-pointer">
                        @foreach($months as $m => $label)
                            <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ __($label) }}</option>
                        @endforeach
                    </select>
                    <select name="year" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none cursor-pointer">
                        @for($y = now()->year + 1; $y >= 2023; $y--)
                            <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </form>
                <a href="{{ route('budgets.export', ['year' => $year, 'month' => $month]) }}"
                   class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-indigo-600 transition-all no-underline flex items-center gap-2">
                    <i class="fa-solid fa-file-csv"></i> {{ __("Exporter CSV") }}
                </a>
            </div>

            {{-- TOTAUX --}}
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 text-center shadow-sm">
                    <p class="text-[8px] font-black text-indigo-500 uppercase tracking-widest mb-1">{{ __("Budget total") }}</p>
                    <p class="text-2xl font-black text-slate-800">{{ number_format($totals['budget'], 0, ',', ' ') }} <span class="text-[9px] text-slate-400">{{ $currency }}</span></p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 text-center shadow-sm">
                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1">{{ __("Dépensé") }}</p>
                    <p class="text-2xl font-black text-amber-600">{{ number_format($totals['spent'], 0, ',', ' ') }} <span class="text-[9px] text-slate-400">{{ $currency }}</span></p>
                </div>
                <div @class(['p-5 rounded-[2rem] border text-center shadow-sm', 'bg-red-50 border-red-200' => $totals['remaining'] < 0, 'bg-white border-slate-100' => $totals['remaining'] >= 0])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $totals['remaining'] < 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ $totals['remaining'] < 0 ? __("Dépassement") : __("Reste") }}</p>
                    <p class="text-2xl font-black {{ $totals['remaining'] < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ number_format(abs($totals['remaining']), 0, ',', ' ') }} <span class="text-[9px] text-slate-400">{{ $currency }}</span></p>
                </div>
            </div>

            {{-- TABLE BUDGET PAR POSTE --}}
            <form method="POST" action="{{ route('budgets.store') }}">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="month" value="{{ $month }}">

                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 bg-slate-50">
                                <th class="px-6 py-4 text-left">{{ __("Poste") }}</th>
                                <th class="px-4 py-4 text-right">{{ __("Budget") }} ({{ $currency }})</th>
                                <th class="px-4 py-4 text-right">{{ __("Dépensé") }}</th>
                                <th class="px-4 py-4 text-right">{{ __("Reste") }}</th>
                                <th class="px-6 py-4 text-left w-1/4">{{ __("Consommation") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($rows as $r)
                            <tr @class(['hover:bg-slate-50/50 transition-colors', 'bg-red-50/40' => $r['over']])>
                                <td class="px-6 py-4">
                                    <p class="text-xs font-black text-slate-800 uppercase">{{ $r['label'] }}</p>
                                    @if($r['no_budget'])
                                        <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest">{{ __("Dépense sans budget") }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <input type="number" name="budgets[{{ $r['category'] }}]" value="{{ $r['budget'] > 0 ? rtrim(rtrim(number_format($r['budget'], 2, '.', ''), '0'), '.') : '' }}"
                                        min="0" step="1" placeholder="0" {{ $canEdit ? '' : 'readonly' }}
                                        class="w-32 bg-slate-50 border border-slate-100 rounded-xl px-3 py-2 text-sm font-black text-slate-800 text-right outline-none focus:border-indigo-400 {{ $canEdit ? '' : 'cursor-not-allowed opacity-70' }}">
                                </td>
                                <td class="px-4 py-4 text-right text-sm font-black text-amber-600">{{ number_format($r['spent'], 0, ',', ' ') }}</td>
                                <td class="px-4 py-4 text-right text-sm font-black {{ $r['remaining'] < 0 ? 'text-red-600' : 'text-slate-500' }}">{{ number_format($r['remaining'], 0, ',', ' ') }}</td>
                                <td class="px-6 py-4">
                                    @if($r['budget'] > 0)
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
                                                <div @class([
                                                    'h-full rounded-full',
                                                    'bg-red-500' => $r['pct'] > 100,
                                                    'bg-amber-400' => $r['pct'] > 80 && $r['pct'] <= 100,
                                                    'bg-emerald-500' => $r['pct'] <= 80,
                                                ]) style="width: {{ min(100, $r['pct']) }}%"></div>
                                            </div>
                                            <span class="text-[9px] font-black {{ $r['pct'] > 100 ? 'text-red-600' : 'text-slate-500' }} w-12 text-right">{{ $r['pct'] }}%</span>
                                        </div>
                                    @else
                                        <span class="text-[8px] font-black text-slate-300 uppercase tracking-widest">{{ __("Non budgété") }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($canEdit)
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="bg-indigo-600 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-indigo-700 transition-all border-none cursor-pointer shadow-xl italic">
                        <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer les budgets") }}
                    </button>
                </div>
                @else
                <p class="mt-4 text-[9px] font-black text-slate-400 uppercase tracking-widest italic text-center">
                    {{ __("Lecture seule — le droit « Modifier » (M) est requis pour définir les budgets.") }}
                </p>
                @endif
            </form>

        </div>
    </div>
</x-app-layout>
