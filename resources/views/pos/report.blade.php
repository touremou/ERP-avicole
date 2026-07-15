<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'🧾 ' . __('Z de caisse')" :subtitle="\Carbon\Carbon::parse($date)->translatedFormat('l j F Y')" icon="fa-receipt" accent="teal">
            <x-slot name="actions">
                <button onclick="window.print()" class="no-print inline-flex items-center gap-2 px-5 py-2.5 bg-slate-900 text-white rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all border-none cursor-pointer shadow-lg italic">
                    <i class="fa-solid fa-print"></i> {{ __("Imprimer") }}
                </button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <form method="GET" action="{{ route('pos.report') }}" class="no-print mb-6 flex items-center gap-3 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
                <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Date") }}</label>
                <input type="date" name="date" value="{{ $date }}" max="{{ date('Y-m-d') }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                <button type="submit" class="px-5 py-3 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all border-none cursor-pointer">{{ __("Afficher") }}</button>
            </form>

            {{-- KPIs --}}
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-emerald-50 border border-emerald-100 p-5 rounded-[2rem] text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Net encaissé") }}</p>
                    <p class="text-2xl font-black text-emerald-600 leading-none">{{ number_format($report['total_net'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-emerald-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-slate-50 border border-slate-100 p-5 rounded-[2rem] text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Tickets") }}</p>
                    <p class="text-2xl font-black text-slate-700 leading-none">{{ $report['tickets_count'] }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("ventes") }}</p>
                </div>
                <div class="bg-orange-50 border border-orange-100 p-5 rounded-[2rem] text-center">
                    <p class="text-[8px] font-black text-orange-500 uppercase tracking-widest mb-1">{{ __("Remboursements") }}</p>
                    <p class="text-2xl font-black text-orange-600 leading-none">{{ $report['refunds_count'] }}</p>
                    <p class="text-[8px] text-orange-300 font-black uppercase mt-1">{{ number_format($report['total_out'], 0, ',', ' ') }}</p>
                </div>
            </div>

            {{-- Détail par mode --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __("Mode de paiement") }}</th>
                            <th class="px-4 py-4 text-right text-emerald-500">{{ __("Encaissé") }}</th>
                            <th class="px-4 py-4 text-right text-orange-500">{{ __("Remboursé") }}</th>
                            <th class="px-6 py-4 text-right">{{ __("Net") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($report['rows'] as $r)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $r['label'] }}</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-emerald-600">{{ $r['in'] > 0 ? number_format($r['in'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-orange-500">{{ $r['out'] > 0 ? '-' . number_format($r['out'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-6 py-4 text-right text-sm font-black text-slate-900">{{ number_format($r['net'], 0, ',', ' ') }}</td>
                        </tr>
                        @endforeach
                        <tr class="bg-slate-900 text-white">
                            <td class="px-6 py-4 text-[10px] font-black uppercase tracking-widest">{{ __("TOTAL") }}</td>
                            <td class="px-4 py-4 text-right text-[11px] font-black text-emerald-400">{{ number_format($report['total_in'], 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right text-[11px] font-black text-orange-400">-{{ number_format($report['total_out'], 0, ',', ' ') }}</td>
                            <td class="px-6 py-4 text-right text-lg font-black text-white">{{ number_format($report['total_net'], 0, ',', ' ') }} {{ currency() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-[8px] text-slate-300 font-black uppercase tracking-widest text-center">
                {{ __("Net = encaissements − remboursements du jour (tous canaux de vente confondus).") }}
            </p>

            {{-- Ventes par vendeur (attribution nominative POS) --}}
            @if($report['by_seller']->isNotEmpty())
            <div class="mt-6 bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-100">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-user-tag text-teal-500"></i> {{ __("Ventes par vendeur") }}
                    </h3>
                </div>
                <table class="w-full text-left border-collapse">
                    <tbody class="divide-y divide-slate-50">
                        @foreach($report['by_seller'] as $seller)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $seller['name'] }}</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-slate-400">{{ $seller['count'] }} {{ __("vente(s)") }}</td>
                            <td class="px-6 py-4 text-right text-sm font-black text-teal-600">{{ number_format($seller['total'], 0, ',', ' ') }} {{ currency() }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
