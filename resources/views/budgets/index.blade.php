<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Suivi Budgétaire')" :subtitle="__('Budget par poste vs dépenses validées')" icon="fa-scale-balanced" accent="indigo">
            <x-slot name="actions">
                <a href="{{ route('expenses.index') }}" class="text-[10px] font-black uppercase italic text-slate-400 hover:text-slate-800 transition no-underline">
                    <i class="fa-solid fa-receipt mr-1"></i> {{ __("Registre des dépenses") }}
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $canEdit = auth()->user()->can('depenses.M');
        $currency = setting('general.currency', 'GNF');
        $months = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
        $isYear = ($mode ?? 'month') === 'year';
        $editable = $canEdit && ! $isYear; // l'annuel est un cumul en lecture seule
        $exportParams = $isYear ? ['mode' => 'year', 'year' => $year] : ['year' => $year, 'month' => $month];
    @endphp

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- BASCULE MENSUEL / ANNUEL --}}
            <div class="flex items-center gap-2 mb-5">
                <a href="{{ route('budgets.index', ['year' => $year, 'month' => $month]) }}"
                   @class(['px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest no-underline transition-all',
                       'bg-indigo-600 text-white shadow-lg' => ! $isYear, 'bg-white text-slate-400 border border-slate-100' => $isYear])>
                    {{ __("Mensuel") }}
                </a>
                <a href="{{ route('budgets.index', ['year' => $year, 'mode' => 'year']) }}"
                   @class(['px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest no-underline transition-all',
                       'bg-indigo-600 text-white shadow-lg' => $isYear, 'bg-white text-slate-400 border border-slate-100' => ! $isYear])>
                    {{ __("Annuel") }}
                </a>
            </div>

            {{-- PÉRIODE + EXPORT --}}
            <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
                <form method="GET" class="flex flex-wrap gap-3 items-center">
                    @if($isYear) <input type="hidden" name="mode" value="year"> @endif
                    @unless($isYear)
                    <select name="month" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none cursor-pointer">
                        @foreach($months as $m => $label)
                            <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ __($label) }}</option>
                        @endforeach
                    </select>
                    @endunless
                    <select name="year" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none cursor-pointer">
                        @for($y = now()->year + 1; $y >= 2023; $y--)
                            <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                    @if($isYear)<span class="text-[9px] font-black text-indigo-500 uppercase tracking-widest italic">{{ __("Cumul des 12 mois — lecture seule") }}</span>@endif
                </form>
                <div class="flex items-center gap-2">
                    <a href="{{ route('budgets.export', $exportParams) }}"
                       class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-indigo-600 transition-all no-underline flex items-center gap-2">
                        <i class="fa-solid fa-file-csv"></i> {{ __("CSV") }}
                    </a>
                    <a href="{{ route('budgets.export-pdf', $exportParams) }}"
                       class="bg-rose-600 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-rose-700 transition-all no-underline flex items-center gap-2">
                        <i class="fa-solid fa-file-pdf"></i> {{ __("PDF") }}
                    </a>
                </div>
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
                                    @if($editable)
                                        <input type="number" name="budgets[{{ $r['category'] }}]" value="{{ $r['budget'] > 0 ? rtrim(rtrim(number_format($r['budget'], 2, '.', ''), '0'), '.') : '' }}"
                                            min="0" step="1" placeholder="0"
                                            class="w-32 bg-slate-50 border border-slate-100 rounded-xl px-3 py-2 text-sm font-black text-slate-800 text-right outline-none focus:border-indigo-400">
                                    @else
                                        <span class="text-sm font-black text-slate-800">{{ number_format($r['budget'], 0, ',', ' ') }}</span>
                                    @endif
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

                @if($editable)
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="bg-indigo-600 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-indigo-700 transition-all border-none cursor-pointer shadow-xl italic">
                        <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer les budgets") }}
                    </button>
                </div>
                @endif
            </form>

            @if($editable)
                {{-- Report mois-à-mois : recopie les budgets du mois précédent --}}
                <form method="POST" action="{{ route('budgets.copy-previous') }}" class="mt-3 flex justify-start"
                      onsubmit="return confirm('{{ __('Reporter les budgets du mois précédent sur ce mois ? Les montants existants seront remplacés.') }}');">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}">
                    <input type="hidden" name="month" value="{{ $month }}">
                    <button type="submit" class="bg-white border-2 border-indigo-100 text-indigo-600 px-6 py-3 rounded-[2rem] font-black text-[9px] uppercase tracking-widest hover:bg-indigo-50 transition-all border-none cursor-pointer italic">
                        <i class="fa-solid fa-clock-rotate-left mr-2"></i> {{ __("Copier le mois précédent") }}
                    </button>
                </form>
            @elseif($isYear)
                <p class="mt-4 text-[9px] font-black text-slate-400 uppercase tracking-widest italic text-center">
                    {{ __("Vue annuelle (cumul des 12 mois) — l'édition des budgets se fait en vue mensuelle.") }}
                </p>
            @else
                <p class="mt-4 text-[9px] font-black text-slate-400 uppercase tracking-widest italic text-center">
                    {{ __("Lecture seule — le droit « Modifier » (M) est requis pour définir les budgets.") }}
                </p>
            @endif

        </div>
    </div>
</x-app-layout>
