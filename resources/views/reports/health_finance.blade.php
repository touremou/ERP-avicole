<x-app-layout>
    @php
        $currency = setting('general.currency', 'GNF');
        $seuilCritique = setting('elevage.mortality_alert', 5);
        $seuilAlerte = round($seuilCritique * 0.6, 1);
        $df = setting('general.date_format', 'd/m/Y');
    @endphp
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div>
                <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    💰 Analyse Financière Santé
                </h2>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-3 italic leading-none">
                    Performance Économique Prophylactique • {{ $batches->count() }} Lot(s)
                </p>
            </div>
            <a href="{{ route('reports.index') }}" class="group flex items-center gap-3 px-6 py-3 bg-white border border-slate-200 text-slate-500 hover:text-slate-900 rounded-2xl text-[10px] font-black uppercase italic transition-all shadow-sm no-underline">
                <i class="fa-solid fa-chevron-left group-hover:-translate-x-1 transition-transform"></i> Retour aux rapports
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">
            
            {{-- 1. FILTRES STRATÉGIQUES --}}
            <div class="flex flex-col md:flex-row justify-center items-center gap-6">
                <div class="bg-slate-100 p-2 rounded-[2.5rem] flex gap-2 shadow-inner border border-slate-200/50">
                    @foreach(['all' => 'Historique global', 'year' => 'Exercice '.date('Y'), 'month' => 'Mois en cours'] as $key => $label)
                        <a href="{{ request()->fullUrlWithQuery(['period' => $key]) }}" 
                           @class([
                               'px-8 py-3 rounded-[1.5rem] text-[10px] font-black uppercase italic transition-all no-underline',
                               'bg-white shadow-xl text-slate-900 ring-1 ring-slate-200' => $period == $key,
                               'text-slate-400 hover:text-slate-600' => $period != $key
                           ])>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <div class="bg-blue-50 p-2 rounded-[2.5rem] flex gap-2 shadow-inner border border-blue-100">
                    @foreach(['all' => 'Tous les lots', 'actif' => 'En cours', 'clos' => 'Archives'] as $key => $label)
                        <a href="{{ request()->fullUrlWithQuery(['status' => $key]) }}"
                           @class([
                               'px-8 py-3 rounded-[1.5rem] text-[10px] font-black uppercase italic transition-all no-underline',
                               'bg-blue-600 text-white shadow-lg shadow-blue-200' => $statusFilter == $key,
                               'text-blue-400 hover:text-blue-600' => $statusFilter != $key
                           ])>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <a href="{{ route('reports.health_finance.pdf', ['period' => $period, 'status' => $statusFilter]) }}" class="px-8 py-3 bg-slate-900 text-white rounded-[1.5rem] text-[10px] font-black uppercase italic no-underline hover:bg-blue-600 transition-all shadow-lg">
                    <i class="fa-solid fa-file-pdf mr-1"></i> Export PDF
                </a>
            </div>

            {{-- 2. DASHBOARD FINANCIER --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 text-left">
                {{-- Carte Investissement --}}
                <div class="bg-slate-900 p-10 rounded-[3.5rem] text-white shadow-2xl relative overflow-hidden group border-b-8 border-blue-600">
                    <div class="relative z-10">
                        <p class="text-[11px] uppercase text-blue-400 font-black mb-5 tracking-[0.3em] italic">Dépense Sanitaire Totale</p>
                        <p class="text-6xl font-black text-white tracking-tighter mb-8 italic">
                            {{ number_format($totalGlobalCost, 0, ',', ' ') }} <small class="text-xs opacity-40 italic">{{ $currency }}</small>
                        </p>
                        <div class="flex items-center gap-8 border-t border-white/10 pt-8 italic">
                            <div>
                                <p class="text-[9px] uppercase opacity-40 mb-2 font-black tracking-widest leading-none">Coût Moyen / Tête</p>
                                <p class="text-2xl font-black text-blue-400 tracking-tighter">{{ number_format($averageCostPerHead, 0) }} <span class="text-xs opacity-60">{{ $currency }}</span></p>
                            </div>
                        </div>
                    </div>
                    <i class="fa-solid fa-chart-line absolute -right-8 -bottom-8 text-[12rem] opacity-5 group-hover:rotate-6 group-hover:scale-110 transition-all duration-1000"></i>
                </div>

                {{-- Graphique de Répartition --}}
                <div class="lg:col-span-2 bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-xl flex flex-col md:flex-row items-center gap-12 relative overflow-hidden">
                    <div class="flex-1 w-full space-y-6 relative z-10">
                        <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center gap-3">
                            <span class="w-2 h-6 bg-blue-600 rounded-full"></span> Structure des Coûts (%)
                        </h3>
                        <div class="space-y-5">
                            @php $colors = ['Vaccin' => 'bg-indigo-600', 'Traitement' => 'bg-rose-600', 'Vitamine' => 'bg-amber-400', 'Désinfection' => 'bg-slate-500']; @endphp
                            @foreach(['Vaccin', 'Traitement', 'Vitamine', 'Désinfection'] as $type)
                                @php 
                                    $amount = $typeBreakdown[$type] ?? 0;
                                    $percent = $totalGlobalCost > 0 ? ($amount / $totalGlobalCost) * 100 : 0; 
                                @endphp
                                <div class="group/bar space-y-2">
                                    <div class="flex justify-between text-[10px] font-black uppercase italic tracking-tighter">
                                        <span class="text-slate-500 group-hover/bar:text-slate-900 transition-colors">{{ $type }}</span>
                                        <span class="text-slate-800">{{ number_format($percent, 1) }}% <small class="text-slate-300 ml-1">({{ number_format($amount, 0, ',', ' ') }} {{ $currency }})</small></span>
                                    </div>
                                    <div class="h-3 w-full bg-slate-50 rounded-full overflow-hidden shadow-inner border border-slate-100 p-0.5">
                                        <div class="progress-bar {{ $colors[$type] }} h-full rounded-full transition-all duration-1000 shadow-sm" 
                                             style="width: {{ $percent }}%" 
                                             data-amount="{{ number_format($amount, 0, ',', ' ') }} {{ $currency }}"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    
                    {{-- Performance --}}
                    <div class="w-full md:w-56 bg-emerald-600 rounded-[3rem] p-8 text-center border-b-8 border-emerald-800 shadow-2xl shadow-emerald-200 group transform hover:rotate-2 transition-all">
                        <p class="text-[10px] uppercase text-emerald-100 mb-4 font-black tracking-widest italic leading-none">Batch d'Excellence</p>
                        <p class="text-4xl font-black text-white leading-none mb-3 tracking-tighter italic">{{ $bestBatch->code ?? 'N/A' }}</p>
                        <div class="h-px bg-white/20 w-12 mx-auto mb-4"></div>
                        <p class="text-[12px] font-black text-emerald-100 italic leading-none">{{ number_format($bestBatchCost, 0) }} <small class="font-black">{{ $currency }}/tête</small></p>
                        <i class="fa-solid fa-trophy absolute -right-4 -top-4 text-6xl opacity-10 text-white group-hover:scale-125 transition-transform"></i>
                    </div>
                </div>
            </div>

            {{-- 3. REGISTRE ANALYTIQUE DES LOTS --}}
            <div class="bg-white rounded-[4rem] border border-slate-100 shadow-2xl overflow-hidden text-left italic">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-800 text-[10px] font-black uppercase text-slate-400 italic">
                            <th class="px-12 py-8 tracking-[0.2em]">Identifiant Lot</th>
                            <th class="px-8 py-8 text-center tracking-[0.2em]">Effectif Initial</th>
                            <th class="px-8 py-8 text-right tracking-[0.2em]">Total Investi</th>
                            <th class="px-12 py-8 text-right tracking-[0.2em]">Performance ({{ $currency }}/Tête)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-[12px] font-black">
                        @php $totalBirdsInTable = 0; @endphp
                        @foreach($batches as $batch)
                            @php 
                                $totalBatchCost = $batch->healthChecks->sum('cost');
                                $ratio = $batch->initial_quantity > 0 ? $totalBatchCost / $batch->initial_quantity : 0;
                                $diff = $averageCostPerHead > 0 ? (($ratio - $averageCostPerHead) / $averageCostPerHead) * 100 : 0;
                                $totalBirdsInTable += $batch->initial_quantity;
                            @endphp
                            <tr class="hover:bg-slate-50 transition-all group">
                                <td class="px-12 py-8">
                                    <div class="flex items-center gap-4 mb-2">
                                        <p class="text-lg font-black text-slate-900 leading-none tracking-tighter group-hover:text-blue-600 transition-colors uppercase italic">{{ $batch->code }}</p>
                                        <span @class([
                                            'px-3 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest',
                                            'bg-emerald-100 text-emerald-600' => $batch->status === 'Actif',
                                            'bg-blue-100 text-blue-600' => $batch->status === 'Terminé',
                                        ])>
                                            {{ $batch->status }}
                                        </span>
                                    </div>
                                    <p class="text-[9px] text-slate-400 uppercase tracking-widest leading-none">Installation: <span class="text-slate-600 font-black">{{ $batch->building->name ?? 'N/A' }}</span></p>
                                </td>
                                <td class="px-8 py-8 text-center text-slate-500 text-base">
                                    {{ number_format($batch->initial_quantity, 0, ',', ' ') }} <small class="text-[10px] opacity-40 italic uppercase">Têtes</small>
                                </td>
                                <td class="px-8 py-8 text-right font-black text-slate-900 text-base">
                                    {{ number_format($totalBatchCost, 0, ',', ' ') }} <small class="text-[9px] opacity-40 italic">{{ $currency }}</small>
                                </td>
                                <td class="px-12 py-8 text-right">
                                    <div class="flex flex-col items-end gap-2">
                                        <span @class([
                                            'px-6 py-3 rounded-2xl text-[14px] font-black italic shadow-xl transition-all group-hover:scale-105',
                                            'bg-rose-50 text-rose-600 border border-rose-100' => $ratio > $averageCostPerHead,
                                            'bg-emerald-50 text-emerald-600 border border-emerald-100' => $ratio <= $averageCostPerHead
                                        ])>
                                            {{ number_format($ratio, 0) }} <small class="text-[9px] font-black ml-1">{{ $currency }}/U</small>
                                        </span>
                                        @if($ratio > 0)
                                            <span class="text-[9px] font-black uppercase {{ $ratio > $averageCostPerHead ? 'text-rose-500' : 'text-emerald-500' }} tracking-tighter">
                                                {{ $ratio > $averageCostPerHead ? '⚠️ SURCOÛT' : '✔️ ÉCONOMIE' }} : {{ number_format(abs($diff), 1) }}% vs moy.
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-100 text-slate-900 font-black italic uppercase text-[11px]">
                            <td class="px-12 py-8 border-t border-slate-200">Consolidé de la Période</td>
                            <td class="px-8 py-8 text-center border-t border-slate-200">{{ number_format($totalBirdsInTable, 0, ',', ' ') }} Têtes traitées</td>
                            <td class="px-8 py-8 text-right border-t border-slate-200 text-blue-600 text-lg tracking-tighter">{{ number_format($totalGlobalCost, 0, ',', ' ') }} GNF</td>
                            <td class="px-12 py-8 text-right border-t border-slate-200 bg-slate-200/50">Moy. de référence : {{ number_format($averageCostPerHead, 0) }} GNF</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>