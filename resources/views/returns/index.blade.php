<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('↩️ Journal des avoirs')" :subtitle="\Carbon\Carbon::parse($from)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($to)->format('d/m/Y')" icon="fa-rotate-left" accent="teal">
            <x-slot name="actions">
                <a href="{{ route('sales.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-list"></i> {{ __("Ventes") }}
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            {{-- Filtres + exports --}}
            <form method="GET" action="{{ route('returns.index') }}" class="mb-6 flex flex-wrap items-center gap-3 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
                <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Du") }}</label>
                <input type="date" name="from" value="{{ $from }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Au") }}</label>
                <input type="date" name="to" value="{{ $to }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                <button type="submit" class="px-5 py-3 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-orange-600 transition-all border-none cursor-pointer">{{ __("Afficher") }}</button>
                <span class="flex-1"></span>
                <a href="{{ route('returns.csv', ['from' => $from, 'to' => $to]) }}" class="px-4 py-3 bg-emerald-50 text-emerald-600 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-100 transition-all no-underline"><i class="fa-solid fa-file-csv mr-1"></i> CSV</a>
                <a href="{{ route('returns.pdf', ['from' => $from, 'to' => $to]) }}" class="px-4 py-3 bg-red-50 text-red-600 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-red-100 transition-all no-underline"><i class="fa-solid fa-file-pdf mr-1"></i> PDF</a>
            </form>

            <div class="mb-6 bg-orange-50 border border-orange-100 p-5 rounded-[2rem] flex items-center justify-between">
                <span class="text-[9px] font-black text-orange-500 uppercase tracking-widest">{{ __("Total remboursé sur la période") }}</span>
                <span class="text-2xl font-black text-orange-600">{{ money($totalRefund) }}</span>
            </div>

            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __("Avoir") }}</th>
                            <th class="px-4 py-4 text-left">{{ __("Date") }}</th>
                            <th class="px-4 py-4 text-left">{{ __("Vente") }}</th>
                            <th class="px-4 py-4 text-left">{{ __("Client") }}</th>
                            <th class="px-4 py-4 text-center">{{ __("Art.") }}</th>
                            <th class="px-4 py-4 text-right">{{ __("Remboursé") }}</th>
                            <th class="px-6 py-4 text-left">{{ __("Mode") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($returns as $r)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-800">{{ $r->reference }}</td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-500">{{ $r->return_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-4 text-[10px] font-black">
                                @if($r->sale)
                                    <a href="{{ route('sales.show', $r->sale) }}" class="text-teal-600 hover:text-teal-800 no-underline">{{ $r->sale->reference }}</a>
                                @else — @endif
                            </td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-600 uppercase">{{ $r->sale?->client?->name ?? '—' }}</td>
                            <td class="px-4 py-4 text-center text-[10px] font-black text-slate-500">{{ $r->items_count }}</td>
                            <td class="px-4 py-4 text-right text-sm font-black text-orange-600">{{ number_format($r->total_refund, 0, ',', ' ') }}</td>
                            <td class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase">{{ ['especes'=>'Espèces','orange_money'=>'OM/MoMo','virement'=>'Virement','cheque'=>'Chèque'][$r->refund_method] ?? $r->refund_method }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="p-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun avoir sur cette période.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $returns->links() }}</div>
        </div>
    </div>
</x-app-layout>
