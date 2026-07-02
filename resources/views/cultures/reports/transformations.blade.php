<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <x-page-header :title="__('Efficacité Transformation')" :subtitle="__('Production végétale') . ' · ' . $year" icon="fa-industry" accent="green">
            <x-slot name="actions">
                <a href="{{ route('crop-reports.transformations.pdf', request()->query()) }}" class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 hover:bg-emerald-600 transition">
                    <i class="fa-solid fa-file-pdf"></i> {{ __("Export PDF") }}
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- FILTRES --}}
            <form method="GET" action="{{ route('crop-reports.transformations') }}" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
                <div>
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">{{ __("Année") }}</label>
                    <select name="year" class="text-[11px] font-black rounded-xl border-slate-200 px-3 py-2">
                        @foreach($years as $y)
                            <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
                        @endforeach
                        @if($years->isEmpty())
                            <option value="{{ now()->year }}" selected>{{ now()->year }}</option>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">{{ __("Type") }}</label>
                    <select name="transformation_type" class="text-[11px] font-black rounded-xl border-slate-200 px-3 py-2">
                        <option value="">{{ __("Tous") }}</option>
                        @foreach($types as $key => $label)
                            <option value="{{ $key }}" @selected($key == $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest italic hover:bg-emerald-600 transition">
                    <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                </button>
            </form>

            {{-- KPI CARDS --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Entrée totale") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($totalInput, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Sortie totale") }}</p>
                    <p class="text-3xl font-black text-green-600 leading-none">{{ number_format($totalOutput, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-emerald-400 uppercase tracking-widest italic mb-2">{{ __("Rendement moyen") }}</p>
                    <p class="text-3xl font-black leading-none">{{ number_format($avgYield, 1, ',', ' ') }} <small class="text-[10px] opacity-40">%</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Valeur produite") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($totalValue, 0, ',', ' ') }} <small class="text-[10px] opacity-40">{{ $currency }}</small></p>
                </div>
            </div>

            {{-- PAR TYPE --}}
            @if($byType->isNotEmpty())
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 pt-8 pb-4">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Synthèse par type de transformation") }}</h3>
                </div>
                <table class="w-full text-left text-[11px]">
                    <thead>
                        <tr class="border-t border-slate-50">
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Type") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Lots") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Entrée (kg)") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Sortie (kg)") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Rendement") }}</th>
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Valeur") }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byType as $row)
                        <tr class="border-t border-slate-50 hover:bg-slate-50 transition">
                            <td class="px-8 py-4 font-black text-slate-800">{{ $row['label'] }}</td>
                            <td class="px-4 py-4 text-right text-slate-600">{{ $row['count'] }}</td>
                            <td class="px-4 py-4 text-right text-slate-600">{{ number_format($row['input'], 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right font-black text-green-600">{{ number_format($row['output'], 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right font-black text-emerald-600">{{ number_format($row['yield'], 1, ',', ' ') }}%</td>
                            <td class="px-8 py-4 text-right font-black text-slate-800">{{ number_format($row['value'], 0, ',', ' ') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- DÉTAIL --}}
            @if($transformations->isNotEmpty())
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 pt-8 pb-4">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Détail des lots") }}</h3>
                </div>
                <table class="w-full text-left text-[11px]">
                    <thead>
                        <tr class="border-t border-slate-50">
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Lot") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Entrée") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Sortie") }}</th>
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Rendement") }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transformations->sortByDesc('production_date') as $t)
                        <tr class="border-t border-slate-50 hover:bg-slate-50 transition">
                            <td class="px-8 py-3">
                                <a href="{{ route('crop-transformations.show', $t) }}" class="font-black text-slate-800 hover:text-emerald-700 no-underline">{{ $t->input_product }} → {{ $t->output_product }}</a>
                                <p class="text-[8px] text-slate-400 font-medium mt-0.5">{{ $t->batch_number }} · {{ $t->production_date?->format('d/m/Y') }}</p>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ number_format((float)$t->input_quantity, 0, ',', ' ') }} {{ $t->input_unit }}</td>
                            <td class="px-4 py-3 text-right font-black text-green-600">{{ number_format((float)$t->output_quantity, 0, ',', ' ') }} {{ $t->output_unit }}</td>
                            <td class="px-8 py-3 text-right font-black text-emerald-600">{{ number_format((float)$t->yield_percent, 1, ',', ' ') }}%</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="bg-white p-12 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                <i class="fa-solid fa-industry text-4xl text-slate-200 mb-4"></i>
                <p class="text-[11px] font-black uppercase text-slate-400 tracking-widest">{{ __("Aucune transformation pour cette sélection") }}</p>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
