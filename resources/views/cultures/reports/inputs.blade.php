<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-flask-vial text-lg"></i>
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Coûts des Intrants") }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ __("Production végétale") }} · {{ $year }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('crop-reports.inputs.pdf', request()->query()) }}" class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 hover:bg-amber-600 transition">
                    <i class="fa-solid fa-file-pdf"></i> {{ __("Export PDF") }}
                </a>
                <a href="{{ route('crop-reports.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-1"></i> {{ __("Rapports") }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- FILTRE ANNÉE --}}
            <form method="GET" action="{{ route('crop-reports.inputs') }}" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
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
                <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest italic hover:bg-amber-600 transition">
                    <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                </button>
            </form>

            {{-- KPI CARDS --}}
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Lignes saisies") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $inputs->count() }}</p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-amber-400 uppercase tracking-widest italic mb-2">{{ __("Coût total intrants") }}</p>
                    <p class="text-3xl font-black leading-none">{{ number_format($totalCost, 0, ',', ' ') }} <small class="text-[10px] opacity-40">{{ $currency }}</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Cultures concernées") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $byCrop->count() }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                {{-- PAR TYPE --}}
                @if($byType->isNotEmpty())
                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-8 pt-8 pb-4">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Répartition par type") }}</h3>
                    </div>
                    <div class="px-8 pb-8 space-y-3">
                        @foreach($byType as $row)
                        <div>
                            <div class="flex justify-between text-[10px] mb-1">
                                <span class="font-black text-slate-700">{{ $row['label'] }}</span>
                                <span class="text-slate-500">{{ number_format($row['cost'], 0, ',', ' ') }} {{ $currency }} <span class="text-slate-300">·</span> {{ $row['pct'] }}%</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2">
                                <div class="bg-amber-500 h-2 rounded-full transition-all" style="width: {{ $row['pct'] }}%"></div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- PAR CULTURE --}}
                @if($byCrop->isNotEmpty())
                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-8 pt-8 pb-4">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Répartition par culture") }}</h3>
                    </div>
                    <table class="w-full text-left text-[11px]">
                        <thead>
                            <tr class="border-t border-slate-50">
                                <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Culture") }}</th>
                                <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Lignes") }}</th>
                                <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Coût") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($byCrop as $row)
                            <tr class="border-t border-slate-50 hover:bg-slate-50 transition">
                                <td class="px-8 py-3 font-black text-slate-800">{{ $row['crop'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-600">{{ $row['count'] }}</td>
                                <td class="px-8 py-3 text-right font-black text-amber-600">{{ number_format($row['cost'], 0, ',', ' ') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

            </div>

            {{-- DÉTAIL --}}
            @if($inputs->isNotEmpty())
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 pt-8 pb-4">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Détail des intrants") }}</h3>
                </div>
                <table class="w-full text-left text-[11px]">
                    <thead>
                        <tr class="border-t border-slate-50">
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Intrant") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Type") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Cycle") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Qté") }}</th>
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Coût total") }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($inputs->sortByDesc('input_date') as $input)
                        <tr class="border-t border-slate-50 hover:bg-slate-50 transition">
                            <td class="px-8 py-3 font-black text-slate-800">
                                {{ $input->name }}
                                <p class="text-[8px] text-slate-400 font-medium mt-0.5">{{ $input->input_date?->format('d/m/Y') }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ \App\Models\CropInput::TYPES[$input->type] ?? $input->type }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $input->cropCycle->crop_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ number_format((float)$input->quantity, 1, ',', ' ') }} {{ $input->unit }}</td>
                            <td class="px-8 py-3 text-right font-black text-amber-600">{{ number_format((float)$input->total_cost, 0, ',', ' ') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="bg-white p-12 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                <i class="fa-solid fa-flask-vial text-4xl text-slate-200 mb-4"></i>
                <p class="text-[11px] font-black uppercase text-slate-400 tracking-widest">{{ __("Aucun intrant enregistré pour cette année") }}</p>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
