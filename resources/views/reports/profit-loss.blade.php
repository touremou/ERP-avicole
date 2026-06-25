<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('reports.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm no-underline">
                <i class="fas fa-chevron-left text-xs"></i>
                <span class="text-[10px] font-black uppercase italic tracking-widest leading-none">{{ __("Rapports") }}</span>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">⚖️ {{ __("Compte de résultat") }}</h2>
                <p class="text-[10px] font-black text-amber-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("P&L consolidé — toutes activités") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- SÉLECTEUR DE PÉRIODE --}}
            <form method="GET" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">{{ __("Du") }}</label>
                    <input type="date" name="date_from" value="{{ $from->toDateString() }}" class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">{{ __("Au") }}</label>
                    <input type="date" name="date_to" value="{{ $to->toDateString() }}" class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-amber-600 transition-all border-none cursor-pointer">
                    <i class="fa-solid fa-filter mr-1"></i> {{ __("Appliquer") }}
                </button>
                <div class="ml-auto flex gap-2">
                    <a href="{{ route('reports.profit_loss', ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString()]) }}" class="px-4 py-3 bg-slate-50 text-slate-500 rounded-2xl text-[9px] font-black uppercase tracking-widest no-underline hover:bg-slate-100">{{ __("Ce mois") }}</a>
                    <a href="{{ route('reports.profit_loss', ['date_from' => now()->startOfYear()->toDateString(), 'date_to' => now()->toDateString()]) }}" class="px-4 py-3 bg-slate-50 text-slate-500 rounded-2xl text-[9px] font-black uppercase tracking-widest no-underline hover:bg-slate-100">{{ __("Année") }}</a>
                    <a href="{{ route('reports.profit_loss.pdf', ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]) }}" class="px-4 py-3 bg-amber-600 text-white rounded-2xl text-[9px] font-black uppercase tracking-widest no-underline hover:bg-amber-700"><i class="fa-solid fa-file-pdf mr-1"></i> {{ __("Export PDF") }}</a>
                </div>
            </form>

            {{-- RÉSULTAT NET --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic">{{ __("Produits") }}</p>
                    <p class="text-3xl font-black text-slate-900 italic tracking-tighter">{{ number_format($totalRevenue) }}</p>
                    <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ currency() }}</p>
                </div>
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-rose-500 uppercase tracking-widest mb-2 italic">{{ __("Charges") }}</p>
                    <p class="text-3xl font-black text-slate-900 italic tracking-tighter">{{ number_format($totalCosts) }}</p>
                    <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ currency() }}</p>
                </div>
                <div class="p-8 rounded-[3rem] shadow-2xl {{ $netResult >= 0 ? 'bg-emerald-600' : 'bg-rose-600' }} text-white">
                    <p class="text-[9px] font-black uppercase tracking-widest mb-2 italic opacity-80">{{ __("Résultat net") }}</p>
                    <p class="text-3xl font-black italic tracking-tighter">{{ number_format($netResult) }}</p>
                    <p class="text-[8px] mt-2 uppercase font-black opacity-80">{{ __("Marge") }} {{ $marginPct }}% · {{ currency() }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {{-- PRODUITS --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-6">
                        <span class="w-2 h-6 bg-emerald-500 rounded-full mr-3"></span> {{ __("Produits") }}
                    </h3>
                    @forelse($revenue as $label => $amount)
                        <div class="flex justify-between items-center py-3 border-b border-slate-50">
                            <span class="text-xs font-black text-slate-600 uppercase">{{ $label }}</span>
                            <span class="text-sm font-black text-slate-900">{{ number_format($amount) }}</span>
                        </div>
                    @empty
                        <p class="text-center text-slate-300 text-[10px] font-black uppercase tracking-widest italic py-6">{{ __("Aucun produit sur la période.") }}</p>
                    @endforelse
                    <div class="flex justify-between items-center pt-4 mt-2">
                        <span class="text-[10px] font-black text-emerald-600 uppercase tracking-widest">{{ __("Total produits") }}</span>
                        <span class="text-lg font-black text-emerald-600">{{ number_format($totalRevenue) }}</span>
                    </div>
                </div>

                {{-- CHARGES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-6">
                        <span class="w-2 h-6 bg-rose-500 rounded-full mr-3"></span> {{ __("Charges") }}
                    </h3>
                    @foreach($costs as $label => $amount)
                        <div class="flex justify-between items-center py-3 border-b border-slate-50">
                            <span class="text-xs font-black text-slate-600 uppercase">{{ $label }}</span>
                            <span class="text-sm font-black {{ $amount > 0 ? 'text-slate-900' : 'text-slate-300' }}">{{ number_format($amount) }}</span>
                        </div>
                    @endforeach
                    <div class="flex justify-between items-center pt-4 mt-2">
                        <span class="text-[10px] font-black text-rose-600 uppercase tracking-widest">{{ __("Total charges") }}</span>
                        <span class="text-lg font-black text-rose-600">{{ number_format($totalCosts) }}</span>
                    </div>
                </div>
            </div>

            {{-- MARGE PAR ESPÈCE --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-2">
                    <span class="w-2 h-6 bg-amber-500 rounded-full mr-3"></span> {{ __("Marge directe par espèce") }}
                </h3>
                <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest italic mb-6 ml-5">{{ __("Revenus & coûts traçables au lot · hors frais généraux (paie, eau, énergie)") }}</p>
                @if(count($speciesMargin))
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="pb-3">{{ __("Espèce") }}</th><th class="pb-3 text-right">{{ __("Produits") }}</th><th class="pb-3 text-right">{{ __("Coûts directs") }}</th><th class="pb-3 text-right">{{ __("Marge directe") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($speciesMargin as $row)
                            <tr class="text-xs font-black border-b border-slate-50">
                                <td class="py-3 text-slate-800 uppercase">{{ $row['icon'] }} {{ $row['species'] }}</td>
                                <td class="py-3 text-right text-emerald-600">{{ number_format($row['revenue']) }}</td>
                                <td class="py-3 text-right text-rose-500">{{ number_format($row['cost']) }}</td>
                                <td class="py-3 text-right {{ $row['margin'] >= 0 ? 'text-slate-900' : 'text-rose-600' }}">{{ number_format($row['margin']) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase tracking-widest italic py-6">{{ __("Aucune activité traçable par espèce sur la période.") }}</p>
                @endif
            </div>

            @if(count($cropMargin ?? []))
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-2">
                    <span class="w-2 h-6 bg-green-500 rounded-full mr-3"></span> {{ __("Marge directe par culture") }}
                </h3>
                <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest italic mb-6 ml-5">{{ __("Cycles clôturés sur la période · revenus vs coûts (forfaits + intrants)") }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="pb-3">{{ __("Culture") }}</th><th class="pb-3 text-right">{{ __("Produits") }}</th><th class="pb-3 text-right">{{ __("Coûts directs") }}</th><th class="pb-3 text-right">{{ __("Marge directe") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cropMargin as $row)
                            <tr class="text-xs font-black border-b border-slate-50">
                                <td class="py-3 text-slate-800 uppercase">🌱 {{ $row['crop'] }}</td>
                                <td class="py-3 text-right text-emerald-600">{{ number_format($row['revenue']) }}</td>
                                <td class="py-3 text-right text-rose-500">{{ number_format($row['cost']) }}</td>
                                <td class="py-3 text-right {{ $row['margin'] >= 0 ? 'text-slate-900' : 'text-rose-600' }}">{{ number_format($row['margin']) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest italic text-center">
                {{ __("Vue trésorerie sur la période — produits = ventes validées + lait collecté valorisé · charges = flux engagés sur la période.") }}
            </p>
        </div>
    </div>
</x-app-layout>
