<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    📉 {{ __("Démarque & ajustements") }}
                </h2>
                <p class="text-[10px] font-black text-orange-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ __("Écarts d'inventaire valorisés") }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('stock-adjustments.csv', request()->query()) }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-50 hover:text-emerald-600 transition-all no-underline shadow-sm italic"><i class="fa-solid fa-file-csv"></i> CSV</a>
                <a href="{{ route('stock-adjustments.pdf', request()->query()) }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-rose-50 hover:text-rose-600 transition-all no-underline shadow-sm italic"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                @can('logistique.C')
                <a href="{{ route('stock-adjustments.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-orange-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-orange-600 transition-all no-underline shadow-lg italic"><i class="fa-solid fa-plus"></i> {{ __("Nouvel ajustement") }}</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <x-flash />

            {{-- KPIs --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Démarque (pertes)") }}</p>
                    <p class="text-xl font-black text-rose-600 leading-none">{{ number_format($stats['loss_value'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Gains (écarts +)") }}</p>
                    <p class="text-xl font-black text-emerald-600 leading-none">{{ number_format($stats['gain_value'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Impact net") }}</p>
                    <p class="text-xl font-black {{ $stats['net_value'] < 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($stats['net_value'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Ajustements") }}</p>
                    <p class="text-xl font-black text-slate-800 leading-none">{{ $stats['count'] }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("pièces") }}</p>
                </div>
            </div>

            {{-- Filtres --}}
            <form method="GET" class="bg-white p-4 rounded-[2rem] border border-slate-100 shadow-sm grid grid-cols-2 md:grid-cols-6 gap-3">
                <input type="date" name="from" value="{{ $from }}" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none">
                <input type="date" name="to" value="{{ $to }}" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none">
                <select name="stock_id" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none uppercase">
                    <option value="">{{ __("Tous articles") }}</option>
                    @foreach($stocks as $s)<option value="{{ $s->id }}" @selected(($filters['stock_id'] ?? '') == $s->id)>{{ $s->item_name }}</option>@endforeach
                </select>
                <select name="reason" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none uppercase">
                    <option value="">{{ __("Tous motifs") }}</option>
                    @foreach($reasons as $k => $v)<option value="{{ $k }}" @selected(($filters['reason'] ?? '') === $k)>{{ $v }}</option>@endforeach
                </select>
                <select name="type" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none uppercase">
                    <option value="">{{ __("Perte & gain") }}</option>
                    <option value="perte" @selected(($filters['type'] ?? '') === 'perte')>{{ __("Pertes") }}</option>
                    <option value="gain" @selected(($filters['type'] ?? '') === 'gain')>{{ __("Gains") }}</option>
                </select>
                <button type="submit" class="bg-slate-900 text-white rounded-xl p-3 text-[10px] font-black uppercase tracking-widest hover:bg-orange-600 transition-all border-none cursor-pointer italic">{{ __("Filtrer") }}</button>
            </form>

            {{-- Journal --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-3 text-left">{{ __("Réf.") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Date") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Article") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Motif") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Écart") }}</th>
                            <th class="px-6 py-3 text-right">{{ __("Valeur") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($adjustments as $a)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black text-slate-500">{{ $a->reference }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-400 whitespace-nowrap">{{ $a->adjustment_date->format('d/m/Y') }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-600 uppercase">{{ $a->stock->item_name ?? '—' }}</td>
                            <td class="px-3 py-3 text-[10px] font-bold text-slate-500">{{ $a->reason_label }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black {{ $a->is_loss ? 'text-rose-600' : 'text-emerald-600' }}">{{ $a->delta > 0 ? '+' : '' }}{{ number_format($a->delta, 2, ',', ' ') }}</td>
                            <td class="px-6 py-3 text-right text-[11px] font-black {{ $a->is_loss ? 'text-rose-600' : 'text-emerald-600' }}">{{ $a->is_loss ? '−' : '+' }}{{ number_format($a->value_impact, 0, ',', ' ') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun ajustement sur la période.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $adjustments->links() }}</div>
        </div>
    </div>
</x-app-layout>
