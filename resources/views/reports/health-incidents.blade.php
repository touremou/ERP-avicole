<x-app-layout>
    <x-slot name="header">
        {{-- Retour assuré par <x-hub-back> (layout) → pas de :back. --}}
        <x-page-header :title="'🦠 ' . __('Rapport sanitaire')" :subtitle="__('Incidents par maladie · gravité · bâtiment · saison')" icon="fa-virus" accent="slate" />
    </x-slot>

    <div class="py-10 italic font-bold text-slate-700">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- Filtre période --}}
            <form method="GET" action="{{ route('reports.health_incidents') }}" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">{{ __("Du") }}</label>
                    <input type="date" name="from" value="{{ $from->toDateString() }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">{{ __("Au") }}</label>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer shadow-lg">
                    <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                </button>
            </form>

            {{-- KPIs synthèse --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="bg-white p-5 rounded-[1.5rem] border border-slate-100 shadow-sm"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Incidents") }}</p><p class="text-2xl font-black text-slate-800">{{ $summary['total'] }}</p></div>
                <div class="bg-white p-5 rounded-[1.5rem] border border-slate-100 shadow-sm"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Ouverts") }}</p><p class="text-2xl font-black {{ $summary['open'] > 0 ? 'text-rose-600' : 'text-slate-800' }}">{{ $summary['open'] }}</p></div>
                <div class="bg-white p-5 rounded-[1.5rem] border border-slate-100 shadow-sm"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Cadavres signalés") }}</p><p class="text-2xl font-black text-rose-600">{{ number_format($summary['mortality'], 0, ',', ' ') }}</p></div>
                <div class="bg-white p-5 rounded-[1.5rem] border border-slate-100 shadow-sm"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Coût traitements") }}</p><p class="text-xl font-black text-slate-800">{{ number_format($summary['cost'], 0, ',', ' ') }} <span class="text-[9px] text-slate-400">{{ currency() }}</span></p></div>
                <div class="bg-white p-5 rounded-[1.5rem] border border-slate-100 shadow-sm"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Délai résolution moyen") }}</p><p class="text-2xl font-black text-slate-800">{{ $summary['avg_resolution_days'] !== null ? $summary['avg_resolution_days'].' j' : '—' }}</p></div>
            </div>

            @if($incidents->isEmpty())
                <div class="bg-white p-16 rounded-[2.5rem] text-center border border-slate-100 shadow-sm">
                    <i class="fa-solid fa-shield-heart text-5xl text-emerald-400 mb-4"></i>
                    <p class="text-[10px] uppercase tracking-widest text-slate-400 font-black">{{ __("Aucun incident sanitaire sur la période.") }}</p>
                </div>
            @else
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {{-- Par maladie --}}
                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden lg:col-span-2">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100"><h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500">{{ __("Par maladie suspectée") }}</h3></div>
                    <table class="w-full text-left">
                        <thead><tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-6 py-3">{{ __("Maladie") }}</th><th class="px-6 py-3 text-right">{{ __("Cas") }}</th><th class="px-6 py-3 text-right">{{ __("Cadavres") }}</th><th class="px-6 py-3 text-right">{{ __("Coût") }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($byDisease as $disease => $row)
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-3 text-xs font-black text-slate-700">{{ $disease }}</td>
                                <td class="px-6 py-3 text-right font-black text-slate-800">{{ $row['count'] }}</td>
                                <td class="px-6 py-3 text-right font-black text-rose-600">{{ number_format($row['mortality'], 0, ',', ' ') }}</td>
                                <td class="px-6 py-3 text-right font-black text-slate-700">{{ number_format($row['cost'], 0, ',', ' ') }} {{ currency() }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Par gravité --}}
                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-4">{{ __("Par gravité") }}</h3>
                    @php $sevLabels = ['critique' => 'Critique', 'modere' => 'Modéré', 'mineur' => 'Mineur']; @endphp
                    @foreach(['critique', 'modere', 'mineur'] as $sev)
                        <div class="flex justify-between items-center py-2 border-b border-slate-50 last:border-0">
                            <span class="text-[11px] font-black text-slate-600 uppercase">{{ $sevLabels[$sev] }}</span>
                            <span class="font-black {{ $sev === 'critique' ? 'text-rose-600' : 'text-slate-800' }}">{{ $bySeverity[$sev] ?? 0 }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- Par bâtiment --}}
                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-4">{{ __("Foyers (par bâtiment)") }}</h3>
                    @forelse($byBuilding as $building => $count)
                        <div class="flex justify-between items-center py-2 border-b border-slate-50 last:border-0">
                            <span class="text-[11px] font-black text-slate-600 uppercase">{{ $building }}</span>
                            <span class="font-black text-slate-800">{{ $count }}</span>
                        </div>
                    @empty
                        <p class="text-[10px] text-slate-400 uppercase">—</p>
                    @endforelse
                </div>

                {{-- Saisonnalité (par mois) --}}
                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 lg:col-span-2">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-4">{{ __("Saisonnalité (incidents par mois)") }}</h3>
                    @php $maxMonth = max(1, $byMonth->max() ?? 1); @endphp
                    <div class="space-y-2">
                        @foreach($byMonth as $month => $count)
                            <div class="flex items-center gap-3">
                                <span class="text-[10px] font-black text-slate-500 w-20">{{ \Carbon\Carbon::parse($month.'-01')->translatedFormat('M Y') }}</span>
                                <div class="flex-1 h-4 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-rose-400 rounded-full" style="width: {{ round($count / $maxMonth * 100) }}%"></div></div>
                                <span class="text-[10px] font-black text-slate-700 w-8 text-right">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
